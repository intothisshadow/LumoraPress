<?php

/**
 * The admin front controller: authenticates the request and dispatches to the matching admin view.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

use LumoraPress\Core\Database\Migrator;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Security\FormTiming;
use LumoraPress\Models\ThemePreference;

/** @var \LumoraPress\Core\Kernel $kernel */
if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Buffered so a required view can still call header()/http_response_code()
// for its own redirect/403 handling after layout-header.php has echoed.
ob_start();

/*
 * Applied here rather than in bootstrap.php so the public-facing hot path
 * stays free of the extra queries — admin traffic is low-volume. Failures
 * are caught so a broken migration never takes down the whole admin area.
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

if ($page === 'forgot-password') {
    if ($kernel->auth->check()) {
        header('Location: ' . admin_url());
        exit;
    }

    $sent = false;
    $error = null;
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $secondsLocked = $kernel->passwordResetThrottle->secondsUntilUnlocked($clientIp);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $token = $_POST['csrf_token'] ?? null;

        if ($secondsLocked > 0) {
            $error = sprintf(
                'Too many requests. Please try again in %d minute%s.',
                (int) ceil($secondsLocked / 60),
                ceil($secondsLocked / 60) === 1.0 ? '' : 's',
            );
        } elseif (!Csrf::verify('password_reset_request', is_string($token) ? $token : null)) {
            $error = 'Your session expired. Please try again.';
        } else {
            $email = trim((string) ($_POST['email'] ?? ''));

            // Counted regardless of outcome so an attacker can't dodge the
            // IP-wide quota by probing addresses expected not to exist.
            $kernel->passwordResetThrottle->recordAttempt($clientIp, $email === '' ? null : $email);

            // A bot signal here still produces the same generic "sent"
            // response as a real success — never reveal via any signal
            // whether an email is actually registered.
            $isHoneypotFilled = trim((string) ($_POST['reset_website'] ?? '')) !== '';
            $formTime = is_string($_POST['form_time'] ?? null) ? $_POST['form_time'] : null;
            $formTimeHmac = is_string($_POST['form_time_hmac'] ?? null) ? $_POST['form_time_hmac'] : null;

            if (!$isHoneypotFilled && FormTiming::verify($formTime, $formTimeHmac)) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                    $user = $kernel->users->findByEmail($email);

                    if ($user !== null) {
                        // A found account does strictly more work here (DB
                        // insert + mail()) than a not-found one — a known,
                        // accepted timing side-channel.
                        $resetToken = $kernel->passwordResets->issueToken($user->id);

                        if ($resetToken !== null) {
                            // home_url(), not admin_url(): this goes into an
                            // email, with no "current page" to resolve against.
                            $resetUrl = home_url('admin/reset-password') . '?token=' . urlencode($resetToken);
                            $body = "Someone requested a password reset for your Lumora Press account.\n\n"
                                . "Reset your password: {$resetUrl}\n\n"
                                . "This link expires in 1 hour. If you didn't request this, you can safely ignore this email.";

                            $kernel->mailer->send($user->email, 'Reset your Lumora Press password', $body);
                        }
                    }
                }
            }

            $sent = true;
        }
    }

    require __DIR__ . '/views/forgot-password.php';

    return;
}

if ($page === 'reset-password') {
    if ($kernel->auth->check()) {
        header('Location: ' . admin_url());
        exit;
    }

    $token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
    $tokenUserId = $token !== '' ? $kernel->passwordResets->findValidToken($token) : null;
    $error = null;

    if ($tokenUserId !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $csrfToken = $_POST['csrf_token'] ?? null;
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if (!Csrf::verify('password_reset_confirm', is_string($csrfToken) ? $csrfToken : null)) {
            $error = 'Your session expired. Please try again.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $passwordConfirm) {
            $error = 'Passwords do not match.';
        } else {
            $confirmedUserId = $kernel->passwordResets->consume($token);

            if ($confirmedUserId === null) {
                $tokenUserId = null;
                $error = 'This link has expired or was already used. Please request a new one.';
            } else {
                $kernel->users->changePassword($confirmedUserId, $password);
                $kernel->passwordResets->invalidateForUser($confirmedUserId);
                // A password change ends other persistent "Remember Me"
                // sessions too, not just the current one.
                $kernel->rememberMe->forgetUserTokens($confirmedUserId);

                header('Location: ' . admin_url('login') . '?reset=success');
                exit;
            }
        }
    }

    require __DIR__ . '/views/reset-password.php';

    return;
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

// Handled before routing so the toggle works identically from every admin
// screen. Redirects back to REQUEST_URI, still unrouted at this point.
if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && ($_POST['form'] ?? null) === 'quick_theme_toggle'
    && Csrf::verify('quick_theme_toggle', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)
) {
    $requestedThemePreference = ThemePreference::tryFrom((string) ($_POST['theme_preference'] ?? ''));

    if ($requestedThemePreference !== null) {
        $kernel->users->updateThemePreference($currentUser->id, $requestedThemePreference);
    }

    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? admin_url('dashboard')));
    exit;
}

// Coarse "recently active" signal, stamped once per page load since there's
// no DB-backed session table to derive it from.
$kernel->users->touchLastActive($currentUser->id);

// maybeSendPing() returns immediately unless enabled and due; wrapped
// defensively so a future change there can't break the admin panel.
try {
    $kernel->installPing->maybeSendPing();
} catch (\Throwable) {
    // Never let this affect the admin page render.
}

$subpage = is_string($_GET['subpage'] ?? null) ? $_GET['subpage'] : null;

// Settings and Maintenance are two-level menus — each has 'children' keyed
// the same way as top-level entries. Everything else stays flat.
// Icons are purely decorative (see layout-header.php's aria-hidden
// treatment), so plain emoji with no icon font/SVG dependency.
$activePluginsRaw = $kernel->config->option('active_plugins', '[]');
$activePlugins = is_string($activePluginsRaw) ? (json_decode($activePluginsRaw, true) ?: []) : (array) $activePluginsRaw;
// Font Awesome's settings page only appears under Appearance while active,
// avoiding a dead menu entry pointing at an unloaded plugin class.
$fontAwesomeActive = in_array('font-awesome', $activePlugins, true);
// Dummy Content has no menu entry of its own — it's a gated section on the
// always-present Maintenance > Tools screen, computed here for that view's
// require-scope.
$dummyContentActive = in_array('dummy-content', $activePlugins, true);
// WordPress Importer gates only the content of maintenance/import.php; the
// menu entry stays so an admin can find it to activate the plugin.
$wordPressImporterActive = in_array('wordpress-importer', $activePlugins, true);
// Downloads/Contact Forms/Visitor Stats/Gallery Shortcodes each get a real
// top-level menu entry — their admin screens are their entire reason to
// exist, so the whole entry hides while inactive. Lumora Shield has no
// top-level entry of its own (LP-153): its Settings/Logs screens live
// under Settings > Security and Maintenance > Logs instead.
$downloadsActive = in_array('downloads', $activePlugins, true);
$contactFormsActive = in_array('contact-forms', $activePlugins, true);
$visitorStatsActive = in_array('visitor-stats', $activePlugins, true);
$galleryShortcodesActive = in_array('lumora-gallery-shortcodes', $activePlugins, true);
// Emoji Picker's settings screen is gated as a nested child (like Font
// Awesome) since a single Settings screen is its entire admin footprint.
$emojiPickerActive = in_array('emoji-picker', $activePlugins, true);

$menu = [
    'dashboard' => ['label' => 'Dashboard', 'icon' => '📊', 'capability' => null],
    'posts' => [
        'label' => 'Posts',
        'icon' => '📝',
        'capability' => 'edit_posts',
        'default_child' => 'all-posts',
        'children' => [
            'all-posts' => ['label' => 'All Posts', 'icon' => '📋', 'capability' => 'edit_posts'],
            'new' => ['label' => 'New Post', 'icon' => '🆕', 'capability' => 'edit_posts'],
            'categories' => ['label' => 'Categories', 'icon' => '📁', 'capability' => 'edit_posts'],
            'tags' => ['label' => 'Tags', 'icon' => '🏷️', 'capability' => 'edit_posts'],
        ],
    ],
    'pages' => [
        'label' => 'Pages',
        'icon' => '📄',
        'capability' => 'edit_posts',
        'default_child' => 'all-pages',
        'children' => [
            'all-pages' => ['label' => 'All Pages', 'icon' => '📋', 'capability' => 'edit_posts'],
            'new' => ['label' => 'New Page', 'icon' => '🆕', 'capability' => 'edit_posts'],
        ],
    ],
    'media' => [
        'label' => 'Media Manager',
        'icon' => '🖼️',
        'capability' => 'upload_files',
        'default_child' => 'media',
        'children' => [
            'media' => ['label' => 'Media', 'icon' => '🖼️', 'capability' => 'upload_files'],
            'upload' => ['label' => 'Upload', 'icon' => '⬆️', 'capability' => 'upload_files'],
            'import' => ['label' => 'Import from Server', 'icon' => '📥', 'capability' => 'upload_files'],
            'thumbnails' => ['label' => 'Thumbnails', 'icon' => '🔲', 'capability' => 'upload_files'],
        ],
    ],
    ...($downloadsActive ? [
        'downloads' => [
            'label' => 'Downloads',
            'icon' => '⬇️',
            'capability' => 'upload_files',
            'default_child' => 'all-downloads',
            'children' => [
                'all-downloads' => ['label' => 'All Downloads', 'icon' => '📋', 'capability' => 'upload_files'],
                'add-new' => ['label' => 'Add New', 'icon' => '🆕', 'capability' => 'upload_files'],
                'categories' => ['label' => 'Categories', 'icon' => '📁', 'capability' => 'upload_files'],
                'shortcodes' => ['label' => 'Shortcodes', 'icon' => '📖', 'capability' => 'upload_files'],
            ],
        ],
    ] : []),
    ...($contactFormsActive ? [
        'contact-forms' => [
            'label' => 'Contact Forms',
            'icon' => '✉️',
            'capability' => 'manage_options',
            'default_child' => 'all-forms',
            'children' => [
                'all-forms' => ['label' => 'All Forms', 'icon' => '📋', 'capability' => 'manage_options'],
                'add-new' => ['label' => 'Add New', 'icon' => '🆕', 'capability' => 'manage_options'],
                'submissions' => ['label' => 'Submissions', 'icon' => '📬', 'capability' => 'manage_options'],
                'settings' => ['label' => 'Settings', 'icon' => '⚙️', 'capability' => 'manage_options'],
            ],
        ],
    ] : []),
    ...($visitorStatsActive ? [
        'visitor-stats' => [
            'label' => 'Visitor Stats',
            'icon' => '📈',
            'capability' => 'manage_options',
            'default_child' => 'stats',
            'children' => [
                'stats' => ['label' => 'Stats', 'icon' => '📈', 'capability' => 'manage_options'],
                'settings' => ['label' => 'Settings', 'icon' => '⚙️', 'capability' => 'manage_options'],
            ],
        ],
    ] : []),
    ...($galleryShortcodesActive ? [
        'lumora-gallery-shortcodes' => [
            'label' => 'Lumora Gallery',
            'icon' => '🖼️',
            'capability' => 'manage_options',
            'default_child' => 'settings',
            'children' => [
                'settings' => ['label' => 'Settings', 'icon' => '⚙️', 'capability' => 'manage_options'],
                'shortcodes' => ['label' => 'Shortcodes', 'icon' => '📖', 'capability' => 'manage_options'],
            ],
        ],
    ] : []),
    'comments' => ['label' => 'Comments', 'icon' => '💬', 'capability' => 'moderate_comments'],
    'appearance' => [
        'label' => 'Appearance',
        'icon' => '🎨',
        'capability' => 'manage_themes',
        'default_child' => 'themes',
        'children' => [
            'themes' => ['label' => 'Themes', 'icon' => '🖌️', 'capability' => 'manage_themes'],
            'customize' => ['label' => 'Customize', 'icon' => '🎛️', 'capability' => 'manage_themes'],
            'widgets' => ['label' => 'Widgets', 'icon' => '🧩', 'capability' => 'manage_themes'],
            'menus' => ['label' => 'Menus', 'icon' => '🧭', 'capability' => 'manage_themes'],
            'custom-css' => ['label' => 'Custom CSS', 'icon' => '🎨', 'capability' => 'manage_themes'],
            'reset' => ['label' => 'Reset Theme Options', 'icon' => '♻️', 'capability' => 'manage_themes'],
            'editor' => ['label' => 'Theme Editor', 'icon' => '💻', 'capability' => 'manage_themes'],
            ...($fontAwesomeActive ? ['font-awesome' => ['label' => 'Font Awesome', 'icon' => '🅰️', 'capability' => 'manage_themes']] : []),
        ],
    ],
    // LP-154: 🔌 read as small/washed-out next to colorful neighbors like
    // Appearance's 🎨 or Comments' 💬 in the collapsed/icon sidebar (the
    // only place a top-level item's icon is actually shown — see LP-056's
    // note on .lp-admin__nav-icon in admin.css) — 📦 is more visually
    // distinct there and still isn't reused by any other top-level or
    // child entry in this array.
    'plugins' => ['label' => 'Plugins', 'icon' => '📦', 'capability' => 'manage_plugins'],
    'settings' => [
        'label' => 'Settings',
        // LP-154: no U+FE0F variation selector, unlike every other ⚙️ in
        // this array (still used verbatim for child "Settings" pages
        // elsewhere — this change is this one top-level entry only).
        // U+2699 GEAR defaults to plain-text presentation without it, so
        // it inherits .lp-admin__nav-icon's ordinary text `color` instead
        // of rendering as a fixed-tone color-emoji bitmap immune to CSS —
        // admin.css's collapsed-sidebar override lightens that color for
        // this one icon specifically, since the default muted grey every
        // other icon uses still read as too easy to miss here.
        'icon' => '⚙',
        'capability' => 'manage_options',
        'default_child' => 'general',
        'children' => [
            'general' => ['label' => 'General', 'icon' => '🔧', 'capability' => 'manage_options'],
            'permalinks' => ['label' => 'Permalinks', 'icon' => '🔗', 'capability' => 'manage_options'],
            'reading' => ['label' => 'Reading', 'icon' => '📖', 'capability' => 'manage_options'],
            'discussion' => ['label' => 'Discussion', 'icon' => '💬', 'capability' => 'manage_options'],
            'media' => ['label' => 'Media', 'icon' => '🗂️', 'capability' => 'manage_options'],
            ...($emojiPickerActive ? ['writing' => ['label' => 'Writing', 'icon' => '✍️', 'capability' => 'manage_options']] : []),
            'privacy' => ['label' => 'Privacy', 'icon' => '🔏', 'capability' => 'manage_options'],
            'cache' => ['label' => 'Cache', 'icon' => '⚡', 'capability' => 'manage_options'],
            'redirects' => ['label' => 'Redirects', 'icon' => '↪️', 'capability' => 'manage_options'],
            'embeds' => ['label' => 'Embeds', 'icon' => '▶️', 'capability' => 'manage_options'],
            'maintenance-mode' => ['label' => 'Maintenance Mode', 'icon' => '🚧', 'capability' => 'manage_options'],
            'security' => ['label' => 'Security', 'icon' => '🔒', 'capability' => 'manage_options'],
        ],
    ],
    'maintenance' => [
        'label' => 'Maintenance',
        'icon' => '🧰',
        'capability' => 'manage_options',
        'default_child' => 'updates',
        'children' => [
            'updates' => ['label' => 'Updates', 'icon' => '🔔', 'capability' => 'manage_options'],
            'import' => ['label' => 'Import', 'icon' => '📥', 'capability' => 'manage_options'],
            'export' => ['label' => 'Export', 'icon' => '📤', 'capability' => 'manage_options'],
            'tools' => ['label' => 'Tools', 'icon' => '🪛', 'capability' => 'manage_options'],
            'system-information' => ['label' => 'System Information', 'icon' => '🖥️', 'capability' => 'manage_options'],
            'logs' => ['label' => 'Logs', 'icon' => '📋', 'capability' => 'manage_options'],
        ],
    ],
    'users' => ['label' => 'Users', 'icon' => '👥', 'capability' => 'manage_users'],
    // No capability requirement: every authenticated role manages their
    // own editor preference here.
    'profile' => ['label' => 'My Profile', 'icon' => '🙍', 'capability' => null],
];

// Preserves bookmarked/linked URLs from before these were children of
// Maintenance/Posts, when each was a complete page on its own.
$legacyRedirects = [
    'updates' => 'maintenance/updates',
    'tools' => 'maintenance/tools',
    'categories' => 'posts/categories',
    'tags' => 'posts/tags',
];

if ($subpage === null && isset($legacyRedirects[$page])) {
    header('Location: ' . admin_url($legacyRedirects[$page]));
    exit;
}

// Preserves bookmarked/linked URLs from before Lumora Shield's own
// top-level menu entry was folded into Settings > Security and
// Maintenance > Logs (LP-153) — unlike $legacyRedirects above, these are
// full page/subpage pairs rather than a bare top-level page rename.
$legacyRouteRedirects = [
    'lumora-shield/settings' => 'settings/security?tab=lumora-shield',
    'lumora-shield/logs' => 'maintenance/logs#enumeration-attempts',
];

if (isset($legacyRouteRedirects["{$page}/{$subpage}"])) {
    header('Location: ' . admin_url($legacyRouteRedirects["{$page}/{$subpage}"]));
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
