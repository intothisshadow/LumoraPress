<?php

/**
 * Static bridge exposing site identity (name, tagline, logo, favicon, SEO defaults) to theme template helpers.
 *
 * @package LumoraPress
 * @subpackage Themes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

/**
 * Static bridge exposing site identity (name, tagline, logo, favicon,
 * custom CSS — LP-034; meta description, footer copyright text, default
 * Open Graph image, date/time display format — LP-042; search engine
 * visibility — LP-046) to the procedural site_name()/site_tagline()/
 * site_logo_url()/favicon_url()/custom_css()/meta_description()/
 * footer_copyright_text()/default_og_image_url()/the_date()/the_time()/
 * search_engines_discouraged() helpers used by themes. Mirrors SiteUrl/
 * BasePath: themes have no other route to PressConfig/MediaService, and
 * these values are identical on every page, so threading them through
 * every SiteController render() call would mean touching every action
 * for one request-scoped, set-once-at-bootstrap value.
 */
final class SiteBranding
{
    private static string $siteName = 'Lumora Press';

    private static string $tagline = '';

    private static ?string $logoUrl = null;

    private static ?string $faviconUrl = null;

    private static string $customCss = '';

    private static string $metaDescription = '';

    private static string $footerCopyrightText = '';

    private static ?string $defaultOgImageUrl = null;

    private static string $dateFormat = 'F j, Y';

    private static string $timeFormat = 'g:i a';

    private static bool $discourageSearchEngines = false;

    public static function set(
        string $siteName,
        ?string $logoUrl,
        ?string $faviconUrl,
        string $customCss,
        string $tagline = '',
        string $metaDescription = '',
        string $footerCopyrightText = '',
        ?string $defaultOgImageUrl = null,
        string $dateFormat = 'F j, Y',
        string $timeFormat = 'g:i a',
        bool $discourageSearchEngines = false,
    ): void {
        self::$siteName = $siteName !== '' ? $siteName : 'Lumora Press';
        self::$logoUrl = $logoUrl;
        self::$faviconUrl = $faviconUrl;
        self::$customCss = $customCss;
        self::$tagline = $tagline;
        self::$metaDescription = $metaDescription;
        self::$footerCopyrightText = $footerCopyrightText;
        self::$defaultOgImageUrl = $defaultOgImageUrl;
        self::$dateFormat = $dateFormat !== '' ? $dateFormat : 'F j, Y';
        self::$timeFormat = $timeFormat !== '' ? $timeFormat : 'g:i a';
        self::$discourageSearchEngines = $discourageSearchEngines;
    }

    public static function siteName(): string
    {
        return self::$siteName;
    }

    public static function tagline(): string
    {
        return self::$tagline;
    }

    public static function logoUrl(): ?string
    {
        return self::$logoUrl;
    }

    public static function faviconUrl(): ?string
    {
        return self::$faviconUrl;
    }

    public static function customCss(): string
    {
        return self::$customCss;
    }

    public static function metaDescription(): string
    {
        return self::$metaDescription;
    }

    public static function footerCopyrightText(): string
    {
        return self::$footerCopyrightText;
    }

    public static function defaultOgImageUrl(): ?string
    {
        return self::$defaultOgImageUrl;
    }

    public static function dateFormat(): string
    {
        return self::$dateFormat;
    }

    public static function timeFormat(): string
    {
        return self::$timeFormat;
    }

    public static function discourageSearchEngines(): bool
    {
        return self::$discourageSearchEngines;
    }
}
