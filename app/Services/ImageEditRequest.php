<?php

/**
 * Validates a submitted image edit (rotate, flip, crop) for ThumbnailService::editImage().
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.21.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

/**
 * Shared by the Media Manager's own form and the editors' JSON action, so the
 * two accept exactly the same input and cannot drift apart.
 */
final class ImageEditRequest
{
    /**
     * @param array<string, mixed> $post Submitted fields: rotate, flip_h, flip_v, crop_x/y/width/height, output_size, save_mode.
     * @param array<string, mixed> $media The image being edited (its stored width/height are used to spot an edit that changes nothing).
     * @param int $featuredMaxWidth Width used when the author chooses to shrink to the featured image size.
     * @return array{error: string}|array{error: null, replace: bool, edit: array{rotate: int, flipH: bool, flipV: bool, crop: array{x: int, y: int, width: int, height: int}|null, maxWidth: int|null}}
     */
    public static function parse(array $post, array $media, int $featuredMaxWidth): array
    {
        $rotate = (int) ($post['rotate'] ?? 0);

        if (!in_array($rotate, [0, 90, 180, 270], true)) {
            return ['error' => 'Please choose a valid rotation.'];
        }

        $flipH = ($post['flip_h'] ?? '') === '1';
        $flipV = ($post['flip_v'] ?? '') === '1';
        $resize = ($post['output_size'] ?? '') === 'featured';
        $values = [$post['crop_x'] ?? '', $post['crop_y'] ?? '', $post['crop_width'] ?? '', $post['crop_height'] ?? ''];
        $hasCrop = count(array_filter($values, 'is_numeric')) === 4 && (int) $values[2] > 0 && (int) $values[3] > 0;

        $crop = $hasCrop
            ? ['x' => max(0, (int) $values[0]), 'y' => max(0, (int) $values[1]), 'width' => (int) $values[2], 'height' => (int) $values[3]]
            : null;

        $swapsAxes = $rotate === 90 || $rotate === 270;
        $transformedWidth = (int) ($swapsAxes ? $media['height'] : $media['width']);
        $transformedHeight = (int) ($swapsAxes ? $media['width'] : $media['height']);
        $cropsNothing = $crop === null
            || ($crop['x'] === 0 && $crop['y'] === 0 && $crop['width'] >= $transformedWidth && $crop['height'] >= $transformedHeight);

        if ($rotate === 0 && !$flipH && !$flipV && $cropsNothing && !$resize) {
            return ['error' => 'Rotate, flip, or select a crop area first.'];
        }

        return [
            'error' => null,
            'replace' => ($post['save_mode'] ?? '') === 'replace',
            'edit' => [
                'rotate' => $rotate,
                'flipH' => $flipH,
                'flipV' => $flipV,
                'crop' => $crop,
                'maxWidth' => $resize ? $featuredMaxWidth : null,
            ],
        ];
    }
}
