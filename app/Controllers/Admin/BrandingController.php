<?php

/**
 * Saves the site name, logo, and favicon from the admin Settings > General screen.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.21.0
 */

declare(strict_types=1);

namespace LumoraPress\Controllers\Admin;

use LumoraPress\Core\PressConfig;
use LumoraPress\Services\MediaService;

/**
 * The caller (the Site form on Settings > General) has already verified
 * its CSRF token, so this only applies the submitted values.
 */
final class BrandingController
{
    public function __construct(
        private readonly PressConfig $config,
        private readonly MediaService $media,
    ) {
    }

    /**
     * Returns an error message when a logo or favicon upload fails, or
     * null when everything saved. A failed upload leaves the previous
     * image in place.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function save(array $post, array $files, int $currentUserId): ?string
    {
        $this->config->setOption('site_name', trim((string) ($post['site_name'] ?? '')));

        $error = null;

        foreach ([
            'logo' => ['option' => 'site_logo_media_id', 'remove' => 'remove_logo', 'label' => 'Logo'],
            'favicon' => ['option' => 'favicon_media_id', 'remove' => 'remove_favicon', 'label' => 'Favicon'],
        ] as $field => $spec) {
            if (($post[$spec['remove']] ?? '') === '1') {
                $this->config->setOption($spec['option'], '');

                continue;
            }

            if (!isset($files[$field]) || $files[$field]['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            try {
                $uploaded = $this->media->upload($files[$field], $currentUserId);
                $this->config->setOption($spec['option'], (string) $uploaded['id']);
            } catch (\Throwable $exception) {
                $error = $spec['label'] . ' upload failed: ' . $exception->getMessage();
            }
        }

        return $error;
    }
}
