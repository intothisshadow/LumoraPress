<?php

/**
 * Bluesky Auto-Embed's oEmbed resolution cache.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Database\Database;
use Throwable;

/**
 * Bluesky Auto-Embed's oEmbed resolution cache. Bluesky's embed widget requires an AT-URI
 * and content CID, neither derivable by regex from a plain post URL the way other Auto-Embed
 * providers' ids are — the one deliberate exception to `EmbedService`'s no-outbound-request rule.
 *
 * Kept narrow: `resolveContent()`, the only network call, runs once per distinct Bluesky URL
 * from the `post_saved`/`page_saved` hooks, never from a page render. `cached()`, called at
 * render time, only reads the local cache — a not-yet-resolved URL just renders as a plain
 * link until a future save resolves it. HTTP pattern mirrors AkismetClient/
 * GitHubReleaseProvider and fails open, so a down Bluesky can never block a save.
 */
final class BlueskyResolverService
{
    private const OEMBED_URL = 'https://embed.bsky.app/oembed';

    private const USER_AGENT = 'LumoraPress/1.0';

    private const TIMEOUT_SECONDS = 10;

    /**
     * Matches a bsky.app profile/post URL anywhere in raw post/page
     * content — deliberately more permissive than EmbedService::render()'s
     * "must be alone on its own line/paragraph" matching, since
     * over-resolving (caching a URL that turns out not to be alone on its
     * own line, so never actually gets embedded) is harmless, while
     * under-resolving would mean re-deriving the exact same paragraph/line
     * detection logic here just to decide whether to warm the cache.
     */
    private const URL_PATTERN = '#https?://(?:www\.)?bsky\.app/profile/([\w.\-:%]+)/post/([A-Za-z0-9]+)#i';

    /**
     * @param (\Closure(string $url, int $timeoutSeconds): (string|null))|null $httpGet
     *     Injectable for tests; production code never passes this.
     */
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly ?\Closure $httpGet = null,
    ) {
    }

    /**
     * Pure cache lookup — never makes a network request.
     *
     * @return array{atUri: string, cid: string}|null
     */
    public function cached(string $handle, string $rkey): ?array
    {
        $row = $this->database->fetchOne(
            'SELECT at_uri, cid FROM ' . $this->table() . '
             WHERE handle = :handle AND rkey = :rkey AND at_uri IS NOT NULL AND cid IS NOT NULL',
            ['handle' => $handle, 'rkey' => $rkey],
        );

        if ($row === null) {
            return null;
        }

        return ['atUri' => (string) $row['at_uri'], 'cid' => (string) $row['cid']];
    }

    /**
     * Scans $content for bsky.app post URLs and resolves+caches any not
     * already cached. Called once, synchronously, from post_saved/
     * page_saved — see this class's own docblock. A resolution failure
     * for one URL never stops the others in the same content from being
     * attempted, and never propagates out of this method — a broken or
     * slow Bluesky must never turn into a failed post/page save.
     */
    public function resolveContent(string $content): void
    {
        if (!str_contains($content, 'bsky.app')) {
            return;
        }

        if (preg_match_all(self::URL_PATTERN, $content, $matches, PREG_SET_ORDER) === false) {
            return;
        }

        $seen = [];

        foreach ($matches as $match) {
            $handle = $match[1];
            $rkey = $match[2];
            $key = $handle . '/' . $rkey;

            if (isset($seen[$key]) || $this->cached($handle, $rkey) !== null) {
                continue;
            }

            $seen[$key] = true;

            try {
                $this->resolveAndCache($handle, $rkey);
            } catch (Throwable) {
                // Fail open — see class docblock. A malformed response or
                // a database hiccup for one URL must not stop the rest of
                // this save, and must never surface as a save failure.
            }
        }
    }

    private function resolveAndCache(string $handle, string $rkey): void
    {
        $profileUrl = "https://bsky.app/profile/{$handle}/post/{$rkey}";
        $requestUrl = self::OEMBED_URL . '?' . http_build_query(['url' => $profileUrl, 'format' => 'json']);

        $body = ($this->httpGet ?? $this->defaultHttpGet(...))($requestUrl, self::TIMEOUT_SECONDS);

        if ($body === null) {
            return;
        }

        $decoded = json_decode($body, true);
        $html = is_array($decoded) && isset($decoded['html']) && is_string($decoded['html']) ? $decoded['html'] : null;

        if ($html === null) {
            return;
        }

        // Only the two specific attributes are extracted and stored — the
        // rest of Bluesky's returned `html` blob (a full blockquote+script
        // snippet) is deliberately discarded rather than cached/echoed
        // verbatim later. EmbedService::wrap() builds its own fixed
        // template from just these two values, the same "we control the
        // markup, only the data is external" shape every other provider
        // already uses (see EmbedService's class docblock).
        if (preg_match('#data-bluesky-uri="(at://[^"]+)"#', $html, $uriMatch) !== 1) {
            return;
        }

        if (preg_match('#data-bluesky-cid="([^"]+)"#', $html, $cidMatch) !== 1) {
            return;
        }

        $this->database->execute(
            'INSERT INTO ' . $this->table() . ' (handle, rkey, at_uri, cid, resolved_at, created_at)
             VALUES (:handle, :rkey, :at_uri, :cid, :now, :now)',
            [
                'handle' => $handle,
                'rkey' => $rkey,
                'at_uri' => $uriMatch[1],
                'cid' => $cidMatch[1],
                'now' => date('Y-m-d H:i:s'),
            ],
        );
    }

    private function defaultHttpGet(string $url, int $timeoutSeconds): ?string
    {
        $headers = ['User-Agent: ' . self::USER_AGENT];

        if (function_exists('curl_init')) {
            $handle = curl_init($url);

            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $body = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);

            return (is_string($body) && $status >= 200 && $status < 300) ? $body : null;
        }

        if (!ini_get('allow_url_fopen')) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => $timeoutSeconds,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        return $body !== false ? $body : null;
    }

    private function table(): string
    {
        return $this->tablePrefix . 'bluesky_embeds';
    }
}
