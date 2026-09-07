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
use LumoraPress\Core\ActiveKernel;
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
use LumoraPress\Core\Security\TrustedImageOrigins;
use LumoraPress\Core\Shortcodes\ShortcodeManager;
use LumoraPress\Core\Shortcodes\Shortcodes;
use LumoraPress\Core\Theme\ActiveAuth;
use LumoraPress\Core\Theme\ActiveCategories;
use LumoraPress\Core\Theme\ActivePages;
use LumoraPress\Core\Theme\ActivePosts;
use LumoraPress\Core\Theme\ActiveTags;
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
use LumoraPress\Services\InstallPingService;
use LumoraPress\Services\MediaImportService;
use LumoraPress\Services\MediaPlayerShortcode;
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
use LumoraPress\Services\SettingsPortabilityService;
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

// Returns the composed Kernel; callers are responsible for dispatching it.

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

// The "timezone" DB option is only readable after bindDatabase() above,
// so this must run after that call.
date_default_timezone_set((string) $config->option('timezone', $config->get('timezone', 'UTC')));

BasePath::set((string) $config->get('base_path', ''));

// Falls back to live detection for sites installed before the site_url
// option existed.
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

// Set up early: include/theme.php's render_content() helper needs
// ActiveContentRenderer populated before any template can call it.
$content = new ContentRenderer(new MarkdownParser(), new HtmlSanitizer(), $hooks);
ActiveContentRenderer::set($content);

// ContentSecurityPolicy ships same-origin-only by design; any need to
// loosen a directive belongs in a filter here, not in that class.
// jsDelivr serves EasyMDE/TinyMCE/PhotoSwipe and Font Awesome's glyphs
// (script-src, style-src, font-src). style-src also carries a per-request
// nonce for the theme's inline <style> blocks (see CspNonce). Google
// Fonts and Gravatar are added unconditionally since both are always
// used regardless of settings.
$cspNonce = bin2hex(random_bytes(16));
CspNonce::set($cspNonce);

add_filter('csp_directives', static function (array $directives) use ($cspNonce): array {
    $directives['script-src'] .= ' https://cdn.jsdelivr.net';
    $directives['style-src'] .= " https://cdn.jsdelivr.net https://fonts.googleapis.com 'nonce-{$cspNonce}'";
    $directives['font-src'] .= ' https://cdn.jsdelivr.net https://fonts.gstatic.com';
    $directives['img-src'] .= ' https://www.gravatar.com';
    // Plyr fetches its icon sprite at runtime (an XHR, not a <script>/<link>
    // load), so it needs connect-src too — media-player.js points it at
    // this same jsdelivr copy instead of Plyr's own cdn.plyr.io default.
    $directives['connect-src'] = ($directives['connect-src'] ?? "'self'") . ' https://cdn.jsdelivr.net';

    return $directives;
});

// Lets content authors reference an external image host the admin
// explicitly trusts (Settings > Security > Trusted Image Sources) —
// img-src 'self' would otherwise silently block it.
add_filter('csp_directives', static function (array $directives) use ($config): array {
    $validOrigins = TrustedImageOrigins::parse((string) $config->option('trusted_image_origins', ''));

    if ($validOrigins !== []) {
        $directives['img-src'] .= ' ' . implode(' ', $validOrigins);
    }

    return $directives;
});

// Set up before $plugins->loadActive() so a plugin's top-level file can
// call register_shortcode() directly at load time — unlike CoreWidgets,
// a shortcode needs no other services. A plugin needing database-backed
// choices instead hooks 'register_shortcodes', fired later once Kernel
// exists.
$shortcodes = new ShortcodeManager();
Shortcodes::set($shortcodes);
require LUMORA_ROOT . '/include/shortcodes.php';

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
$activeThemeSlug = (string) $config->option('active_theme', 'lumora-classic');

$theme = new ThemeRenderer(
    themesPath: $themesPath,
    themesUrl: $themesUrl,
);
$theme->setActiveTheme($activeThemeSlug);
ActiveTheme::set($theme);
require LUMORA_ROOT . '/include/theme.php';
$theme->loadFunctions();

// Registered after loadFunctions() so a theme's functions.php can hook
// 'register_theme_options' to add fields of its own.
$themeOptions = new ThemeOptions($config, $activeThemeSlug);
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

// Thresholds are configurable on Settings > Security; fallbacks apply
// to a site that hasn't saved a value yet.
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

// The "From" address for password-reset emails. admin_email is the only
// email address Lumora Press already knows about (set at install time,
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

// Themes have no route to PressConfig/MediaService of their own, hence
// the SiteBranding bridge.
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
    defaultOgImageUrl: $resolveMediaUrl($config->option('default_og_image_media_id', '')),
    dateFormat: (string) $config->option('date_format', 'F j, Y'),
    timeFormat: (string) $config->option('time_format', 'g:i a'),
    discourageSearchEngines: ((string) $config->option('discourage_search_engines', '0')) === '1',
);

// header_image_media_id lives inside ThemeOptions' own per-theme values,
// not a plain PressConfig option, but still needs $resolveMediaUrl above
// to become a URL.
ThemeOptionsBridge::setHeaderImageUrl($resolveMediaUrl($themeOptions->headerImageMediaId()));

$posts = new PostService($database, $tablePrefix, $hooks);
$pages = new PageService($database, $tablePrefix, $hooks);
$revisions = new RevisionService($database, $tablePrefix, $config);
$categories = new CategoryService($database, $tablePrefix, $hooks);
$tags = new TagService($database, $tablePrefix, $hooks);
$comments = new CommentService($database, $tablePrefix, $hooks);

// Shared bulk-content-creation layer used by both the WXR importer and
// the Dummy Content plugin, avoiding duplicated create() call shapes.
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

// Auto-embed, registered as a plain add_filter() call here rather than
// the service registering its own hooks in its constructor.
$blueskyResolver = new BlueskyResolverService($database, $tablePrefix);
$embeds = new EmbedService($config, $hooks, $blueskyResolver);
add_filter('content_html', [$embeds, 'render']);
add_filter('csp_directives', [$embeds, 'filterCsp']);

// Bluesky's embed needs an AT-URI/CID that can't be derived from a
// pasted URL by regex, so resolveContent() makes the one deliberate
// outbound request auto-embed otherwise avoids, run once per save
// rather than from render(). Gated on the provider's own toggle.
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

// Core widget types need the services just constructed above, hence
// registered here rather than alongside widgets.php earlier.
CoreWidgets::register($widgets, $posts, $pages, $categories, $tags, $comments, $users, $content);

// Persisted widget assignments — one JSON option keyed by sidebar id.
$widgetsConfig = json_decode((string) $config->option('widgets_config', '{}'), true);
$widgetsConfig = is_array($widgetsConfig) ? $widgetsConfig : [];
$widgetsConfigDirty = false;

$registeredSidebarIds = [...array_keys($widgets->sidebars()), WidgetManager::INACTIVE_SIDEBAR_ID];

// A sidebar id saved from a previous theme that the active theme no
// longer registers (switched themes, or a theme dropped a sidebar) has
// its widgets moved into the Inactive Widgets bucket rather than
// silently going unloaded and unreachable — the classic-WordPress
// "widgets survive a theme switch, waiting to be reassigned" behavior.
foreach (array_diff(array_keys($widgetsConfig), $registeredSidebarIds) as $orphanedSidebarId) {
    if (is_array($widgetsConfig[$orphanedSidebarId] ?? null)) {
        $widgetsConfig[WidgetManager::INACTIVE_SIDEBAR_ID] ??= [];
        array_push($widgetsConfig[WidgetManager::INACTIVE_SIDEBAR_ID], ...$widgetsConfig[$orphanedSidebarId]);
    }

    unset($widgetsConfig[$orphanedSidebarId]);
    $widgetsConfigDirty = true;
}

// INACTIVE_SIDEBAR_ID is a reserved bucket, not a theme-registered
// sidebar, so it's loaded explicitly alongside the real ones.
foreach ($registeredSidebarIds as $sidebarId) {
    if (isset($widgetsConfig[$sidebarId]) && is_array($widgetsConfig[$sidebarId])) {
        $widgets->setWidgets($sidebarId, $widgetsConfig[$sidebarId]);

        // setWidgets() generates an id in memory for any entry missing
        // one; write it back or the admin Widgets form's embedded id
        // would no longer match on the next request.
        foreach ($widgetsConfig[$sidebarId] as $widget) {
            if (!isset($widget['id'])) {
                $widgetsConfigDirty = true;

                break;
            }
        }

        $widgetsConfig[$sidebarId] = $widgets->widgetsFor($sidebarId);
    }
}

if ($widgetsConfigDirty) {
    $config->setOption('widgets_config', json_encode($widgetsConfig));
}

// Persisted navigation menus, same JSON-option pattern as
// widgets_config: "nav_menus" holds each menu (id => {name, items}),
// "nav_menu_locations" maps location slug to assigned menu id. Loaded
// after register_nav_menu() so every location is already known.
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
// Shared moderation policy + notifications for both the public comment
// form (SiteController) and the REST API (ApiController) — see
// CommentModerationService's own docblock for why this sits between them
// rather than living in either controller.
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

// [lumora_folder_gallery] — inserted via the content editor's "Insert
// Folder" button, alongside Insert Image.
$folderGallery = new FolderGalleryShortcode($folders, $media, $thumbnails);
add_filter('content_html', [$folderGallery, 'render']);

// [lumora_audio]/[lumora_video] — inserted via the content editor's
// "Insert Audio"/"Insert Video" buttons, alongside Insert Folder.
$mediaPlayer = new MediaPlayerShortcode($media);
add_filter('content_html', [$mediaPlayer, 'render']);

$feeds = new FeedService($posts, $users, $config, $hooks, $media, $thumbnails, $content);
$mediaImport = new MediaImportService(
    database: $database,
    tablePrefix: $tablePrefix,
    uploadsPath: LUMORA_ROOT . '/content/uploads',
    media: $media,
    thumbnails: $thumbnails,
    folders: $folders,
);

// Themes have no route of their own to MediaService/ThumbnailService/
// PressConfig, hence the FeaturedImages bridge.
FeaturedImages::set($media, $thumbnails, $config);
require LUMORA_ROOT . '/include/media-functions.php';

// FooterAssets registered on 'footer_assets', same pattern as the Font
// Awesome plugin's 'head_assets' hook.
add_action('footer_assets', [FooterAssets::class, 'render']);

// Same bridge shape as FeaturedImages above, for the same reason
// (themes have no route to UserService of their own).
Authors::set($users);
require LUMORA_ROOT . '/include/author-functions.php';

// Same bridge shape as Authors/FeaturedImages above. ActivePages backs
// privacy_policy_url(); ActiveCategories backs post_categories();
// ActiveAuth backs edit_post_link()/edit_page_link()'s login check.
Permalinks::set($permalinks);
ActivePages::set($pages);
ActiveCategories::set($categories);
ActiveAuth::set($auth);
require LUMORA_ROOT . '/include/permalink-functions.php';

// A post's own tags, and posts related to it by shared tags — same
// bridge shape as ActiveCategories above, for the same reason (themes
// have no route to TagService/PostService of their own).
ActiveTags::set($tags);
ActivePosts::set($posts);
require LUMORA_ROOT . '/include/taxonomy-functions.php';

// the_content()/the_excerpt() and friends need no bridge of their own —
// pure orchestration over helpers already required above.
require LUMORA_ROOT . '/include/content-display-functions.php';

// Same shape as content-display-functions.php above: pure orchestration
// over Csrf/FormTiming, with values passed in by the theme's comments.php.
require LUMORA_ROOT . '/include/comment-functions.php';

// See core-paths.php's own docblock for what this list is.
//
// Defensive fallback: this file can go missing mid-update, and an
// unconditional `require` would take the whole site down with an opaque
// 500. The hardcoded list below is kept in sync by hand — acceptable
// since it only runs when the real file is already missing.
$corePathsFile = LUMORA_ROOT . '/core-paths.php';
$updateCorePaths = is_file($corePathsFile) ? require $corePathsFile : [
    'app', 'admin', 'assets', 'include', 'install',
    'content/themes/lumora-classic', 'content/plugins/font-awesome', 'content/plugins/dummy-content',
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

// Deliberately independent of $githubUpdates above (see
// InstallPingService's docblock) — its own endpoint, its own option
// keys, off by default until an administrator opts in on Settings >
// Privacy.
$appVersion = require LUMORA_ROOT . '/version.php';
$installPing = new InstallPingService($config, (string) $appVersion['version']);

// Staging directory mirrors PluginInstaller's own storage/plugin-installs
// convention above.
$settingsPortability = new SettingsPortabilityService($config, LUMORA_ROOT . '/storage/settings-imports', (string) $appVersion['version']);

// Driver auto-detection, with a manual override (Settings > Cache) for
// a host where detection guesses wrong. Falls back to the inert Null
// driver when LiteSpeed isn't actually available.
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

// Automatic invalidation: every content-change action, plus the generic
// 'option_changed', purges the whole cache. A blanket purgeAll() rather
// than per-tag targeting is a deliberate simplicity trade-off.
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

// UpdateService::install() empties storage/cache/ itself, but an
// external edge cache (LiteSpeed, via $cacheDriver) also needs purging
// once new code is live — reuses the existing update hook.
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
    installPing: $installPing,
    shortcodes: $shortcodes,
    settingsPortability: $settingsPortability,
);

// See ActiveKernel's own docblock for why this exists — plugin code that
// needs more than one Kernel-wired service from inside a hook callback,
// which only ever runs once a real request is underway, well after this
// line.
ActiveKernel::set($kernel);

$site = new SiteController($theme, $posts, $pages, $categories, $tags, $comments, $auth, $config, $feeds, $search, $media, $mediaStats, $cache, $redirects, $akismet, $users, $commentModeration, $commentNotifications, $permalinks);

// Patterns are derived from PermalinkService, not hardcoded, so a
// configured permalink_structure/category_base/tag_base option changes
// what Router actually matches, not just what the *_permalink()
// functions generate.
$postRoutePattern = $permalinks->postRoutePattern();

$router->get('/', fn (array $params) => $site->home($params));
$router->get($postRoutePattern, fn (array $params) => $site->singlePost($params));
$router->post($postRoutePattern . '/comment', fn (array $params) => $site->submitComment($params));
$router->get('/preview/{id}', fn (array $params) => $site->previewPost($params));
$router->get('/preview-page/{id}', fn (array $params) => $site->previewPage($params));
$router->get('/author/{slug}/feed/{format}', fn (array $params) => $site->authorFeed($params));
$router->get('/author/{slug}/feed', fn (array $params) => $site->authorFeed($params));
$router->get('/author/{slug}', fn (array $params) => $site->author($params));
// categoryRoutePattern() is a greedy "{path*}" match (hierarchical category
// URLs) — the two feed routes below are more specific (both require a
// trailing "/feed" or "/feed/{format}" segment) and so must be registered
// first, or the bare category route would swallow a feed URL itself. See
// PermalinkService::categoryRoutePattern()'s own docblock.
$router->get($permalinks->categoryFeedFormatRoutePattern(), fn (array $params) => $site->categoryFeed($params));
$router->get($permalinks->categoryFeedRoutePattern(), fn (array $params) => $site->categoryFeed($params));
$router->get($permalinks->categoryRoutePattern(), fn (array $params) => $site->category($params));
$router->get($permalinks->tagFeedFormatRoutePattern(), fn (array $params) => $site->tagFeed($params));
$router->get($permalinks->tagFeedRoutePattern(), fn (array $params) => $site->tagFeed($params));
$router->get($permalinks->tagRoutePattern(), fn (array $params) => $site->tag($params));
$router->get('/archive', fn (array $params) => $site->archive($params));
$router->get('/archive/{year}/{month}', fn (array $params) => $site->archiveByMonth($params));
$router->get('/search', fn (array $params) => $site->search($params));
/*
 * "/page/{slug}" is now a legacy URL, permanently redirected to the
 * page's real hierarchical URL — see the route table's closing block
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
$router->get('/media/{id}/view', fn (array $params) => $site->mediaView($params));

// Dedicated submission route, gated on the plugin being active: theme
// templates echo directly rather than buffering, so the shortcode's own
// content_html filter can't safely redirect() a POST from inside
// itself — same reasoning as comment submission's own route above.
if (in_array('contact-forms', $activePlugins, true)) {
    $router->post('/contact-form/{id}/submit', fn (array $params) => (new \LumoraPress\Plugins\ContactForms\ContactFormSubmissionHandler(
        $kernel->database,
        $tablePrefix,
        $kernel->mailer,
        $kernel->akismet,
        $kernel->config,
    ))->handle($params));
}

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
// Settings/Maintenance are two-level menus (e.g. /admin/settings/general),
// registered as their own pattern rather than teaching Router about
// optional segments — it only matches a fixed number of path parts per
// pattern.
$router->get('/admin/{page}/{subpage}', $adminHandler);
$router->post('/admin', $adminHandler);
$router->post('/admin/{page}', $adminHandler);
$router->post('/admin/{page}/{subpage}', $adminHandler);

// REST API, versioned under /api/v1.
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

// "/{path*}" greedily matches any remaining path, so it must be the
// very last route registered or it would shadow every route above it.
$router->get('/{path*}', fn (array $params) => $site->pageByPath($params));
$router->post('/{path*}/comment', fn (array $params) => $site->submitPageComment($params));

$router->setNotFoundHandler(static fn () => $site->notFound());

// Sent last, after plugins and the theme's functions.php have loaded,
// so any add_filter('csp_directives', ...) they registered is honoured.
if ((bool) $config->get('csp_enabled', true)) {
    $cspDirectives = apply_filters('csp_directives', ContentSecurityPolicy::defaultDirectives());
    (new ContentSecurityPolicy($cspDirectives))->send();
}

// Fired after $kernel is fully built so a shortcode field needing
// database-backed choices has services to query — see
// include/shortcodes.php's own docblock.
do_action('register_shortcodes', $shortcodes, $kernel);

do_action('lumora_press_loaded');

return $kernel;