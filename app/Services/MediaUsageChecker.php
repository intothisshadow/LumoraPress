<?php

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Core\PressConfig;

/**
 * Describes where a media file is used, so the admin Media Manager (LP-005)
 * can warn before deleting a referenced file. Checks every known reference
 * as of this session — a post's or page's featured image (LP-040), the
 * site logo/favicon/default Open Graph image options (LP-034/LP-042), and
 * passes the result through the media_usage filter so plugins can extend
 * it without editing this class, the same extensibility pattern
 * FeedService/SearchService/MaintenanceGate already established. Does not
 * scan post/page body content for inline embeds (an author-inserted
 * `<img>`/link baked into stored HTML/Markdown) — only these structured
 * references.
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
     * Every media id referenced anywhere this class knows how to check in
     * bulk (LP-006's "Unused Media" admin view) — built from a handful of
     * bounded queries (PostService::featuredImageIdsInUse()/
     * PageService::featuredImageIdsInUse()) plus the three media-id
     * options, not a describeUsage() call per media item, so it scales
     * with the number of posts/pages rather than the number of media
     * items. Unlike describeUsage(), this does not run the media_usage
     * filter hook — a plugin-added usage source can't be enumerated in
     * bulk without that plugin's own cooperation — so it's a best-effort
     * listing aid, not the authoritative check; describeUsage() (used by
     * the actual delete flow) still is.
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
