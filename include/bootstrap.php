<?php

declare(strict_types=1);

use LumoraPress\Controllers\SiteController;
use LumoraPress\Core\Autoloader;
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\Errors\ErrorHandler;
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Core\Hooks\Hooks;
use LumoraPress\Core\Http\BasePath;
use LumoraPress\Core\Http\MaintenanceGate;
use LumoraPress\Core\Http\Router;
use LumoraPress\Core\Http\SiteUrl;
use LumoraPress\Core\Kernel;
use LumoraPress\Core\Menus\MenuManager;
use LumoraPress\Core\Menus\Menus;
use LumoraPress\Core\PluginManager;
use LumoraPress\Core\PressConfig;
use LumoraPress\Core\Security\Auth;
use LumoraPress\Core\Security\ContentSecurityPolicy;
use LumoraPress\Core\Security\LoginThrottle;
use LumoraPress\Core\Security\RememberMeService;
use LumoraPress\Core\Security\SessionManager;
use LumoraPress\Core\Theme\ActiveTheme;
use LumoraPress\Core\Theme\SiteBranding;
use LumoraPress\Core\Theme\ThemeRegistry;
use LumoraPress\Core\Theme\ThemeRenderer;
use LumoraPress\Core\Widgets\WidgetManager;
use LumoraPress\Core\Widgets\Widgets;
use LumoraPress\Services\CategoryService;
use LumoraPress\Services\CommentService;
use LumoraPress\Services\FeedService;
use LumoraPress\Services\FolderService;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\MediaUsageChecker;
use LumoraPress\Services\PageService;
use LumoraPress\Services\PostService;
use LumoraPress\Services\SearchService;
use LumoraPress\Services\TagService;
use LumoraPress\Services\ThemeInstaller;
use LumoraPress\Services\ThumbnailService;
use LumoraPress\Services\UpdateBackupService;
use LumoraPress\Services\UpdatePackageValidator;
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

$errorHandler = new ErrorHandler(LUMORA_ROOT . '/storage/logs', (bool) $config->get('debug', false));
$errorHandler->register();

date_default_timezone_set((string) $config->get('timezone', 'UTC'));

$database = Database::connect(
    host: (string) $config->get('db_host', '127.0.0.1'),
    database: (string) $config->get('db_name', ''),
    username: (string) $config->get('db_user', ''),
    password: (string) $config->get('db_password', ''),
    charset: (string) $config->get('db_charset', 'utf8mb4'),
    port: (int) $config->get('db_port', 3306),
);

$config->bindDatabase($database);

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

$sessions = new SessionManager(
    sessionPath: LUMORA_ROOT . '/storage/sessions',
    secureCookies: $secureCookies,
);
$sessions->start();

require LUMORA_ROOT . '/include/helpers.php';

$hooks = new HookManager();
Hooks::set($hooks);
require LUMORA_ROOT . '/include/hooks.php';

$tablePrefix = (string) $config->get('table_prefix', 'lp_');

$plugins = new PluginManager(LUMORA_ROOT . '/content/plugins');
$activePlugins = $config->option('active_plugins', '[]');
$activePlugins = is_string($activePlugins) ? (json_decode($activePlugins, true) ?: []) : (array) $activePlugins;
$plugins->loadActive($activePlugins);

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

$themes = new ThemeRegistry($themesPath, $themesUrl, $activeThemeSlug);
$themeInstaller = new ThemeInstaller($themesPath, $themes);

$users = new UserService($database, $tablePrefix);
$auth = new Auth($users, $sessions);
$loginThrottle = new LoginThrottle($database, $tablePrefix);
$rememberMe = new RememberMeService($database, $users, $tablePrefix, $secureCookies);

$media = new MediaService(
    database: $database,
    tablePrefix: $tablePrefix,
    uploadsPath: LUMORA_ROOT . '/content/uploads',
    uploadsUrl: BasePath::get() . '/content/uploads',
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
);

$posts = new PostService($database, $tablePrefix);
$pages = new PageService($database, $tablePrefix);
$categories = new CategoryService($database, $tablePrefix);
$tags = new TagService($database, $tablePrefix);
$comments = new CommentService($database, $tablePrefix);
$feeds = new FeedService($posts, $users, $config, $hooks);
$search = new SearchService($database, $tablePrefix, $config);
$folders = new FolderService($database, $tablePrefix);
$mediaUsage = new MediaUsageChecker($posts, $config, $hooks);
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

/*
 * The fixed set of paths (relative to LUMORA_ROOT) that make up the core
 * application, as distributed in an official release ZIP. Everything
 * else under LUMORA_ROOT — config/, content/uploads, content/plugins,
 * custom themes other than "default", and storage/ — is user data and is
 * never touched by a manual update.
 */
$updateCorePaths = [
    'app',
    'admin',
    'include',
    'install',
    'content/themes/default',
    'index.php',
    'version.php',
    '.htaccess',
    'README.md',
    'LICENSE.md',
];

$updateValidator = new UpdatePackageValidator(
    installRoot: LUMORA_ROOT,
    stagingRoot: LUMORA_ROOT . '/storage/updates/staging',
    corePaths: $updateCorePaths,
);

$updateBackups = new UpdateBackupService(
    database: $database,
    tablePrefix: $tablePrefix,
    installRoot: LUMORA_ROOT,
    backupsPath: LUMORA_ROOT . '/storage/backups',
    corePaths: $updateCorePaths,
);

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
);

$router = new Router();
$maintenance = new MaintenanceGate($config, $auth, $theme, $hooks);

$kernel = new Kernel(
    config: $config,
    database: $database,
    errors: $errorHandler,
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
    router: $router,
    maintenance: $maintenance,
    themes: $themes,
    themeInstaller: $themeInstaller,
    folders: $folders,
    mediaUsage: $mediaUsage,
    thumbnails: $thumbnails,
);

$site = new SiteController($theme, $posts, $pages, $categories, $tags, $comments, $auth, $config, $feeds, $search);

$router->get('/', fn (array $params) => $site->home($params));
$router->get('/post/{slug}', fn (array $params) => $site->singlePost($params));
$router->post('/post/{slug}/comment', fn (array $params) => $site->submitComment($params));
$router->get('/category/{slug}', fn (array $params) => $site->category($params));
$router->get('/tag/{slug}', fn (array $params) => $site->tag($params));
$router->get('/archive', fn (array $params) => $site->archive($params));
$router->get('/search', fn (array $params) => $site->search($params));
$router->get('/page/{slug}', fn (array $params) => $site->page($params));
$router->get('/feed', fn (array $params) => $site->feed($params));
$router->get('/feed/{format}', fn (array $params) => $site->feed($params));

$adminHandler = static function (array $params) use ($kernel): void {
    if (isset($params['page'])) {
        $_GET['page'] = $params['page'];
    }

    require LUMORA_ROOT . '/admin/index.php';
};

$router->get('/admin', $adminHandler);
$router->get('/admin/{page}', $adminHandler);
$router->post('/admin', $adminHandler);
$router->post('/admin/{page}', $adminHandler);

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
