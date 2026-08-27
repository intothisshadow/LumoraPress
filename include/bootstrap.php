<?php

/**
 * Central application bootstrap: builds every service, wires hooks, sends the CSP header, and constructs the Kernel.
 *
 * @package LumoraPress
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

use LumoraPress\Controllers\ApiController;
use LumoraPress\Controllers\SiteController;
use LumoraPress\Core\ActiveConfig;
use LumoraPress\Core\ActiveEditorPreference;
use LumoraPress\Core\Autoloader;
use LumoraPress\Core\Cache\CacheDriverInterface;
use LumoraPress\Core\Cache\CacheManager;
use LumoraPress\Core\Cache\LiteSpeedCacheDriver;
use LumoraPress\Core\Cache\NullCacheDriver;
use LumoraPress\Core\Content\ActiveContentRenderer;
use LumoraPress\Core\Content\HtmlSanitizer;
use LumoraPress\Core\Content\MarkdownParser;
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\Errors\ErrorHandler;
use LumoraPress\Core\Errors\ErrorLogReader;
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Core\Hooks\Hooks;
use LumoraPress\Core\Http\BasePath;
use LumoraPress\Core\Http\MaintenanceGate;
use LumoraPress\Core\Http\Router;
use LumoraPress\Core\Mail\NativeMailer;
use LumoraPress\Core\Http\SiteUrl;
use LumoraPress\Core\Kernel;
use LumoraPress\Core\Menus\MenuManager;
use LumoraPress\Core\Menus\Menus;
use LumoraPress\Core\Plugin\PluginRegistry;
use LumoraPress\Core\PluginManager;
use LumoraPress\Core\PressConfig;
use LumoraPress\Core\Security\ApiTokenService;
use LumoraPress\Core\Security\Auth;
use LumoraPress\Core\Security\ContentSecurityPolicy;
use LumoraPress\Core\Security\CspNonce;
use LumoraPress\Core\Security\FormTiming;
use LumoraPress\Core\Security\LoginThrottle;
use LumoraPress\Core\Security\PasswordResetService;
use LumoraPress\Core\Security\PasswordResetThrottle;
use LumoraPress\Core\Security\RememberMeService;
use LumoraPress\Core\Security\SessionManager;
use LumoraPress\Core\Theme\ActiveAuth;
use LumoraPress\Core\Theme\ActiveCategories;
use LumoraPress\Core\Theme\ActivePages;
use LumoraPress\Core\Theme\ActiveTheme;
use LumoraPress\Core\Theme\Authors;
use LumoraPress\Core\Theme\FeaturedImages;
use LumoraPress\Core\Theme\FooterAssets;
use LumoraPress\Core\Theme\Permalinks;
use LumoraPress\Core\Theme\SiteBranding;
use LumoraPress\Core\Theme\ThemeOptions;
use LumoraPress\Core\Theme\ThemeOptionsBridge;
use LumoraPress\Core\Theme\ThemeRegistry;
use LumoraPress\Core\Theme\ThemeRenderer;
use LumoraPress\Core\Widgets\CoreWidgets;
use LumoraPress\Core\Widgets\WidgetManager;
use LumoraPress\Core\Widgets\Widgets;
use LumoraPress\Services\AkismetClient;
use LumoraPress\Services\BlueskyResolverService;
use LumoraPress\Services\CategoryService;
use LumoraPress\Services\CommentModerationService;
use LumoraPress\Services\CommentNotificationService;
use LumoraPress\Services\CommentService;
use LumoraPress\Services\ContentImportRegistry;
use LumoraPress\Services\ContentRenderer;
use LumoraPress\Services\DownloadCategoryMigrationService;
use LumoraPress\Services\EditorPreferenceService;
use LumoraPress\Services\EmbedService;
use LumoraPress\Services\EntityDecodeRepairService;
use LumoraPress\Services\FeedService;
use LumoraPress\Services\FolderGalleryShortcode;
use LumoraPress\Services\FolderService;
use LumoraPress\Services\GitHubReleaseProvider;
use LumoraPress\Services\Import\CommentImporter;
use LumoraPress\Services\Import\MediaImporter;
use LumoraPress\Services\Import\MenuImporter;
use LumoraPress\Services\Import\PageImporter;
use LumoraPress\Services\Import\PostImporter;
use LumoraPress\Services\Import\UserImporter;
use LumoraPress\Services\Import\WidgetImporter;
use LumoraPress\Services\MediaImportService;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\MediaStatsService;
use LumoraPress\Services\MediaUsageChecker;
use LumoraPress\Services\PageService;
use LumoraPress\Services\PermalinkService;
use LumoraPress\Services\PluginInstaller;
use LumoraPress\Services\PostService;
use LumoraPress\Services\RedirectService;
use LumoraPress\Services\RevisionService;
use LumoraPress\Services\SearchService;
use LumoraPress\Services\TagService;
use LumoraPress\Services\ThemeFileEditor;
use LumoraPress\Services\ThemeInstaller;
use LumoraPress\Services\ThumbnailService;
use LumoraPress\Services\UpdateBackupService;
use LumoraPress\Services\UpdateChecksumManifest;
use LumoraPress\Services\UpdateManifest;
use LumoraPress\Services\UpdatePackageValidator;
use LumoraPress\Services\UpdateProgress;
use LumoraPress\Services\UpdateService;
use LumoraPress\Services\UserService;

/**
 * Central application bootstrap. Loads configuration, starts the session,
 * connects to the database, wires services, loads plugins and the active
 * theme, and registers the front-end routes. Returns the composed Kernel;
 * callers are responsible for dispatching it.
 */

if (!defined('LUMORA_ROOT')) {
    define('LUMORA_ROOT', dirname(__DIR__));
}

require LUMORA_ROOT . '/app/Core/Autoloader.php';

$autoloader = new Autoloader();
$autoloader->addNamespace('LumoraPress', LUMORA_ROOT . '/app');
$autoloader->register();

$config = new PressConfig(LUMORA_ROOT . '/config/config.php');
ActiveConfig::set($config);

FormTiming::setSecretKey((string) $config->get('secret_key', ''));

$errorHandler = new ErrorHandler(LUMORA_ROOT . '/storage/logs', (bool) $config->get('debug', false));
$errorHandler->register();
$errorLogReader = new ErrorLogReader(LUMORA_ROOT . '/storage/logs');

$database = Database::connect(
    host: (string) $config->get('db_host', '127.0.0.1'),
    database: (string) $config->get('db_name', ''),
    username: (string) $config->get('db_user', ''),
    password: (string) $config->get('db_password', ''),
    charset: (string) $config->get('db_charset', 'utf8mb4'),
    port: (int) $config->get('db_port', 3306),
);

$config->bindDatabase($database);

/*
 * LP-042: the "timezone" DB option (editable on Settings > General) is
 * only readable once bindDatabase() above has run — before this fix,
 * date_default_timezone_set() ran on the install-time file-config copy
 * only (set once by the installer and never re-read), so changing the
 * Settings > General timezone field had no runtime effect at all.
 */
date_default_timezone_set((string) $config->option('timezone', $config->get('timezone', 'UTC')));

BasePath::set((string) $config->get('base_path', ''));

/*
 * Falls back to live detection for sites installed before the site_url
 * option existed (LP-028), so an existing install never breaks just
 * because it predates this feature.
 */
$detectedSiteUrl = SiteUrl::detect(
    isHttps: !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    host: (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'),
    basePath: BasePath::get(),
);
SiteUrl::set((string) $config->option('site_url', $detectedSiteUrl));

$secureCookies = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

// Empty (the default) means storage/sessions inside this install — see
// config.example.php's own comment. An install can point this elsewhere
// (outside this directory, a tmpfs, etc.) by setting an absolute path.
$configuredSessionPath = trim((string) $config->get('session_path', ''));

$sessions = new SessionManager(
    sessionPath: $configuredSessionPath !== '' ? $configuredSessionPath : LUMORA_ROOT . '/storage/sessions',
    secureCookies: $secureCookies,
);
$sessions->start();

require LUMORA_ROOT . '/include/helpers.php';

$hooks = new HookManager();
Hooks::set($hooks);
require LUMORA_ROOT . '/include/hooks.php';
$config->bindHooks($hooks);

/*
 * Content rendering (LP-015/LP-016) — set up early, right after hooks,
 * since include/theme.php's render_content() helper (loaded further
 * below alongside the rest of the procedural theme API) needs
 * ActiveContentRenderer already populated before any template can call
 * it. MarkdownParser/HtmlSanitizer are dependency-free pure-computation
 * classes (no DB/config), so nothing else needs to exist first.
 */
$content = new ContentRenderer(new MarkdownParser(), new HtmlSanitizer(), $hooks);
ActiveContentRenderer::set($content);

/*
 * ContentSecurityPolicy ships same-origin-only by design (see its own
 * docblock) and directs any genuine need to loosen a directive to this
 * exact filter rather than editing that class. EasyMDE (LP-015),
 * TinyMCE (LP-016), and the pre-existing PhotoSwipe lightbox
 * (admin/assets/js/media-viewer.js, content/themes/default's copy) are
 * all loaded from jsDelivr rather than vendored locally — see their own
 * docblocks for why — so script-src/style-src need that one origin
 * added, or every one of those libraries silently fails to load under
 * the default policy (a CSP violation blocks the request without
 * throwing a catchable JS error, so this failure mode is easy to miss
 * without actually checking the browser console/network tab). font-src
 * needs it too: EasyMDE's toolbar icons are Font Awesome 4 glyphs (also
 * loaded from jsDelivr — see content-editor.js), and FA's CSS pulls in
 * its actual font files via @font-face.
 *
 * style-src also needs a per-request nonce (LP-034 fix): the default
 * theme emits two inline <style> blocks (Custom CSS, Theme Options CSS,
 * both in header.php) that `style-src 'self'` blocks outright with no
 * catchable error on either side — the response is entirely valid, CSP
 * enforcement happens client-side, so this failure mode looks exactly
 * like "the CSS variable isn't taking effect" rather than "the whole
 * <style> block never ran," and was only found by inspecting
 * document.styleSheets directly. See CspNonce's own docblock. Google
 * Fonts (the new google_fonts_url Theme Option) needs its own two hosts:
 * style-src for the stylesheet fetch, font-src for the @font-face files
 * it references — added unconditionally, the same "always allow, don't
 * bother checking whether this page actually uses it" approach the
 * jsDelivr additions above already take. img-src needs
 * www.gravatar.com the same unconditional way: UserService::gravatarUrl()/
 * comment_avatar_url() (LP-047) always point there for both admin user
 * pictures and public comment avatars, and this same filter runs for
 * both admin and public responses (see its one call site below).
 */
$cspNonce = bin2hex(random_bytes(16));
CspNonce::set($cspNonce);

add_filter('csp_directives', static function (array $directives) use ($cspNonce): array {
    $directives['script-src'] .= ' https://cdn.jsdelivr.net';
    $directives['style-src'] .= " https://cdn.jsdelivr.net https://fonts.googleapis.com 'nonce-{$cspNonce}'";
    $directives['font-src'] .= ' https://cdn.jsdelivr.net https://fonts.gstatic.com';
    $directives['img-src'] .= ' https://www.gravatar.com';

    return $directives;
});

$tablePrefix = (string) $config->get('table_prefix', 'lp_');

$plugins = new PluginManager(LUMORA_ROOT . '/content/plugins');
$activePlugins = $config->option('active_plugins', '[]');
$activePlugins = is_string($activePlugins) ? (json_decode($activePlugins, true) ?: []) : (array) $activePlugins;
$plugins->loadActive($activePlugins);

$pluginsPath = LUMORA_ROOT . '/content/plugins';
$pluginRegistry = new PluginRegistry($pluginsPath, BasePath::get() . '/content/plugins', $activePlugins);
$pluginInstaller = new PluginInstaller($pluginsPath, LUMORA_ROOT . '/storage/plugin-installs', $pluginRegistry);

$widgets = new WidgetManager();
Widgets::set($widgets);
require LUMORA_ROOT . '/include/widgets.php';

$menus = new MenuManager();
Menus::set($menus);
require LUMORA_ROOT . '/include/menus.php';

$themesPath = LUMORA_ROOT . '/content/themes';
$themesUrl = BasePath::get() . '/content/themes';
$activeThemeSlug = (string) $config->option('active_theme', 'default');

$theme = new ThemeRenderer(
    themesPath: $themesPath,
    themesUrl: $themesUrl,
);
$theme->setActiveTheme($activeThemeSlug);
ActiveTheme::set($theme);
require LUMORA_ROOT . '/include/theme.php';
$theme->loadFunctions();

/*
 * LP-034 (originally scoped as LP-023 — see DECISIONS.md's "LP-023
 * merged into LP-034" entry): registered after loadFunctions() so a
 * theme's own functions.php can hook 'register_theme_options' to add
 * fields of its own, the same ordering widgets/menus already rely on for
 * register_widget()/register_nav_menu() above.
 */
$themeOptions = new ThemeOptions($config);
$themeOptions->registerStandardOptions();
do_action('register_theme_options', $themeOptions);
ThemeOptionsBridge::set($themeOptions);

$themes = new ThemeRegistry($themesPath, $themesUrl, $activeThemeSlug);
$themeInstaller = new ThemeInstaller($themesPath, $themes);
$themeFileEditor = new ThemeFileEditor($themesPath, LUMORA_ROOT . '/storage/theme-file-backups');

$users = new UserService($database, $tablePrefix);
$auth = new Auth($users, $sessions);
$editorPreferences = new EditorPreferenceService($config, $users, $hooks);
ActiveEditorPreference::set($editorPreferences);

/*
 * LP-025: thresholds are configurable on Settings > Security, previously
 * hardcoded to LoginThrottle's own defaults (5 attempts / 15 min window /
 * 15 min lockout) — those same values are still the fallback for a site
 * that hasn't saved a Security settings value yet.
 */
$loginThrottle = new LoginThrottle(
    $database,
    $tablePrefix,
    maxAttempts: max(1, (int) $config->option('login_max_attempts', '5')),
    windowSeconds: max(60, (int) $config->option('login_window_seconds', '900')),
    lockoutSeconds: max(60, (int) $config->option('login_lockout_seconds', '900')),
);
$rememberMe = new RememberMeService($database, $users, $tablePrefix, $secureCookies);
$apiTokens = new ApiTokenService($database, $users, $tablePrefix);

$passwordResets = new PasswordResetService($database, $tablePrefix);
$passwordResetThrottle = new PasswordResetThrottle($database, $tablePrefix);

// LP-058: the "From" address for password-reset emails. admin_email is the
// only email address Lumora Press already knows about (set at install time,
// editable on Settings > General) — falls back to a noreply@ address on
// this site's own host when it's never been set.
$adminEmail = trim((string) $config->option('admin_email', ''));
$mailFromAddress = $adminEmail !== ''
    ? $adminEmail
    : 'noreply@' . ((string) (parse_url(home_url(), PHP_URL_HOST) ?: 'localhost'));
$mailer = new NativeMailer($mailFromAddress);

$media = new MediaService(
    database: $database,
    tablePrefix: $tablePrefix,
    uploadsPath: LUMORA_ROOT . '/content/uploads',
    uploadsUrl: BasePath::get() . '/content/uploads',
    hooks: $hooks,
);

/*
 * site_name has been saved by the installer since LP-028, but nothing
 * read it back until LP-034 (Branding) — themes have no route to
 * PressConfig/MediaService of their own, hence the SiteBranding bridge.
 */
$resolveMediaUrl = static function (mixed $optionValue) use ($media): ?string {
    $id = (int) $optionValue;

    if ($id <= 0) {
        return null;
    }

    $item = $media->find($id);

    return $item !== null ? $media->url($item) : null;
};

SiteBranding::set(
    siteName: (string) $config->option('site_name', 'Lumora Press'),
    logoUrl: $resolveMediaUrl($config->option('site_logo_media_id', '')),
    faviconUrl: $resolveMediaUrl($config->option('favicon_media_id', '')),
    customCss: (string) $config->option('custom_css', ''),
    tagline: (string) $config->option('site_tagline', ''),
    metaDescription: (string) $config->option('meta_description', ''),
    footerCopyrightText: (string) $config->option('footer_copyright_text', ''),
    defaultOgImageUrl: $resolveMediaUrl($config->option('default_og_image_media_id', '')),
    dateFormat: (string) $config->option('date_format', 'F j, Y'),
    timeFormat: (string) $config->option('time_format', 'g:i a'),
    discourageSearchEngines: ((string) $config->option('discourage_search_engines', '0')) === '1',
);

$posts = new PostService($database, $tablePrefix, $hooks);
$pages = new PageService($database, $tablePrefix, $hooks);
$revisions = new RevisionService($database, $tablePrefix, $config);
$categories = new CategoryService($database, $tablePrefix, $hooks);
$tags = new TagService($database, $tablePrefix, $hooks);
$comments = new CommentService($database, $tablePrefix, $hooks);

/*
 * LPP-004/LPP-005 Phase 1: a shared bulk-content-creation layer used by
 * both the (future) WordPress WXR importer and the Dummy Content plugin,
 * so neither duplicates PostService/PageService/UserService/MediaService/
 * CommentService's create() call shapes. Constructed here since every
 * service above (posts/pages/categories/tags/comments/media/users) is
 * already available by this point.
 */
$contentImportRegistry = new ContentImportRegistry($database, $tablePrefix);
$postImporter = new PostImporter($posts, $categories, $tags, $contentImportRegistry);
$pageImporter = new PageImporter($pages, $contentImportRegistry);
$userImporter = new UserImporter($users, $contentImportRegistry);
$mediaImporter = new MediaImporter($media, LUMORA_ROOT . '/content/uploads', $contentImportRegistry);
$commentImporter = new CommentImporter($comments, $contentImportRegistry);
$menuImporter = new MenuImporter($menus, $contentImportRegistry);
$widgetImporter = new WidgetImporter($widgets, $contentImportRegistry);

$entityDecodeRepair = new EntityDecodeRepairService($database, $tablePrefix);
$downloadCategoryMigration = new DownloadCategoryMigrationService($database, $tablePrefix);

$redirects = new RedirectService($database, $tablePrefix);
$permalinks = new PermalinkService($config, $categories, $users);

/*
 * LP-023: auto-embed. Registered as a plain procedural add_filter() call
 * here, the same convention 'csp_directives' itself already uses just
 * above, rather than the service registering its own hooks in its
 * constructor — 'content_html' has no existing subscriber to follow as
 * precedent, so this matches the closest one that does.
 */
$blueskyResolver = new BlueskyResolverService($database, $tablePrefix);
$embeds = new EmbedService($config, $hooks, $blueskyResolver);
add_filter('content_html', [$embeds, 'render']);
add_filter('csp_directives', [$embeds, 'filterCsp']);

/*
 * LP-071: Bluesky's embed needs an AT-URI/CID this app can't derive from
 * a pasted URL by regex (see BlueskyResolverService's own class
 * docblock) — resolveContent() is the one deliberate, scoped exception to
 * this file's otherwise-total "auto-embed never makes an outbound
 * request" rule, run here once per save via the existing 'post_saved'/
 * 'page_saved' hooks (see PostService/PageService's own docblocks on why
 * those hooks exist) rather than from render(). Gated on the Bluesky
 * provider's own toggle so a site with it switched off in Settings >
 * Embeds never makes this request at all, matching every other
 * provider's on/off behavior.
 */
add_action('post_saved', static function (\LumoraPress\Models\Post $post) use ($blueskyResolver, $embeds): void {
    if ($embeds->providerEnabled('bluesky')) {
        $blueskyResolver->resolveContent($post->content);
    }
});
add_action('page_saved', static function (\LumoraPress\Models\Page $page) use ($blueskyResolver, $embeds): void {
    if ($embeds->providerEnabled('bluesky')) {
        $blueskyResolver->resolveContent($page->content);
    }
});

/*
 * LP-048: core widget types need PostService/PageService/CategoryService/
 * TagService/CommentService, all of which are only just constructed above
 * — registered here rather than alongside $widgets/require widgets.php
 * earlier in this file. Order relative to the active theme's own
 * register_widget() calls (loadFunctions(), already run by this point)
 * doesn't matter: registerWidget() only populates a lookup map queried
 * later at render time.
 */
CoreWidgets::register($widgets, $posts, $pages, $categories, $tags, $comments, $users, $content);

/*
 * Persisted widget assignments (LP-048) — one JSON option keyed by
 * sidebar id, the same "structured value as JSON in the options table"
 * approach already used for active_plugins. Loaded only for sidebars a
 * theme actually registered (loadFunctions() above), so a sidebar
 * removed by switching themes doesn't resurrect stale widgets if that
 * theme is switched back to later with an old copy of the option still
 * on file.
 */
$widgetsConfig = json_decode((string) $config->option('widgets_config', '{}'), true);
$widgetsConfig = is_array($widgetsConfig) ? $widgetsConfig : [];
$widgetsConfigNeedsBackfill = false;

/*
 * WidgetManager::INACTIVE_SIDEBAR_ID (LP-048 "inactive widgets") is a
 * reserved bucket, not a theme-registered sidebar, so it never appears in
 * $widgets->sidebars() — loaded here explicitly alongside the real ones so
 * the admin Widgets screen has it preloaded in memory on every request,
 * the same way registered sidebars are, rather than only after a POST that
 * happens to touch it this request.
 */
foreach ([...array_keys($widgets->sidebars()), WidgetManager::INACTIVE_SIDEBAR_ID] as $sidebarId) {
    if (isset($widgetsConfig[$sidebarId]) && is_array($widgetsConfig[$sidebarId])) {
        $widgets->setWidgets($sidebarId, $widgetsConfig[$sidebarId]);

        /*
         * setWidgets() generates an id in memory for any entry that
         * doesn't already carry one, but that generated id only lives
         * for this request — if it isn't written back into the
         * persisted option here, every widget saved before ids existed
         * (or added through a code path that forgot to persist one)
         * gets a *different* random id on the next request, so the id
         * embedded in the admin Widgets form no longer matches by the
         * time it's submitted and every Save/Move/Remove for that
         * widget fails with "That widget no longer exists."
         */
        foreach ($widgetsConfig[$sidebarId] as $widget) {
            if (!isset($widget['id'])) {
                $widgetsConfigNeedsBackfill = true;

                break;
            }
        }

        $widgetsConfig[$sidebarId] = $widgets->widgetsFor($sidebarId);
    }
}

if ($widgetsConfigNeedsBackfill) {
    $config->setOption('widgets_config', json_encode($widgetsConfig));
}

/*
 * Persisted navigation menus (LP-049) — two JSON options, the same
 * pattern LP-048 uses for widgets_config: "nav_menus" holds every named
 * menu (id => {name, items}), "nav_menu_locations" holds which menu id
 * (if any) is assigned to each theme-registered location. Loaded after
 * the theme's own register_nav_menu() calls (loadFunctions() above) so
 * every registered location is already known, though menus themselves
 * don't depend on that — a menu can exist unassigned to any location.
 */
$navMenusConfig = json_decode((string) $config->option('nav_menus', '{}'), true);
$navMenusConfig = is_array($navMenusConfig) ? $navMenusConfig : [];

foreach ($navMenusConfig as $menuId => $menuData) {
    if (!is_array($menuData) || !is_string($menuId)) {
        continue;
    }

    $menus->createMenu((string) ($menuData['name'] ?? ''), $menuId);
    $menus->setMenuItems($menuId, is_array($menuData['items'] ?? null) ? $menuData['items'] : []);
}

$navMenuLocationsConfig = json_decode((string) $config->option('nav_menu_locations', '{}'), true);
$navMenuLocationsConfig = is_array($navMenuLocationsConfig) ? $navMenuLocationsConfig : [];

foreach ($navMenuLocationsConfig as $locationSlug => $menuId) {
    if (is_string($locationSlug) && is_string($menuId) && array_key_exists($locationSlug, $menus->locations())) {
        $menus->assignMenuToLocation($locationSlug, $menuId);
    }
}

$search = new SearchService($database, $tablePrefix, $config, $content, $users);
$akismet = new AkismetClient($config, home_url());
// LP-047 Discussion Settings: shared moderation policy + notifications for
// both the public comment form (SiteController) and the REST API
// (ApiController) — see CommentModerationService's own docblock for why
// this sits between them rather than living in either controller.
$commentModeration = new CommentModerationService($config, $comments);
$commentNotifications = new CommentNotificationService($config, $mailer, $users);
$api = new ApiController($posts, $pages, $categories, $tags, $comments, $search, $apiTokens, $config, $hooks, akismet: $akismet, commentModeration: $commentModeration, commentNotifications: $commentNotifications);
$folders = new FolderService($database, $tablePrefix);
$mediaUsage = new MediaUsageChecker($posts, $pages, $config, $hooks);
$mediaStats = new MediaStatsService($database, $tablePrefix);
$thumbnails = new ThumbnailService(
    database: $database,
    tablePrefix: $tablePrefix,
    uploadsPath: LUMORA_ROOT . '/content/uploads',
    uploadsUrl: BasePath::get() . '/content/uploads',
    config: $config,
    hooks: $hooks,
    media: $media,
    logDirectory: LUMORA_ROOT . '/storage/logs',
);

// LP-122: [lumora_folder_gallery] — inserted via the content editor's
// "Insert Folder" button, alongside Insert Image.
$folderGallery = new FolderGalleryShortcode($folders, $media, $thumbnails);
add_filter('content_html', [$folderGallery, 'render']);

$feeds = new FeedService($posts, $users, $config, $hooks, $media, $thumbnails, $content);
$mediaImport = new MediaImportService(
    database: $database,
    tablePrefix: $tablePrefix,
    uploadsPath: LUMORA_ROOT . '/content/uploads',
    media: $media,
    thumbnails: $thumbnails,
    folders: $folders,
);

/*
 * Featured Images (LP-040) — themes have no route to MediaService/
 * ThumbnailService/PressConfig of their own, hence the FeaturedImages
 * bridge (see its docblock for why it holds live services rather than a
 * snapshot value like SiteBranding).
 */
FeaturedImages::set($media, $thumbnails, $config);
require LUMORA_ROOT . '/include/media-functions.php';

/*
 * FooterAssets (LP-031/LP-070/LP-071's PhotoSwipe/Twitter/Bluesky markup,
 * previously duplicated verbatim inside every theme's own footer.php) —
 * registered on 'footer_assets' the same way the Font Awesome plugin
 * registers its own icon <link> tags on 'head_assets'. A theme's
 * footer.php only needs to call do_action('footer_assets') once.
 */
add_action('footer_assets', [FooterAssets::class, 'render']);

/*
 * Author archive/profile-link helpers (LP-008) — same bridge shape as
 * FeaturedImages above, for the same reason (themes have no route to
 * UserService of their own).
 */
Authors::set($users);
require LUMORA_ROOT . '/include/author-functions.php';

/*
 * Post/category/tag permalink helpers (LP-078) — same bridge shape as
 * Authors/FeaturedImages above, for the same reason (themes have no route
 * to PermalinkService of their own). ActivePages backs the same file's
 * privacy_policy_url(), which needs to resolve the configured Privacy
 * Policy Page id to a live Page. ActiveCategories backs post_categories()/
 * the_post_categories(); ActiveAuth backs edit_post_link()/edit_page_link(),
 * which need to know who (if anyone) is currently signed in.
 */
Permalinks::set($permalinks);
ActivePages::set($pages);
ActiveCategories::set($categories);
ActiveAuth::set($auth);
require LUMORA_ROOT . '/include/permalink-functions.php';

/*
 * the_content()/get_the_content()/the_excerpt()/get_the_excerpt() (LP-079)
 * — unlike the bridges above, these need no bridge/service of their own:
 * they're pure orchestration over render_content()/content_plain_text()/
 * content_split_at_more_tag() (include/theme.php, already required above)
 * and make_excerpt()/theme_option() (include/helpers.php, already
 * required). Still grouped here with the other theme-helper-file requires
 * for consistency.
 */
require LUMORA_ROOT . '/include/content-display-functions.php';

/*
 * comment_form()/comment_list()/comment_avatar_url() — no bridge/service
 * of their own either (same shape as content-display-functions.php
 * above): pure orchestration over Csrf/FormTiming, with every value they
 * need (post, current user, guest field defaults) passed in by the
 * theme's comments.php from what SiteController already gave it.
 */
require LUMORA_ROOT . '/include/comment-functions.php';

/*
 * See core-paths.php's own docblock for what this list is and why it's a
 * standalone file rather than an inline array literal here.
 *
 * Defensive fallback (2026-08-13): this exact file went missing on a real
 * site mid-update — the update that *introduces* core-paths.php can't
 * reliably deliver it via the same mechanism that reads it (see
 * UpdateService::resolveEffectiveCorePaths()'s docblock for the full
 * chicken-and-egg explanation), and a partially-applied or interrupted
 * update could plausibly hit the same gap again. Previously this `require`
 * was unconditional, so a missing file took the entire site down (public
 * pages included, not just admin) with an opaque 500 instead of degrading.
 * The hardcoded fallback below is the same list core-paths.php itself
 * ships, kept in sync by hand — acceptable since this only ever runs when
 * the real file is already missing, i.e. something has already gone
 * wrong and staying up with a slightly stale list is strictly better than
 * a blank error page.
 */
$corePathsFile = LUMORA_ROOT . '/core-paths.php';
$updateCorePaths = is_file($corePathsFile) ? require $corePathsFile : [
    'app', 'admin', 'assets', 'include', 'install',
    'content/themes/default', 'content/plugins/font-awesome', 'content/plugins/dummy-content',
    'content/plugins/wordpress-importer', 'content/plugins/downloads',
    'index.php', 'version.php', '.htaccess', 'README.md', 'LICENSE.md', 'docs', 'core-paths.php',
];

$updateValidator = new UpdatePackageValidator(
    installRoot: LUMORA_ROOT,
    stagingRoot: LUMORA_ROOT . '/storage/updates/staging',
    corePaths: $updateCorePaths,
);

$updateManifest = new UpdateManifest(LUMORA_ROOT);
$updateChecksums = new UpdateChecksumManifest(LUMORA_ROOT);

$updateBackups = new UpdateBackupService(
    database: $database,
    tablePrefix: $tablePrefix,
    installRoot: LUMORA_ROOT,
    backupsPath: LUMORA_ROOT . '/storage/backups',
    corePaths: $updateCorePaths,
    manifest: $updateManifest,
    checksums: $updateChecksums,
);

$updateProgress = new UpdateProgress(LUMORA_ROOT);

$updates = new UpdateService(
    database: $database,
    tablePrefix: $tablePrefix,
    hooks: $hooks,
    validator: $updateValidator,
    backups: $updateBackups,
    installRoot: LUMORA_ROOT,
    migrationsPath: LUMORA_ROOT . '/install/migrations',
    versionFilePath: LUMORA_ROOT . '/version.php',
    stagingRoot: LUMORA_ROOT . '/storage/updates/staging',
    lockFilePath: LUMORA_ROOT . '/storage/updates/update.lock',
    corePaths: $updateCorePaths,
    manifest: $updateManifest,
    configFilePath: LUMORA_ROOT . '/config/config.php',
    config: $config,
    users: $users,
    checksums: $updateChecksums,
    progress: $updateProgress,
);

$githubUpdates = new GitHubReleaseProvider($config);

/*
 * LP-037: driver auto-detection, with a manual override option (Settings
 * > Cache) for a host where detection guesses wrong. LiteSpeedCacheDriver
 * is only actually used when it reports itself available; otherwise the
 * inert Null driver keeps every CacheManager call a safe no-op.
 */
$cacheDriverOverride = (string) $config->option('cache_driver', 'auto');
$cacheDriver = match ($cacheDriverOverride) {
    'litespeed' => new LiteSpeedCacheDriver(),
    'null' => new NullCacheDriver(),
    default => (static function (): CacheDriverInterface {
        $liteSpeed = new LiteSpeedCacheDriver();

        return $liteSpeed->isAvailable() ? $liteSpeed : new NullCacheDriver();
    })(),
};
$cache = new CacheManager($config, $cacheDriver, LUMORA_ROOT);

/*
 * Automatic invalidation (LP-037): every content-change action fired
 * from the service layer above (PostService/PageService/
 * CategoryService/TagService/CommentService — each optionally
 * HookManager-aware, see PostService's docblock) plus the generic
 * 'option_changed' PressConfig::setOption() now fires (covering
 * settings/widgets/menus/theme activation/theme options, all stored as
 * options) purges the whole cache. A blanket purgeAll() rather than
 * per-tag targeting is a deliberate simplicity trade-off for this first
 * pass — always correct, just not maximally efficient; see TODO.md's
 * LP-037 entry.
 */
foreach ([
    'post_saved', 'post_deleted',
    'page_saved', 'page_deleted',
    'category_saved', 'category_deleted',
    'tag_saved', 'tag_deleted',
    'comment_posted', 'comment_status_changed', 'comment_deleted',
    'media_saved', 'media_deleted',
    'option_changed',
] as $cachePurgeAction) {
    $hooks->addAction($cachePurgeAction, static function () use ($cache): void {
        $cache->purgeAll();
    });
}

/*
 * LP-026 "Rebuild caches if necessary": UpdateService::install() already
 * empties storage/cache/ itself (see its own clearCache()), but that's
 * this app's own local file cache — an external reverse-proxy/edge cache
 * (LiteSpeed, via $cacheDriver above) is a separate thing that also needs
 * purging once new code is live, the same purgeAll() every content-change
 * action above already triggers. Reuses the existing
 * 'lumora_press_after_update' hook rather than a new UpdateService
 * constructor dependency, consistent with how this class already prefers
 * hooks for cross-cutting concerns.
 */
$hooks->addAction('lumora_press_after_update', static function (string $fromVersion, string $toVersion, \LumoraPress\Models\UpdateStatus $status) use ($cache): void {
    if ($status === \LumoraPress\Models\UpdateStatus::Success) {
        $cache->purgeAll();
    }
});

$router = new Router();
$maintenance = new MaintenanceGate($config, $auth, $theme, $hooks);

$kernel = new Kernel(
    config: $config,
    database: $database,
    errors: $errorHandler,
    errorLog: $errorLogReader,
    hooks: $hooks,
    plugins: $plugins,
    theme: $theme,
    widgets: $widgets,
    menus: $menus,
    sessions: $sessions,
    users: $users,
    auth: $auth,
    loginThrottle: $loginThrottle,
    rememberMe: $rememberMe,
    media: $media,
    posts: $posts,
    pages: $pages,
    categories: $categories,
    tags: $tags,
    comments: $comments,
    feeds: $feeds,
    search: $search,
    updates: $updates,
    updateProgress: $updateProgress,
    githubUpdates: $githubUpdates,
    router: $router,
    maintenance: $maintenance,
    themes: $themes,
    themeInstaller: $themeInstaller,
    folders: $folders,
    mediaUsage: $mediaUsage,
    thumbnails: $thumbnails,
    mediaImport: $mediaImport,
    apiTokens: $apiTokens,
    content: $content,
    pluginRegistry: $pluginRegistry,
    pluginInstaller: $pluginInstaller,
    revisions: $revisions,
    themeFileEditor: $themeFileEditor,
    mediaStats: $mediaStats,
    cache: $cache,
    redirects: $redirects,
    akismet: $akismet,
    passwordResets: $passwordResets,
    passwordResetThrottle: $passwordResetThrottle,
    mailer: $mailer,
    themeOptions: $themeOptions,
    embeds: $embeds,
    editorPreferences: $editorPreferences,
    commentModeration: $commentModeration,
    permalinks: $permalinks,
    contentImportRegistry: $contentImportRegistry,
    postImporter: $postImporter,
    pageImporter: $pageImporter,
    userImporter: $userImporter,
    mediaImporter: $mediaImporter,
    commentImporter: $commentImporter,
    menuImporter: $menuImporter,
    widgetImporter: $widgetImporter,
    entityDecodeRepair: $entityDecodeRepair,
    downloadCategoryMigration: $downloadCategoryMigration,
);

$site = new SiteController($theme, $posts, $pages, $categories, $tags, $comments, $auth, $config, $feeds, $search, $media, $mediaStats, $cache, $redirects, $akismet, $users, $commentModeration, $commentNotifications, $permalinks);

/*
 * LP-078: the post/category/tag patterns are derived from PermalinkService
 * rather than hardcoded, so a configured permalink_structure/category_base/
 * tag_base option changes what Router actually matches, not just what
 * post_permalink()/category_permalink()/tag_permalink() generate. The
 * default (unconfigured) structure compiles to the exact same
 * '/post/{slug}' pattern this application always registered — see
 * PermalinkService::postRoutePattern()'s docblock.
 */
$postRoutePattern = $permalinks->postRoutePattern();

$router->get('/', fn (array $params) => $site->home($params));
$router->get($postRoutePattern, fn (array $params) => $site->singlePost($params));
$router->post($postRoutePattern . '/comment', fn (array $params) => $site->submitComment($params));
$router->get('/preview/{id}', fn (array $params) => $site->previewPost($params));
$router->get('/preview-page/{id}', fn (array $params) => $site->previewPage($params));
$router->get('/author/{slug}', fn (array $params) => $site->author($params));
$router->get($permalinks->categoryRoutePattern(), fn (array $params) => $site->category($params));
$router->get($permalinks->categoryFeedRoutePattern(), fn (array $params) => $site->categoryFeed($params));
$router->get($permalinks->categoryFeedFormatRoutePattern(), fn (array $params) => $site->categoryFeed($params));
$router->get($permalinks->tagRoutePattern(), fn (array $params) => $site->tag($params));
$router->get('/archive', fn (array $params) => $site->archive($params));
$router->get('/archive/{year}/{month}', fn (array $params) => $site->archiveByMonth($params));
$router->get('/search', fn (array $params) => $site->search($params));
/*
 * LP-084: "/page/{slug}" is now a legacy URL, permanently redirected to
 * the page's real hierarchical URL — see the route table's closing block
 * below, where "/{path*}" (the actual hierarchical Page route) is
 * registered last, after every other route.
 */
$router->get('/page/{slug}', fn (array $params) => $site->legacyPageRedirect($params));
$router->post('/page/{slug}/comment', fn (array $params) => $site->submitPageComment($params));
$router->get('/feed', fn (array $params) => $site->feed($params));
$router->get('/feed/{format}', fn (array $params) => $site->feed($params));
$router->get('/robots.txt', fn (array $params) => $site->robotsTxt($params));
$router->get('/sitemap.xml', fn (array $params) => $site->sitemap($params));
$router->get('/media/{id}/download', fn (array $params) => $site->mediaDownload($params));

$adminHandler = static function (array $params) use ($kernel): void {
    if (isset($params['page'])) {
        $_GET['page'] = $params['page'];
    }

    if (isset($params['subpage'])) {
        $_GET['subpage'] = $params['subpage'];
    }

    require LUMORA_ROOT . '/admin/index.php';
};

$router->get('/admin', $adminHandler);
$router->get('/admin/{page}', $adminHandler);
// LP-043: Settings/Maintenance are two-level menus (e.g. /admin/settings/general),
// registered as their own pattern rather than teaching Router about optional
// segments — it only matches a fixed number of path parts per pattern.
$router->get('/admin/{page}/{subpage}', $adminHandler);
$router->post('/admin', $adminHandler);
$router->post('/admin/{page}', $adminHandler);
$router->post('/admin/{page}/{subpage}', $adminHandler);

// REST API (LP-021), versioned under /api/v1.
$router->get('/api/v1/posts', fn (array $params) => $api->postsIndex($params));
$router->get('/api/v1/posts/{slug}', fn (array $params) => $api->postsShow($params));
$router->post('/api/v1/posts', fn (array $params) => $api->postsStore($params));
$router->patch('/api/v1/posts/{id}', fn (array $params) => $api->postsUpdate($params));
$router->delete('/api/v1/posts/{id}', fn (array $params) => $api->postsDestroy($params));

$router->get('/api/v1/pages', fn (array $params) => $api->pagesIndex($params));
$router->get('/api/v1/pages/{slug}', fn (array $params) => $api->pagesShow($params));
$router->post('/api/v1/pages', fn (array $params) => $api->pagesStore($params));
$router->patch('/api/v1/pages/{id}', fn (array $params) => $api->pagesUpdate($params));
$router->delete('/api/v1/pages/{id}', fn (array $params) => $api->pagesDestroy($params));

$router->get('/api/v1/categories', fn (array $params) => $api->categoriesIndex($params));
$router->get('/api/v1/categories/{slug}', fn (array $params) => $api->categoriesShow($params));
$router->post('/api/v1/categories', fn (array $params) => $api->categoriesStore($params));
$router->patch('/api/v1/categories/{id}', fn (array $params) => $api->categoriesUpdate($params));
$router->delete('/api/v1/categories/{id}', fn (array $params) => $api->categoriesDestroy($params));

$router->get('/api/v1/tags', fn (array $params) => $api->tagsIndex($params));
$router->get('/api/v1/tags/{slug}', fn (array $params) => $api->tagsShow($params));
$router->post('/api/v1/tags', fn (array $params) => $api->tagsStore($params));
$router->patch('/api/v1/tags/{id}', fn (array $params) => $api->tagsUpdate($params));
$router->delete('/api/v1/tags/{id}', fn (array $params) => $api->tagsDestroy($params));

$router->get('/api/v1/comments', fn (array $params) => $api->commentsIndex($params));
$router->post('/api/v1/comments', fn (array $params) => $api->commentsStore($params));
$router->patch('/api/v1/comments/{id}', fn (array $params) => $api->commentsUpdate($params));
$router->delete('/api/v1/comments/{id}', fn (array $params) => $api->commentsDestroy($params));

$router->get('/api/v1/search', fn (array $params) => $api->searchIndex($params));

// REST API (LP-021), versioned under /api/v1.
$router->get('/api/v1/posts', fn (array $params) => $api->postsIndex($params));
$router->get('/api/v1/posts/{slug}', fn (array $params) => $api->postsShow($params));
$router->post('/api/v1/posts', fn (array $params) => $api->postsStore($params));
$router->patch('/api/v1/posts/{id}', fn (array $params) => $api->postsUpdate($params));
$router->delete('/api/v1/posts/{id}', fn (array $params) => $api->postsDestroy($params));

$router->get('/api/v1/pages', fn (array $params) => $api->pagesIndex($params));
$router->get('/api/v1/pages/{slug}', fn (array $params) => $api->pagesShow($params));
$router->post('/api/v1/pages', fn (array $params) => $api->pagesStore($params));
$router->patch('/api/v1/pages/{id}', fn (array $params) => $api->pagesUpdate($params));
$router->delete('/api/v1/pages/{id}', fn (array $params) => $api->pagesDestroy($params));

$router->get('/api/v1/categories', fn (array $params) => $api->categoriesIndex($params));
$router->get('/api/v1/categories/{slug}', fn (array $params) => $api->categoriesShow($params));
$router->post('/api/v1/categories', fn (array $params) => $api->categoriesStore($params));
$router->patch('/api/v1/categories/{id}', fn (array $params) => $api->categoriesUpdate($params));
$router->delete('/api/v1/categories/{id}', fn (array $params) => $api->categoriesDestroy($params));

$router->get('/api/v1/tags', fn (array $params) => $api->tagsIndex($params));
$router->get('/api/v1/tags/{slug}', fn (array $params) => $api->tagsShow($params));
$router->post('/api/v1/tags', fn (array $params) => $api->tagsStore($params));
$router->patch('/api/v1/tags/{id}', fn (array $params) => $api->tagsUpdate($params));
$router->delete('/api/v1/tags/{id}', fn (array $params) => $api->tagsDestroy($params));

$router->get('/api/v1/comments', fn (array $params) => $api->commentsIndex($params));
$router->post('/api/v1/comments', fn (array $params) => $api->commentsStore($params));
$router->patch('/api/v1/comments/{id}', fn (array $params) => $api->commentsUpdate($params));
$router->delete('/api/v1/comments/{id}', fn (array $params) => $api->commentsDestroy($params));

$router->get('/api/v1/search', fn (array $params) => $api->searchIndex($params));

/*
 * LP-084: hierarchical Page URLs — "/{path*}" greedily matches any
 * remaining path ("about/team", "about", ...), so it must be the very
 * last route registered, after every fixed-pattern route above
 * (including /admin, /api/v1/*, and the legacy "/page/{slug}" redirect
 * registered earlier in this file), or it would shadow all of them (see
 * Router's own docblock).
 */
$router->get('/{path*}', fn (array $params) => $site->pageByPath($params));
$router->post('/{path*}/comment', fn (array $params) => $site->submitPageComment($params));

$router->setNotFoundHandler(static fn () => $site->notFound());

/*
 * Sent last, after plugins and the theme's functions.php have loaded, so
 * any add_filter('csp_directives', ...) they registered is honoured. No
 * page output has happened yet at this point (bootstrap.php never echoes
 * anything itself), so the header is always still sendable here.
 */
if ((bool) $config->get('csp_enabled', true)) {
    $cspDirectives = apply_filters('csp_directives', ContentSecurityPolicy::defaultDirectives());
    (new ContentSecurityPolicy($cspDirectives))->send();
}

do_action('lumora_press_loaded');

return $kernel;
