<?php

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

/**
 * Static bridge exposing site branding (name, logo, favicon, custom CSS —
 * LP-034) to the procedural site_name()/site_logo_url()/favicon_url()/
 * custom_css() helpers used by themes. Mirrors SiteUrl/BasePath: themes
 * have no other route to PressConfig/MediaService, and these values are
 * identical on every page, so threading them through every
 * SiteController render() call would mean touching every action for one
 * request-scoped, set-once-at-bootstrap value.
 */
final class SiteBranding
{
    private static string $siteName = 'Lumora Press';

    private static ?string $logoUrl = null;

    private static ?string $faviconUrl = null;

    private static string $customCss = '';

    public static function set(string $siteName, ?string $logoUrl, ?string $faviconUrl, string $customCss): void
    {
        self::$siteName = $siteName !== '' ? $siteName : 'Lumora Press';
        self::$logoUrl = $logoUrl;
        self::$faviconUrl = $faviconUrl;
        self::$customCss = $customCss;
    }

    public static function siteName(): string
    {
        return self::$siteName;
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
}
