<?php

/**
 * What one content export should include: which content types, and whether media files are bundled.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.19.0
 */

declare(strict_types=1);

namespace LumoraPress\Services\Export;

/**
 * Categories and tags travel with Posts, and media folders with Media,
 * rather than being separate choices — neither means anything without
 * the content that uses it.
 */
final class ExportOptions
{
    public const POSTS = 'posts';
    public const PAGES = 'pages';
    public const USERS = 'users';
    public const MEDIA = 'media';
    public const COMMENTS = 'comments';
    public const MENUS = 'menus';
    public const WIDGETS = 'widgets';

    public const ALL_CONTENT_TYPES = [
        self::POSTS,
        self::PAGES,
        self::USERS,
        self::MEDIA,
        self::COMMENTS,
        self::MENUS,
        self::WIDGETS,
    ];

    /** @var array<int, string> */
    public readonly array $contentTypes;

    /**
     * @param array<int, string> $contentTypes Unknown values are dropped.
     */
    public function __construct(
        array $contentTypes = self::ALL_CONTENT_TYPES,
        public readonly bool $includeUploads = true,
    ) {
        $this->contentTypes = array_values(array_intersect(self::ALL_CONTENT_TYPES, $contentTypes));
    }

    public function includes(string $contentType): bool
    {
        return in_array($contentType, $this->contentTypes, true);
    }

    /**
     * The same options narrowed to what $writer can represent, so a
     * no-JS form submission can't ask a format for something it has no
     * slot for.
     */
    public function restrictedTo(ExportFormatWriter $writer): self
    {
        return new self(
            array_values(array_intersect($this->contentTypes, $writer->supportedContentTypes())),
            $this->includeUploads && $writer->bundlesUploads(),
        );
    }
}
