<?php

/**
 * Exports and imports the portable subset of this installation's Settings-screen options, for copying configuration between two unrelated Lumora Press installs (LP-140).
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.9.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\PressConfig;
use RuntimeException;

/**
 * "Portable" here means safe to apply on a second, completely unrelated
 * install (different database, different content, different users) — not
 * "every option this site has". Two categories of option are deliberately
 * left out of PORTABLE_FIELDS below, and must stay out if this list is
 * ever extended:
 *
 *  - Identity/per-install fields: site_url, site_tagline, admin_email.
 *    These describe *this* install, not a configuration choice worth
 *    copying elsewhere.
 *  - Local references: avatar_default_media_id, default_og_image_media_id,
 *    homepage_page_id, homepage_posts_page_id. Each stores a database row
 *    id from *this* install's own media/pages tables — importing it
 *    verbatim into an unrelated install would point at a row that either
 *    doesn't exist there or (worse) exists but means something else. The
 *    enum-ish sibling of a couple of these (e.g. homepage_display, which
 *    only says "static page" vs "latest posts" without naming *which*
 *    page) is still portable and stays in the list.
 *  - Anything under Security, Privacy, Redirects, Maintenance Mode,
 *    Cache, and Embeds — install-specific by nature (redirects map old
 *    URLs *on this site*; a CDN hostname belongs to this host) or judged
 *    not worth the review during LP-140 to confirm otherwise. Not in
 *    PORTABLE_FIELDS, so never touched by export or import.
 *
 * Thumbnail sizes (`thumbnail_size_{name}_*`) and REST API resource
 * toggles (`rest_api_resource_{resource}_enabled`) are per-name/per-
 * resource option keys rather than one fixed key each — THUMBNAIL_SIZE_NAMES
 * and REST_API_RESOURCES list the core-registered names this ships with
 * (ThumbnailService::DEFAULT_SIZES and general.php's own hardcoded
 * resource list respectively) and allFields() expands them. A
 * plugin/theme-registered thumbnail size added via the `thumbnail_sizes`
 * filter is deliberately NOT included — such a size may not be
 * config-option-backed at all (a plugin can hardcode its own dimensions),
 * so exporting "whatever ThumbnailService::sizes() currently reports"
 * could silently create a phantom option override the plugin never
 * expected to see read back.
 *
 * Every value round-trips through the exact same PressConfig::setOption()
 * every Settings screen's own POST handler already calls — sanitize()
 * only coerces to the right scalar type (bool -> '1'/'0', int -> a plain
 * integer string, string -> trimmed), it does not re-implement each
 * field's own bespoke validation (e.g. avatar_max_rating's G/PG/R/X
 * allowlist, or comment_per_page's 1-500 clamp). This is a deliberate
 * scope decision, not an oversight: only an Administrator can reach this
 * feature (Maintenance > Tools, capability manage_options — see
 * admin/index.php's $menu), and an Administrator already has direct,
 * unrestricted write access to every one of these same option keys via
 * the Settings screens themselves. A field that ends up with an
 * out-of-range value after import is exactly as recoverable as one set
 * that way by hand: revisit the same Settings screen and save it, which
 * re-validates and overwrites.
 *
 * Upload flow mirrors PluginInstaller's stage()/inspectStaged()/
 * finalize()/discardStaged() shape exactly: stage() moves an uploaded
 * file out of PHP's request-scoped tmp location into a holding directory
 * so a confirmation screen doesn't need the file re-uploaded,
 * inspectStaged() reads it back for a before/after preview without
 * applying anything, and finalize() applies it and always discards the
 * staged file afterward (a staged import is only ever meant to be
 * finalized once). A stale stage is never a security problem the way a
 * stale plugin-install stage isn't either — it can only be replayed
 * through the same allowlisted, type-coerced write path a direct import
 * would already reach.
 */
final class SettingsPortabilityService
{
    private const FORMAT_VERSION = 1;

    /**
     * @var list<string>
     */
    private const THUMBNAIL_SIZE_NAMES = ['small', 'medium', 'large'];

    /**
     * @var list<string>
     */
    private const REST_API_RESOURCES = ['posts', 'pages', 'categories', 'tags', 'comments', 'search'];

    /**
     * @var array<string, string> option key => type ('bool'|'int'|'string')
     */
    private const PORTABLE_FIELDS = [
        // Permalinks
        'permalink_structure' => 'string',
        'category_base' => 'string',
        'tag_base' => 'string',
        // Reading
        'posts_per_page' => 'int',
        'discourage_search_engines' => 'bool',
        'homepage_display' => 'string',
        // Discussion
        'comment_author_name_required' => 'bool',
        'comment_author_email_required' => 'bool',
        'comment_require_registration' => 'bool',
        'comment_close_after_days' => 'int',
        'comment_cookies_consent_enabled' => 'bool',
        'comment_threading_enabled' => 'bool',
        'comment_max_nesting_level' => 'int',
        'comment_pagination_enabled' => 'bool',
        'comment_per_page' => 'int',
        'comment_default_page' => 'string',
        'comment_order' => 'string',
        'comment_default_status_for_new_posts' => 'string',
        'comment_notify_admin_new' => 'bool',
        'comment_notify_admin_moderation' => 'bool',
        'comment_notify_author' => 'bool',
        'comment_notify_recipients' => 'string',
        'comment_moderation_manual_all' => 'bool',
        'comment_moderation_auto_approve_previous' => 'bool',
        'comment_moderation_link_limit' => 'int',
        'comment_moderation_keywords' => 'string',
        'comment_disallowed_keywords' => 'string',
        'avatars_enabled' => 'bool',
        'avatar_max_rating' => 'string',
        'avatar_default' => 'string',
        // Media
        'media_track_downloads' => 'bool',
        'lightbox_show_filenames' => 'bool',
        'lightbox_large_size' => 'string',
        // Thumbnails (fixed keys; per-size keys are added by allFields())
        'thumbnail_max_pixels' => 'int',
        'thumbnail_sharpen' => 'bool',
        'thumbnail_jpeg_quality' => 'int',
        'thumbnail_webp_quality' => 'int',
        'featured_image_crop_size' => 'string',
        // General — portable subset only, see class docblock
        'timezone' => 'string',
        'date_format_preset' => 'string',
        'date_format_custom' => 'string',
        'time_format_preset' => 'string',
        'time_format_custom' => 'string',
        'meta_description' => 'string',
        'feeds_enabled' => 'bool',
        'feed_full_content' => 'bool',
        'feed_featured_images' => 'bool',
        'feed_item_limit' => 'int',
        'feed_cache_lifetime' => 'int',
        'feed_description' => 'string',
        'search_min_length' => 'int',
        'search_max_results' => 'int',
        'revision_retention' => 'int',
        'rest_api_enabled' => 'bool',
        'rest_api_comments_public_submission_enabled' => 'bool',
        'default_editor' => 'string',
        'lock_editor_to_default' => 'bool',
    ];

    public function __construct(
        private readonly PressConfig $config,
        private readonly string $stagingPath,
        private readonly string $appVersion,
    ) {
    }

    public function export(): string
    {
        $values = [];

        foreach ($this->allFields() as $key => $type) {
            $values[$key] = $this->config->option($key);
        }

        $payload = [
            'lumoraPressSettingsExport' => true,
            'formatVersion' => self::FORMAT_VERSION,
            'exportedAt' => date('c'),
            'exportedFromVersion' => $this->appVersion,
            'values' => $values,
        ];

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Moves an uploaded export file out of PHP's request-scoped tmp
     * location into a holding directory that survives to the next
     * request, so the confirmation step doesn't require re-uploading.
     * Returns an opaque token identifying the staged file.
     */
    public function stage(string $uploadedTmpPath): string
    {
        if (!is_dir($this->stagingPath) && !mkdir($this->stagingPath, 0755, true) && !is_dir($this->stagingPath)) {
            throw new RuntimeException('Unable to create the settings-import staging directory.');
        }

        $token = bin2hex(random_bytes(16));
        $stagedFile = rtrim($this->stagingPath, '/') . '/' . $token . '.json';

        if (!move_uploaded_file($uploadedTmpPath, $stagedFile) && !rename($uploadedTmpPath, $stagedFile)) {
            throw new RuntimeException('Unable to store the uploaded file for review.');
        }

        return $token;
    }

    /**
     * Reads a staged export back out for the "here's what will change"
     * confirmation screen, without writing anything.
     *
     * @return array{changes: array<string, array{from: ?string, to: string}>, unknownKeys: list<string>, exportedAt: ?string, exportedFromVersion: ?string}
     */
    public function inspectStaged(string $token): array
    {
        $decoded = $this->readStaged($token);
        $fields = $this->allFields();
        $changes = [];
        $unknownKeys = [];

        foreach ($decoded['values'] as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (!isset($fields[$key])) {
                $unknownKeys[] = $key;

                continue;
            }

            // A null value means the exporting install never explicitly
            // set this option (PressConfig::option() returns null for an
            // unset key) — importing that as a coerced default (e.g. a
            // bool forced to '0') would silently overwrite a destination
            // value the export never actually made a choice about. Only
            // a key the source install genuinely set travels.
            if ($value === null) {
                continue;
            }

            $sanitized = $this->sanitize($value, $fields[$key]);
            $current = $this->config->option($key);
            $currentString = $current === null ? null : (string) $current;

            if ($sanitized !== $currentString) {
                $changes[$key] = ['from' => $currentString, 'to' => $sanitized];
            }
        }

        return [
            'changes' => $changes,
            'unknownKeys' => $unknownKeys,
            'exportedAt' => is_string($decoded['exportedAt'] ?? null) ? $decoded['exportedAt'] : null,
            'exportedFromVersion' => is_string($decoded['exportedFromVersion'] ?? null) ? $decoded['exportedFromVersion'] : null,
        ];
    }

    /**
     * Applies a previously staged import, then deletes the staged file
     * regardless of outcome — a staged import is only ever meant to be
     * finalized once. Unknown keys (see inspectStaged()) are silently
     * skipped, not applied — the allowlist in PORTABLE_FIELDS/allFields()
     * is the only thing this ever writes through.
     *
     * @return int number of options actually changed
     */
    public function finalize(string $token): int
    {
        $decoded = $this->readStaged($token);
        $fields = $this->allFields();
        $applied = 0;

        try {
            foreach ($decoded['values'] as $key => $value) {
                if (!is_string($key) || !isset($fields[$key]) || $value === null) {
                    continue;
                }

                $sanitized = $this->sanitize($value, $fields[$key]);
                $current = $this->config->option($key);
                $currentString = $current === null ? null : (string) $current;

                if ($sanitized !== $currentString) {
                    $this->config->setOption($key, $sanitized);
                    $applied++;
                }
            }
        } finally {
            $this->discardStaged($token);
        }

        return $applied;
    }

    public function discardStaged(string $token): void
    {
        $path = $this->stagedPath($token);

        if ($path !== null) {
            unlink($path);
        }
    }

    /**
     * @return array<string, string> the full portable field map, with
     *     thumbnail per-size and REST API per-resource keys expanded in
     *     alongside the fixed PORTABLE_FIELDS entries.
     */
    private function allFields(): array
    {
        $fields = self::PORTABLE_FIELDS;

        foreach (self::THUMBNAIL_SIZE_NAMES as $name) {
            $fields["thumbnail_size_{$name}_width"] = 'int';
            $fields["thumbnail_size_{$name}_height"] = 'int';
            $fields["thumbnail_size_{$name}_mode"] = 'string';
            $fields["thumbnail_size_{$name}_enabled"] = 'bool';
        }

        foreach (self::REST_API_RESOURCES as $resource) {
            $fields["rest_api_resource_{$resource}_enabled"] = 'bool';
        }

        return $fields;
    }

    /**
     * @return array{values: array<string, mixed>, exportedAt?: mixed, exportedFromVersion?: mixed}
     */
    private function readStaged(string $token): array
    {
        $path = $this->stagedPath($token);

        if ($path === null) {
            throw new RuntimeException('That upload could not be found. Please upload the file again.');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        $decoded = json_decode($contents, true);

        if (
            !is_array($decoded)
            || ($decoded['lumoraPressSettingsExport'] ?? false) !== true
            || !isset($decoded['values'])
            || !is_array($decoded['values'])
        ) {
            throw new RuntimeException('That file is not a valid Lumora Press settings export.');
        }

        /** @var array{values: array<string, mixed>, exportedAt?: mixed, exportedFromVersion?: mixed} $decoded */
        return $decoded;
    }

    private function stagedPath(string $token): ?string
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }

        $path = rtrim($this->stagingPath, '/') . '/' . $token . '.json';

        return is_file($path) ? $path : null;
    }

    private function sanitize(mixed $value, string $type): string
    {
        return match ($type) {
            'bool' => $value === '1' || $value === 1 || $value === true ? '1' : '0',
            'int' => (string) (int) (is_scalar($value) ? $value : 0),
            default => is_scalar($value) ? trim((string) $value) : '',
        };
    }
}
