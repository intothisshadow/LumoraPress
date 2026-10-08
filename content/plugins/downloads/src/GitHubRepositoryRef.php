<?php

/**
 * A validated GitHub owner/repository pair, and the only place GitHub addresses are built.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.22.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\Downloads;

/**
 * User input is reduced to an owner and repo name that match GitHub's own naming rules, and every
 * address the importer fetches is built from those two parts on a fixed host. Nothing the user
 * typed is ever used as a URL, which keeps the importer from reaching arbitrary servers.
 */
final class GitHubRepositoryRef
{
    private const OWNER_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/';

    private const REPO_PATTERN = '/^[A-Za-z0-9._-]{1,100}$/';

    private function __construct(
        public readonly string $owner,
        public readonly string $repo,
    ) {
    }

    /**
     * Accepts "owner/repo" or an https://github.com/owner/repo address, with an optional
     * ".git" suffix, trailing slash, or extra path such as "/tree/main".
     */
    public static function parse(string $input): ?self
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        if (preg_match('#^(?:https://)?(?:www\.)?github\.com/(.+)$#i', $input, $matches) === 1) {
            $input = $matches[1];
        } elseif (str_contains($input, ':') || str_contains($input, '//')) {
            return null;
        }

        $parts = explode('/', trim($input, '/'));

        if (count($parts) < 2) {
            return null;
        }

        $owner = $parts[0];
        $repo = preg_replace('/\.git$/i', '', $parts[1]) ?? '';

        return self::fromParts($owner, $repo);
    }

    public static function fromParts(string $owner, string $repo): ?self
    {
        if (preg_match(self::OWNER_PATTERN, $owner) !== 1 || preg_match(self::REPO_PATTERN, $repo) !== 1) {
            return null;
        }

        // "." and ".." would pass the pattern but are path segments, not repository names.
        if ($repo === '.' || $repo === '..') {
            return null;
        }

        return new self($owner, $repo);
    }

    public static function isValidOwner(string $owner): bool
    {
        return preg_match(self::OWNER_PATTERN, $owner) === 1;
    }

    public function fullName(): string
    {
        return $this->owner . '/' . $this->repo;
    }

    public function htmlUrl(): string
    {
        return 'https://github.com/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo);
    }

    public function archiveUrl(string $branch): string
    {
        return $this->htmlUrl() . '/archive/refs/heads/' . self::encodePath($branch) . '.zip';
    }

    public function blobUrl(string $branch, string $path): string
    {
        return $this->htmlUrl() . '/blob/' . self::encodePath($branch) . '/' . self::encodePath($path);
    }

    public function rawUrl(string $branch, string $path): string
    {
        return 'https://raw.githubusercontent.com/' . rawurlencode($this->owner) . '/' . rawurlencode($this->repo)
            . '/' . self::encodePath($branch) . '/' . self::encodePath($path);
    }

    /** Branch names and file paths contain "/" that must survive, with every segment encoded. */
    private static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
