<?php

/**
 * Renders a link's 100×50 button image as markup, shared by the shortcode and the Links widget.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.2.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\LinkDirectory;

use LumoraPress\Services\MediaService;

/**
 * The image is always emitted at 100×50 through width/height attributes,
 * never resized on disk, so an uploaded badge of another size is simply
 * scaled by the browser rather than altered.
 */
final class LinkButtonRenderer
{
    public const WIDTH = 100;

    public const HEIGHT = 50;

    public function __construct(private readonly MediaService $media)
    {
    }

    /**
     * The <img> for a link's button, or null when it has none (or the
     * Media row was deleted since it was chosen).
     */
    public function image(Link $link): ?string
    {
        if ($link->buttonMediaId === null) {
            return null;
        }

        $item = $this->media->find($link->buttonMediaId);

        if ($item === null) {
            return null;
        }

        return '<img class="lp-link-button__img" src="' . esc_url($this->media->url($item)) . '" alt="' . esc_attr($link->title)
            . '" width="' . self::WIDTH . '" height="' . self::HEIGHT . '" loading="lazy">';
    }

    /**
     * A link's button as a clickable badge, or its plain text title (same
     * classes plus `--text`) when it has no button, so a mixed row stays tidy.
     */
    public function badge(Link $link): string
    {
        $image = $this->image($link);

        return '<a class="lp-link-button' . ($image === null ? ' lp-link-button--text' : '') . '" href="' . esc_url($link->url)
            . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr($link->title) . '">'
            . ($image ?? esc_html($link->title)) . '</a>';
    }
}
