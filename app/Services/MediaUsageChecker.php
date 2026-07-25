<?php

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Core\PressConfig;

/**
 * Describes where a media file is used, so the admin Media Manager (LP-005)
 * can warn before deleting a referenced file. Checks every known reference
 * as of this session — a post's or page's featured image (LP-040), and the
 * site logo/favicon options (LP-034) — and passes the result through the
 * media_usage filter so plugins can extend it without editing this class,
 * the same extensibility pattern FeedService/SearchService/MaintenanceGate
 * already established.
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

        /** @var array<int, string> $usages */
        $usages = $this->hooks->applyFilters('media_usage', $usages, $mediaId);

        return $usages;
    }
}
