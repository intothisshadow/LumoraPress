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

            // Counted here — once per verified submission, regardless of
            // outcome — so an attacker can't dodge the IP-wide quota by
            // only ever probing addresses they expect not to exist. See
            // PasswordResetThrottle's docblock for why this exists: it
            // caps the volume available to exploit PasswordResetService's
            // own documented timing side-channel, without closing it.
            $kernel->passwordResetThrottle->recordAttempt($clientIp, $email === '' ? null : $email);

            // Honeypot + submission timing (LP-025's public-form pattern):
            // a bot signal here still produces the same generic "sent"
            // response as a real success — never reveal detection to a
            // bot, and never reveal via any other signal whether an email
            // is actually registered.
            $isHoneypotFilled = trim((string) ($_POST['reset_website'] ?? '')) !== '';
            $formTime = is_string($_POST['form_time'] ?? null) ? $_POST['form_time'] : null;
            $formTimeHmac = is_string($_POST['form_time_hmac'] ?? null) ? $_POST['form_time_hmac'] : null;

            if (!$isHoneypotFilled && FormTiming::verify($formTime, $formTimeHmac)) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                    $user = $kernel->users->findByEmail($email);

                    if ($user !== null) {
                        // See PasswordResetService's docblock: a found
                        // account does strictly more work here (DB insert
                        // + mail()) than a not-found one — a known, accepted
                        // timing side-channel, not fixed in this pass.
                        $resetToken = $kernel->passwordResets->issueToken($user->id);

                        if ($resetToken !== null) {
                            // home_url(), not admin_url(): the link is going
                        // into an email, where a root-relative URL has no
                        // "current page" for the recipient's mail client to
                        // resolve it against.
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

/*
 * LP-087: the sidebar's quick theme toggle (rendered in every
 * layout-header.php request via an empty-action self-submitting form)
 * is handled here, before routing to any specific view, so it works
 * identically from every admin screen without every view needing to
 * know about it. Redirects back to the exact URL the toggle was
 * clicked from — REQUEST_URI still holds the current page's own
 * page/subpage query string at this point, since routing hasn't
 * happened yet.
 */
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

/*
 * LP-026 "Warn about active users": the only source of "who's currently
 * active" data available (there's no DB-backed session table — see
 * SessionManager's docblock), stamped once per authenticated admin page
 * load rather than per-action, matching the coarse "recently active"
 * signal an update's pre-check actually needs.
 */
$kernel->users->touchLastActive($currentUser->id);

/*
 * Opportunistic, on every authenticated admin page load — cheap in the
 * overwhelming majority of calls (InstallPingService::maybeSendPing()
 * returns immediately unless the feature is enabled and the ~monthly
 * interval has elapsed). Wrapped defensively even though the service
 * already fails silently internally, so a future change there can never
 * turn into a broken admin panel.
 */
try {
    $kernel->installPing->maybeSendPing();
} catch (\Throwable) {
    // Never let this affect the admin page render.
}

$subpage = is_string($_GET['subpage'] ?? null) ? $_GET['subpage'] : null;

/*
 * LP-042/LP-043: Settings and Maintenance are two-level menus — each has
 * 'children' keyed the same way as top-level entries. Everything else
 * stays flat, unchanged from before this reorganization.
 */
// Icons (LP-053) are purely decorative — see layout-header.php's
// aria-hidden treatment — so they're plain emoji, no icon font/SVG sprite
// dependency, matching Lumora Gallery's admin sidebar.
/*
 * LPP-002: the Font Awesome plugin's settings page only appears under
 * Appearance while the plugin is active — same "hidden when inactive"
 * behavior a real WordPress-style plugin settings page has, and avoids a
 * dead menu entry pointing at a view that calls into a class the plugin
 * manager never required this request (see PluginManager::loadActive(),
 * called earlier in include/bootstrap.php). Mirrors admin/views/
 * plugins.php's own $readActivePlugins closure.
 */
$activePluginsRaw = $kernel->config->option('active_plugins', '[]');
$activePlugins = is_string($activePluginsRaw) ? (json_decode($activePluginsRaw, true) ?: []) : (array) $activePluginsRaw;
$fontAwesomeActive = in_array('font-awesome', $activePlugins, true);
/*
 * LPP-005: unlike Font Awesome above, Dummy Content has no menu entry of
 * its own — its generator lives as a gated section on the always-present
 * Maintenance > Tools screen (admin/views/maintenance/tools.php), the
 * same "hidden when inactive" reasoning applied at the section level
 * instead of the menu-entry level. $dummyContentActive is still computed
 * here (not inside tools.php) so it stays available to that view via
 * PHP's normal require-scope sharing, matching every other value this
 * file precomputes before requiring a view.
 */
$dummyContentActive = in_array('dummy-content', $activePlugins, true);
/*
 * LPP-004: unlike Dummy Content above, WordPress Importer's screen lives
 * at the Maintenance > Import menu entry, which already exists
 * unconditionally (see $menu below) — this only gates the *content* of
 * admin/views/maintenance/import.php, the same "hidden when inactive"
 * reasoning applied at the section level rather than the menu-entry
 * level, since removing the whole menu entry would also hide it from an
 * admin trying to find and activate the plugin in the first place.
 */
$wordPressImporterActive = in_array('wordpress-importer', $activePlugins, true);
/*
 * LPP-008: unlike Dummy Content/WordPress Importer above, Downloads
 * gets a real top-level menu entry of its own (mirroring Font
 * Awesome's own gated submenu-entry precedent, just at the top level
 * instead of nested under Appearance) — its admin screens are its
 * entire reason to exist, so hiding the whole entry while inactive (and
 * showing it once activated from Plugins) is the right shape, not a
 * gated section on an existing always-present screen.
 */
$downloadsActive = in_array('downloads', $activePlugins, true);
/*
 * LPP-003: mirrors $downloadsActive's exact reasoning immediately above
 * — Contact Forms' admin screens are its entire reason to exist, so it
 * gets a real top-level menu entry, gated the same way.
 */
$contactFormsActive = in_array('contact-forms', $activePlugins, true);
/*
 * LPP-001: mirrors $downloadsActive/$contactFormsActive's exact
 * reasoning immediately above — Lumora Shield's admin screens are its
 * entire reason to exist, so it gets a real top-level menu entry, gated
 * the same way.
 */
$lumoraShieldActive = in_array('lumora-shield', $activePlugins, true);
/*
 * LPP-014: mirrors $lumoraShieldActive's exact reasoning immediately
 * above — Visitor & Post View Statistics' admin screens are its entire
 * reason to exist, so it gets a real top-level menu entry, gated the
 * same way.
 */
$visitorStatsActive = in_array('visitor-stats', $activePlugins, true);
/*
 * LPP-015: mirrors $lumoraShieldActive/$visitorStatsActive's exact
 * reasoning immediately above — Lumora Gallery Shortcodes' admin screen
 * is its entire reason to exist, so it gets a real top-level menu entry,
 * gated the same way.
 */
$galleryShortcodesActive = in_array('lumora-gallery-shortcodes', $activePlugins, true);
/*
 * LPP-006: mirrors $fontAwesomeActive's exact reasoning — Emoji Picker's
 * settings screen only appears while the plugin is active, gated as a
 * nested child (like Font Awesome under Appearance) rather than a
 * top-level entry, since a single Settings screen is its entire admin
 * footprint.
 */
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
    ...($lumoraShieldActive ? [
        'lumora-shield' => [
            'label' => 'Lumora Shield',
            'icon' => '🛡️',
            'capability' => 'manage_options',
            'default_child' => 'settings',
            'children' => [
                'settings' => ['label' => 'Settings', 'icon' => '⚙️', 'capability' => 'manage_options'],
                'logs' => ['label' => 'Logs', 'icon' => '📋', 'capability' => 'manage_options'],
            ],
        ],
    ] : []),
    ...($visitorStatsActive ? [
        'visitor-stats' => [
            'label' => 'Visitor Stats',
            'icon' => '📈',
            'capability' => 'manage_options',
            'default_child' => 'settings',
            'children' => [
                'settings' => ['label' => 'Settings', 'icon' => '⚙️', 'capability' => 'manage_options'],
            ],
        ],
    ] : []),
    ...($galleryShortcodesActive ? [
        'lumora-gallery-shortcodes' => [
            'label' => 'Gallery Shortcodes',
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
    'plugins' => ['label' => 'Plugins', 'icon' => '🔌', 'capability' => 'manage_plugins'],
    'settings' => [
        'label' => 'Settings',
        'icon' => '⚙️',
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
    // No capability requirement (LP-021): every authenticated role,
    // including Subscriber, manages their own API tokens — the page
    // itself only ever operates on $currentUser->id, never another
    // user's tokens.
    'api-tokens' => ['label' => 'API Tokens', 'icon' => '🔑', 'capability' => null],
    // No capability requirement (LP-066/LP-067): every authenticated
    // role manages their own editor preference here, the same
    // "operates only on $currentUser->id" reasoning api-tokens above
    // already uses.
    'profile' => ['label' => 'My Profile', 'icon' => '🙍', 'capability' => null],
];

/*
 * LP-043: preserve bookmarked/linked URLs from before Settings/Maintenance
 * existed as parent menus — /admin/updates and /admin/tools used to be
 * complete pages on their own, not children of Maintenance. LP-054 adds
 * the same for /admin/categories and /admin/tags, from before Posts
 * gained All Posts/New Post/Categories/Tags children. A bare
 * /admin/pages (LP-009 gained All Pages/New Page children the same way)
 * needs no entry here — it has no legacy direct-subroute to preserve,
 * so the generic children redirect below already sends it to
 * 'default_child' just like a bare /admin/posts always has.
 */
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
