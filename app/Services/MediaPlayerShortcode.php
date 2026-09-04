<?php

/**
 * Renders the `[lumora_audio]`/`[lumora_video]` shortcodes as a native, Plyr-enhanced player.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.10.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Theme\ScriptEmbeds;

/**
 * Lets an author embed a playable audio or video file in post/page
 * content — something Lumora Press could accept as an upload
 * (`MediaService::TYPE_CATEGORY_MIME_TYPES`'s `audio`/`video` entries)
 * but had no way to actually play until this shortcode existed; only a
 * bare download link was possible (LP-006's "Total downloads" counter).
 *
 * Registered on the `content_html` filter (see include/bootstrap.php),
 * the same hook `FolderGalleryShortcode`/`[lumora_downloads]` use, and
 * modeled directly on `FolderGalleryShortcode`'s pattern: a real core
 * service constructed with injected dependencies, resolving the media
 * row at render time so a later edit (e.g. swapping a video's poster
 * image) is reflected automatically without touching stored content.
 *
 * A video's optional `poster_media_id`/`caption_track_media_id`
 * (migration 0032) already existed for the admin edit-screen preview —
 * this is the first place either is ever rendered on the public site.
 *
 * Marks `ScriptEmbeds::markUsed('plyr')` so `FooterAssets` only loads
 * the Plyr skin (jsdelivr, already CSP-allowlisted for PhotoSwipe) on
 * pages that actually contain a player — the same conditional-asset
 * shape `MediaViewer`/`ScriptEmbeds` already use for PhotoSwipe and the
 * Twitter/Bluesky Auto-Embed scripts. Plyr enhances the native
 * `<audio>`/`<video>` elements in place; with JavaScript disabled or
 * before Plyr loads, the plain native controls still work.
 */
final class MediaPlayerShortcode
{
    private const AUDIO_PATTERN = '/\[lumora_audio([^\]]*)\]/i';
    private const VIDEO_PATTERN = '/\[lumora_video([^\]]*)\]/i';

    public function __construct(
        private readonly MediaService $media,
    ) {
    }

    public function render(string $html): string
    {
        if (str_contains($html, '[lumora_audio')) {
            $html = preg_replace_callback(
                self::AUDIO_PATTERN,
                fn (array $matches): string => $this->renderAudio($this->parseAttributes($matches[1])),
                $html,
            ) ?? $html;
        }

        if (str_contains($html, '[lumora_video')) {
            $html = preg_replace_callback(
                self::VIDEO_PATTERN,
                fn (array $matches): string => $this->renderVideo($this->parseAttributes($matches[1])),
                $html,
            ) ?? $html;
        }

        return $html;
    }

    /**
     * @param array<string, string> $attributes
     */
    private function renderAudio(array $attributes): string
    {
        $media = $this->findMedia($attributes, 'audio/');

        if ($media === null) {
            return '';
        }

        ScriptEmbeds::markUsed('plyr');

        $caption = (string) ($media['caption'] ?? '');

        $html = '<figure class="lp-audio-player">'
            . '<audio class="lp-audio-player__audio" controls preload="metadata" src="' . esc_url($this->media->url($media)) . '"></audio>';

        if ($caption !== '') {
            $html .= '<figcaption class="lp-audio-player__caption">' . esc_html($caption) . '</figcaption>';
        }

        return $html . '</figure>';
    }

    /**
     * @param array<string, string> $attributes
     */
    private function renderVideo(array $attributes): string
    {
        $media = $this->findMedia($attributes, 'video/');

        if ($media === null) {
            return '';
        }

        ScriptEmbeds::markUsed('plyr');

        $width = (int) ($media['width'] ?? 0);
        $height = (int) ($media['height'] ?? 0);
        $caption = (string) ($media['caption'] ?? '');

        $posterMediaId = $media['poster_media_id'] ?? null;
        $poster = $posterMediaId !== null ? $this->media->find((int) $posterMediaId) : null;

        $captionTrackMediaId = $media['caption_track_media_id'] ?? null;
        $captionTrack = $captionTrackMediaId !== null ? $this->media->find((int) $captionTrackMediaId) : null;

        $html = '<figure class="lp-video-player">'
            . '<video class="lp-video-player__video" controls preload="metadata"'
            . ($width > 0 ? ' width="' . $width . '"' : '')
            . ($height > 0 ? ' height="' . $height . '"' : '')
            . ($poster !== null ? ' poster="' . esc_url($this->media->url($poster)) . '"' : '')
            . ' src="' . esc_url($this->media->url($media)) . '">';

        if ($captionTrack !== null) {
            $html .= '<track kind="subtitles" src="' . esc_url($this->media->url($captionTrack)) . '" default>';
        }

        $html .= '</video>';

        if ($caption !== '') {
            $html .= '<figcaption class="lp-video-player__caption">' . esc_html($caption) . '</figcaption>';
        }

        return $html . '</figure>';
    }

    /**
     * @param array<string, string> $attributes
     * @return array<string, mixed>|null
     */
    private function findMedia(array $attributes, string $mimePrefix): ?array
    {
        $id = (int) ($attributes['id'] ?? 0);

        if ($id <= 0) {
            return null;
        }

        $media = $this->media->find($id);

        if ($media === null || !str_starts_with((string) $media['mime_type'], $mimePrefix)) {
            return null;
        }

        return $media;
    }

    /**
     * @param string $rawAttributes e.g. ` id="12"`
     * @return array<string, string>
     */
    private function parseAttributes(string $rawAttributes): array
    {
        $attributes = [];

        if (preg_match_all('/([a-zA-Z_]+)="([^"]*)"/', $rawAttributes, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attributes[$match[1]] = $match[2];
            }
        }

        return $attributes;
    }
}
