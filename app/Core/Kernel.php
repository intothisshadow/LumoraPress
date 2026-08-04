<?php

declare(strict_types=1);

namespace LumoraPress\Core;

use LumoraPress\Core\Cache\CacheManager;
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\Errors\ErrorHandler;
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Core\Http\MaintenanceGate;
use LumoraPress\Core\Http\Router;
use LumoraPress\Core\Mail\Mailer;
use LumoraPress\Core\Menus\MenuManager;
use LumoraPress\Core\Plugin\PluginRegistry;
use LumoraPress\Core\Security\ApiTokenService;
use LumoraPress\Core\Security\Auth;
use LumoraPress\Core\Security\LoginThrottle;
use LumoraPress\Core\Security\PasswordResetService;
use LumoraPress\Core\Security\PasswordResetThrottle;
use LumoraPress\Core\Security\RememberMeService;
use LumoraPress\Core\Security\SessionManager;
use LumoraPress\Core\Theme\ThemeOptions;
use LumoraPress\Core\Theme\ThemeRegistry;
use LumoraPress\Core\Theme\ThemeRenderer;
use LumoraPress\Core\Widgets\WidgetManager;
use LumoraPress\Services\AkismetClient;
use LumoraPress\Services\CategoryService;
use LumoraPress\Services\CommentService;
use LumoraPress\Services\ContentRenderer;
use LumoraPress\Services\FeedService;
use LumoraPress\Services\FolderService;
use LumoraPress\Services\GitHubReleaseProvider;
use LumoraPress\Services\MediaImportService;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\MediaStatsService;
use LumoraPress\Services\MediaUsageChecker;
use LumoraPress\Services\PageService;
use LumoraPress\Services\PluginInstaller;
use LumoraPress\Services\PostService;
use LumoraPress\Services\RedirectService;
use LumoraPress\Services\RevisionService;
use LumoraPress\Services\SearchService;
use LumoraPress\Services\TagService;
use LumoraPress\Services\ThemeFileEditor;
use LumoraPress\Services\ThemeInstaller;
use LumoraPress\Services\ThumbnailService;
use LumoraPress\Services\UpdateService;
use LumoraPress\Services\UserService;

/**
 * The composition root. One Kernel instance is built during bootstrap and
 * passed explicitly to entry scripts (index.php, admin/index.php) instead
 * of relying on global state.
 */
final class Kernel
{
    public function __construct(
        public readonly PressConfig $config,
        public readonly Database $database,
        public readonly ErrorHandler $errors,
        public readonly HookManager $hooks,
        public readonly PluginManager $plugins,
        public readonly ThemeRenderer $theme,
        public readonly WidgetManager $widgets,
        public readonly MenuManager $menus,
        public readonly SessionManager $sessions,
        public readonly UserService $users,
        public readonly Auth $auth,
        public readonly LoginThrottle $loginThrottle,
        public readonly RememberMeService $rememberMe,
        public readonly MediaService $media,
        public readonly PostService $posts,
        public readonly PageService $pages,
        public readonly CategoryService $categories,
        public readonly TagService $tags,
        public readonly CommentService $comments,
        public readonly FeedService $feeds,
        public readonly SearchService $search,
        public readonly UpdateService $updates,
        public readonly GitHubReleaseProvider $githubUpdates,
        public readonly Router $router,
        public readonly MaintenanceGate $maintenance,
        public readonly ThemeRegistry $themes,
        public readonly ThemeInstaller $themeInstaller,
        public readonly FolderService $folders,
        public readonly MediaUsageChecker $mediaUsage,
        public readonly ThumbnailService $thumbnails,
        public readonly MediaImportService $mediaImport,
        public readonly ApiTokenService $apiTokens,
        public readonly ContentRenderer $content,
        public readonly PluginRegistry $pluginRegistry,
        public readonly PluginInstaller $pluginInstaller,
        public readonly RevisionService $revisions,
        public readonly ThemeFileEditor $themeFileEditor,
        public readonly MediaStatsService $mediaStats,
        public readonly CacheManager $cache,
        public readonly RedirectService $redirects,
        public readonly AkismetClient $akismet,
        public readonly PasswordResetService $passwordResets,
        public readonly PasswordResetThrottle $passwordResetThrottle,
        public readonly Mailer $mailer,
        public readonly ThemeOptions $themeOptions,
    ) {
    }
}
