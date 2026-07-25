<?php

declare(strict_types=1);

use LumoraPress\Core\Database\Migrator;
use LumoraPress\Core\Security\Csrf;

/** @var \LumoraPress\Core\Kernel $kernel */
if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * Buffered so that a page view required below (e.g. views/posts.php)
 * can still call header()/http_response_code() — for its own
 * process-POST-then-redirect and per-record 403 handling — even though
 * views/layout-header.php's HTML is required and echoed before it runs.
 * Flushed automatically at script end.
 */
ob_start();

/*
 * Existing installs never re-run install/index.php, so a newly added
 * migration (e.g. LP-007's login_attempts table) would otherwise never
 * reach a site that installed before it existed. Applying pending
 * migrations here — rather than in include/bootstrap.php, which every
 * front-end page also goes through — keeps the public-facing hot path
 * free of the extra queries pending()/migrate() do (see CLAUDE.md's
 * Performance goals). Admin traffic is low-volume and already
 * authenticated-or-authenticating, so the cost of checking on every admin
 * page load (including this login page, which is exactly where
 * LoginThrottle's table needs to exist) is acceptable.
 *
 * Failures are caught rather than left to crash the whole admin area: if
 * migrations can't be applied (e.g. the DB user lacks CREATE TABLE), the
 * site should stay usable with whatever schema it already has rather than
 * lock administrators out entirely. LoginThrottle's own methods already
 * fail open when their table is missing, so this degrades gracefully.
 */
try {
    (new Migrator(
        $kernel->database,
        LUMORA_ROOT . '/install/migrations',
        (string) $kernel->config->get('table_prefix', 'lp_'),
    ))->migrate();
} catch (\Throwable $exception) {
    error_log('[admin] Failed to apply pending migrations: ' . $exception->getMessage());
}

$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'dashboard';

if ($page === 'login') {
    if ($kernel->auth->check()) {
        header('Location: ' . admin_url());
        exit;
    }

    $rememberedUser = $kernel->rememberMe->attemptFromCookie();

    if ($rememberedUser !== null) {
        $kernel->auth->login($rememberedUser);
        header('Location: ' . admin_url());
        exit;
    }

    $error = null;
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $secondsLocked = $kernel->loginThrottle->secondsUntilUnlocked($clientIp);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $token = $_POST['csrf_token'] ?? null;

        if ($secondsLocked > 0) {
            $error = sprintf(
                'Too many failed login attempts. Please try again in %d minute%s.',
                (int) ceil($secondsLocked / 60),
                ceil($secondsLocked / 60) === 1.0 ? '' : 's',
            );
        } elseif (!Csrf::verify('admin_login', is_string($token) ? $token : null)) {
            $error = 'Your session expired. Please try again.';
        } else {
            $user = $kernel->auth->attempt($username, $password);

            if ($user === null) {
                $kernel->loginThrottle->recordFailure($clientIp, $username === '' ? null : $username);
                $secondsLocked = $kernel->loginThrottle->secondsUntilUnlocked($clientIp);
                $error = $secondsLocked > 0
                    ? sprintf(
                        'Too many failed login attempts. Please try again in %d minute%s.',
                        (int) ceil($secondsLocked / 60),
                        ceil($secondsLocked / 60) === 1.0 ? '' : 's',
                    )
                    : 'Invalid username or password.';
            } else {
                $kernel->loginThrottle->clear($clientIp);

                if (($_POST['remember'] ?? '') === '1') {
                    $kernel->rememberMe->rememberUser($user->id);
                }

                header('Location: ' . admin_url());
                exit;
            }
        }
    }

    require __DIR__ . '/views/login.php';

    return;
}

if ($page === 'logout') {
    $kernel->auth->logout();
    $kernel->rememberMe->forgetCurrentCookie();
    header('Location: ' . admin_url('login'));
    exit;
}

if (!$kernel->auth->check()) {
    $rememberedUser = $kernel->rememberMe->attemptFromCookie();

    if ($rememberedUser !== null) {
        $kernel->auth->login($rememberedUser);
    } else {
        header('Location: ' . admin_url('login'));
        exit;
    }
}

$currentUser = $kernel->auth->user();

$subpage = is_string($_GET['subpage'] ?? null) ? $_GET['subpage'] : null;

/*
 * LP-042/LP-043: Settings and Maintenance are two-level menus — each has
 * 'children' keyed the same way as top-level entries. Everything else
 * stays flat, unchanged from before this reorganization.
 */
$menu = [
    'dashboard' => ['label' => 'Dashboard', 'capability' => null],
    'posts' => ['label' => 'Posts', 'capability' => 'edit_posts'],
    'categories' => ['label' => 'Categories', 'capability' => 'edit_posts'],
    'tags' => ['label' => 'Tags', 'capability' => 'edit_posts'],
    'media' => ['label' => 'Media', 'capability' => 'upload_files'],
    'pages' => ['label' => 'Pages', 'capability' => 'edit_posts'],
    'comments' => ['label' => 'Comments', 'capability' => 'moderate_comments'],
    'appearance' => ['label' => 'Appearance', 'capability' => 'manage_themes'],
    'plugins' => ['label' => 'Plugins', 'capability' => 'manage_plugins'],
    'settings' => [
        'label' => 'Settings',
        'capability' => 'manage_options',
        'default_child' => 'general',
        'children' => [
            'general' => ['label' => 'General', 'capability' => 'manage_options'],
            'media' => ['label' => 'Media', 'capability' => 'manage_options'],
            'cache' => ['label' => 'Cache', 'capability' => 'manage_options'],
            'maintenance-mode' => ['label' => 'Maintenance Mode', 'capability' => 'manage_options'],
            'security' => ['label' => 'Security', 'capability' => 'manage_options'],
        ],
    ],
    'maintenance' => [
        'label' => 'Maintenance',
        'capability' => 'manage_options',
        'default_child' => 'updates',
        'children' => [
            'updates' => ['label' => 'Updates', 'capability' => 'manage_options'],
            'import' => ['label' => 'Import', 'capability' => 'manage_options'],
            'export' => ['label' => 'Export', 'capability' => 'manage_options'],
            'tools' => ['label' => 'Tools', 'capability' => 'manage_options'],
            'system-information' => ['label' => 'System Information', 'capability' => 'manage_options'],
            'logs' => ['label' => 'Logs', 'capability' => 'manage_options'],
        ],
    ],
    'users' => ['label' => 'Users', 'capability' => 'manage_users'],
    // No capability requirement (LP-021): every authenticated role,
    // including Subscriber, manages their own API tokens — the page
    // itself only ever operates on $currentUser->id, never another
    // user's tokens.
    'api-tokens' => ['label' => 'API Tokens', 'capability' => null],
];

/*
 * LP-043: preserve bookmarked/linked URLs from before Settings/Maintenance
 * existed as parent menus — /admin/updates and /admin/tools used to be
 * complete pages on their own, not children of Maintenance.
 */
$legacyRedirects = [
    'updates' => 'maintenance/updates',
    'tools' => 'maintenance/tools',
];

if ($subpage === null && isset($legacyRedirects[$page])) {
    header('Location: ' . admin_url($legacyRedirects[$page]));
    exit;
}

if (!array_key_exists($page, $menu)) {
    $page = 'dashboard';
}

$menuEntry = $menu[$page];
$breadcrumbs = [['label' => 'Dashboard', 'url' => admin_url('dashboard')]];

if (isset($menuEntry['children'])) {
    if ($subpage === null || !array_key_exists($subpage, $menuEntry['children'])) {
        header('Location: ' . admin_url("{$page}/{$menuEntry['default_child']}"));
        exit;
    }

    $activeEntry = $menuEntry['children'][$subpage];
    $viewFile = __DIR__ . "/views/{$page}/{$subpage}.php";

    $breadcrumbs[] = ['label' => $menuEntry['label'], 'url' => admin_url("{$page}/{$menuEntry['default_child']}")];
    $breadcrumbs[] = ['label' => $activeEntry['label'], 'url' => null];
} else {
    $activeEntry = $menuEntry;
    $viewFile = __DIR__ . "/views/{$page}.php";

    if ($page !== 'dashboard') {
        $breadcrumbs[] = ['label' => $activeEntry['label'], 'url' => null];
    }
}

$requiredCapability = $activeEntry['capability'];

if ($requiredCapability !== null && !$currentUser->can($requiredCapability)) {
    http_response_code(403);
    require __DIR__ . '/views/forbidden.php';

    return;
}

require __DIR__ . '/views/layout-header.php';

require is_file($viewFile) ? $viewFile : __DIR__ . '/views/placeholder.php';

require __DIR__ . '/views/layout-footer.php';
