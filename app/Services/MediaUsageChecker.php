<?php

/**
 * Describes where a media file is referenced, so the admin Media Manager can warn before deleting a file still in use.
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

use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Core\PressConfig;

/**
 * Describes where a media file is used, so the admin Media Manager can warn before deleting
 * a referenced file. Checks featured images and the site logo/favicon/OG-image options, then
 * passes the result through the media_usage filter so plugins can extend it. Does not scan
 * post/page body content for inline embeds — only these structured references.
 */
final class MediaUsageChecker
{
    public function __construct(
        private readonly PostService $posts,
        private readonly PageService $pages,
        private readonly PressConfig $config,
        private readonly HookManager $hooks,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function describeUsage(int $mediaId): array
    {
        $usages = [];

        foreach ($this->posts->titlesByFeaturedImage($mediaId) as $title) {
            $usages[] = "Post \"{$title}\" (featured image)";
        }

        foreach ($this->pages->titlesByFeaturedImage($mediaId) as $title) {
            $usages[] = "Page \"{$title}\" (featured image)";
        }

        if ((int) $this->config->option('site_logo_media_id', '') === $mediaId) {
            $usages[] = 'Site logo';
        }

        if ((int) $this->config->option('favicon_media_id', '') === $mediaId) {
            $usages[] = 'Site favicon';
        }

        if ((int) $this->config->option('default_og_image_media_id', '') === $mediaId) {
            $usages[] = 'Default Open Graph/Twitter Card image';
        }

        /** @var array<int, string> $usages */
        $usages = $this->hooks->applyFilters('media_usage', $usages, $mediaId);

        return $usages;
    }

    /**
     * Every media id referenced anywhere this class can check in bulk (the "Unused Media"
     * admin view) — built from bounded queries, not a describeUsage() call per item, so it
     * scales with post/page count. Unlike describeUsage(), this skips the media_usage filter,
     * so it's a best-effort listing aid, not the authoritative check.
     *
     * @return array<int, int>
     */
    public function usedMediaIds(): array
    {
        $ids = [...$this->posts->featuredImageIdsInUse(), ...$this->pages->featuredImageIdsInUse()];

        foreach (['site_logo_media_id', 'favicon_media_id', 'default_og_image_media_id'] as $optionKey) {
            $optionId = (int) $this->config->option($optionKey, '');

            if ($optionId > 0) {
                $ids[] = $optionId;
            }
        }

        return array_values(array_unique($ids));
    }

    public function isUnused(int $mediaId): bool
    {
        return !in_array($mediaId, $this->usedMediaIds(), true);
    }
}
