<?php

/**
 * Admin logic for the site-wide featured image defaults on Appearance &rsaquo; Customize: the default image, its use on pages, and the crop size.
 *
 * @package LumoraPress
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.20.0
 */

declare(strict_types=1);

namespace LumoraPress\Controllers\Admin;

use LumoraPress\Core\PressConfig;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\ThumbnailService;

/**
 * These are site options, not per-theme Theme Options, so every theme shares
 * them. Saving needs manage_options, as it did on Media &rsaquo; Thumbnails.
 */
final class FeaturedImageDefaultsController
{
    public const CSRF_ACTION = 'featured_image_defaults';

    public const PICKER_CSRF_ACTION = 'featured_image_picker_query';

    /**
     * The Header tab's picker has its own request name, since Csrf::token() keeps one token per
     * action and two pickers on the same screen would otherwise invalidate each other's.
     */
    public const HEADER_PICKER_CSRF_ACTION = 'header_image_picker_query';

    private const PICKER_PAGE_SIZE = 40;

    public function __construct(
        private readonly PressConfig $config,
        private readonly MediaService $media,
        private readonly ThumbnailService $thumbnails,
    ) {
    }

    /**
     * @param array<string, mixed> $post
     */
    public function save(array $post, bool $canManageOptions, ?string $csrfToken): AdminActionResult
    {
        if (!$canManageOptions || !Csrf::verify(self::CSRF_ACTION, $csrfToken)) {
            return AdminActionResult::error('Your session expired or the request could not be verified. Please try again.');
        }

        $this->config->setOption('default_featured_image_media_id', (string) max(0, (int) ($post['default_featured_image_media_id'] ?? 0)));
        $this->config->setOption('default_featured_image_on_pages', ($post['default_featured_image_on_pages'] ?? '') === '1' ? '1' : '0');

        // Only a currently enabled size can be stored, so a removed size can never be chosen.
        $submittedCropSize = is_string($post['featured_image_crop_size'] ?? null) ? $post['featured_image_crop_size'] : '';
        $this->config->setOption('featured_image_crop_size', in_array($submittedCropSize, $this->enabledCropSizes(), true) ? $submittedCropSize : 'large');

        return AdminActionResult::redirect(admin_url('appearance/customize') . '?tab=body&saved=1');
    }

    /**
     * Names of the thumbnail sizes a cropped featured image can be generated at.
     *
     * @return array<int, string>
     */
    public function enabledCropSizes(): array
    {
        return array_keys(array_filter($this->thumbnails->sizes(), static fn (array $size): bool => $size['enabled']));
    }

    /**
     * One page of images for the default-image picker.
     *
     * @param array<string, mixed> $post
     * @return array{status: int, body: array<string, mixed>}
     */
    public function pickerQuery(array $post, bool $canUploadFiles, ?string $csrfToken, string $action = self::PICKER_CSRF_ACTION): array
    {
        if (!in_array($action, [self::PICKER_CSRF_ACTION, self::HEADER_PICKER_CSRF_ACTION], true)) {
            return ['status' => 403, 'body' => ['error' => 'Not permitted.']];
        }

        if (!$canUploadFiles || !Csrf::verify($action, $csrfToken)) {
            return ['status' => 403, 'body' => ['error' => 'Not permitted.']];
        }

        $term = trim((string) ($post['term'] ?? ''));
        $folderId = (int) ($post['folder_id'] ?? 0);
        $page = max(1, (int) ($post['page'] ?? 1));

        $filters = ['type' => 'image'];

        if ($term !== '') {
            $filters['term'] = $term;
        }

        if ($folderId > 0) {
            $filters['folderIds'] = [$folderId];
        }

        $result = $this->media->query($filters, self::PICKER_PAGE_SIZE, ($page - 1) * self::PICKER_PAGE_SIZE);
        $thumbnailsByMediaId = $this->thumbnails->thumbnailsForMany(
            array_map(static fn (array $item): int => (int) $item['id'], $result['items']),
        );

        return [
            'status' => 200,
            'body' => [
                'items' => array_map(fn (array $item): array => $this->pickerItem($item, $thumbnailsByMediaId[(int) $item['id']] ?? []), $result['items']),
                'total' => $result['total'],
                'csrfToken' => Csrf::token($action),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<int, array<string, mixed>> $thumbnails
     * @return array<string, mixed>
     */
    private function pickerItem(array $item, array $thumbnails): array
    {
        $sizes = [
            'full' => [
                'url' => $this->media->url($item),
                'width' => (int) ($item['width'] ?? 0),
                'height' => (int) ($item['height'] ?? 0),
            ],
        ];

        foreach ($thumbnails as $thumbnail) {
            $sizes[(string) $thumbnail['size_name']] = [
                'url' => $this->thumbnails->url($item, (string) $thumbnail['size_name']),
                'width' => (int) $thumbnail['width'],
                'height' => (int) $thumbnail['height'],
            ];
        }

        return [
            'id' => (int) $item['id'],
            'url' => $this->media->url($item),
            'name' => (string) $item['file_name'],
            'alt' => (string) ($item['alt_text'] ?? ''),
            'folderId' => $item['folder_id'] !== null ? (int) $item['folder_id'] : null,
            'sizes' => $sizes,
        ];
    }
}
