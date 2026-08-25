<?php

/**
 * The installer front controller: walks a fresh install through requirements, database setup, and admin account creation.
 *
 * @package LumoraPress
 * @subpackage Installer
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

use LumoraPress\Core\Autoloader;
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\Database\DatabaseConnectionException;
use LumoraPress\Core\Database\Migrator;
use LumoraPress\Core\Errors\ErrorHandler;
use LumoraPress\Core\Http\SiteUrl;
use LumoraPress\Core\InstallerCleanup;
use LumoraPress\Core\PressConfig;
use LumoraPress\Core\RequirementsCheck;
use LumoraPress\Core\Security\ContentSecurityPolicy;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Security\SessionManager;
use LumoraPress\Models\UserRole;
use LumoraPress\Services\UserService;

$root = dirname(__DIR__);

require $root . '/app/Core/Autoloader.php';

/*
 * The install script may be running from the domain root
 * (https://example.com/install/) or from a subdirectory install
 * (https://example.com/lumorapress/install/). Every redirect and every
 * asset link the installer emits must be built from the script's actual
 * location rather than assuming root, or a subdirectory install 404s the
 * moment step 1 redirects.
 */
$installScriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'));
$installUrl = rtrim($installScriptDir, '/') . '/';
$baseUrl = rtrim(dirname($installScriptDir), '/') . '/';

$autoloader = new Autoloader();
$autoloader->addNamespace('LumoraPress', $root . '/app');
$autoloader->register();

/*
 * Must run after the autoloader is registered above — SiteUrl isn't
 * required directly anywhere in this file, so calling it any earlier
 * fatals with "Class not found" (silently, as a blank page, since the
 * installer hasn't set up its own error handler yet at this point either).
 */
$detectedSiteUrl = SiteUrl::detect(
    isHttps: !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    host: (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'),
    basePath: rtrim($baseUrl, '/'),
);

$errorHandler = new ErrorHandler($root . '/storage/logs', debug: false);
$errorHandler->register();

(new ContentSecurityPolicy())->send();

require $root . '/include/hooks.php';
require $root . '/include/helpers.php';

if (is_file($root . '/config/config.php')) {
    require __DIR__ . '/views/already-installed.php';
    exit;
}

$requirementProblems = (new RequirementsCheck($root))->check();

if ($requirementProblems !== []) {
    require __DIR__ . '/views/requirements-not-met.php';
    exit;
}

$sessions = new SessionManager(
    sessionPath: $root . '/storage/sessions',
    secureCookies: !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    sessionName: 'lumora_press_install',
);
$sessions->start();

/**
 * Generates a cryptographically random, predictable-prefix-free table
 * prefix (e.g. "lum_a8f3d1_") so a fresh install never defaults to the
 * guessable "lp_". The suggestion is kept in the install session so it
 * stays stable across re-renders (e.g. a validation error) instead of
 * changing on every page load.
 */
$generateTablePrefix = static function (): string {
    return 'lum_' . bin2hex(random_bytes(3)) . '_';
};

if (!isset($_SESSION['install_suggested_prefix'])) {
    $_SESSION['install_suggested_prefix'] = $generateTablePrefix();
}

$suggestedPrefix = $_SESSION['install_suggested_prefix'];

$errors = [];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST' && ($_POST['step'] ?? '') === '1') {
    $token = $_POST['csrf_token'] ?? null;

    if (!Csrf::verify('install_step1', is_string($token) ? $token : null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $dbHost = trim((string) ($_POST['db_host'] ?? ''));
        $dbPort = (int) ($_POST['db_port'] ?? 3306);
        $dbName = trim((string) ($_POST['db_name'] ?? ''));
        $dbUser = trim((string) ($_POST['db_user'] ?? ''));
        $dbPassword = (string) ($_POST['db_password'] ?? '');
        $tablePrefix = trim((string) ($_POST['table_prefix'] ?? $suggestedPrefix));

        if ($tablePrefix === '') {
            $tablePrefix = $suggestedPrefix;
        }

        if ($dbHost === '' || $dbName === '' || $dbUser === '') {
            $errors[] = 'Please fill in all required database fields.';
        } elseif (!preg_match('/^[A-Za-z0-9_]+$/', $tablePrefix)) {
            $errors[] = 'Table prefix may only contain letters, numbers, and underscores.';
        } else {
            try {
                Database::connect($dbHost, $dbName, $dbUser, $dbPassword, 'utf8mb4', $dbPort);

                $_SESSION['install_db'] = [
                    'db_host' => $dbHost,
                    'db_port' => $dbPort,
                    'db_name' => $dbName,
                    'db_user' => $dbUser,
                    'db_password' => $dbPassword,
                    'db_charset' => 'utf8mb4',
                    'table_prefix' => $tablePrefix,
                ];
                $_SESSION['install_step'] = 2;

                header('Location: ' . $installUrl);
                exit;
            } catch (DatabaseConnectionException) {
                $errors[] = 'Could not connect to the database with the details provided.';
            }
        }
    }
}

if ($method === 'POST' && ($_POST['step'] ?? '') === '2') {
    $token = $_POST['csrf_token'] ?? null;

    if (!Csrf::verify('install_step2', is_string($token) ? $token : null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!isset($_SESSION['install_db'])) {
        $errors[] = 'Please complete the database step first.';
        $_SESSION['install_step'] = 1;
    } else {
        $siteName = trim((string) ($_POST['site_name'] ?? ''));
        $siteUrl = rtrim(trim((string) ($_POST['site_url'] ?? '')), '/');
        $timezone = trim((string) ($_POST['timezone'] ?? 'UTC'));
        $locale = trim((string) ($_POST['locale'] ?? 'en'));
        $adminUsername = trim((string) ($_POST['admin_username'] ?? ''));
        $adminDisplayName = trim((string) ($_POST['admin_display_name'] ?? ''));
        $adminEmail = trim((string) ($_POST['admin_email'] ?? ''));
        $adminPassword = (string) ($_POST['admin_password'] ?? '');
        $adminPasswordConfirm = (string) ($_POST['admin_password_confirm'] ?? '');

        if ($siteName === '' || $siteUrl === '' || $adminUsername === '' || $adminDisplayName === '' || $adminEmail === '' || $adminPassword === '') {
            $errors[] = 'Please fill in all required fields.';
        } elseif (!filter_var($siteUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'Please enter a valid website URL.';
        } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } elseif (UserService::usernameMatchesDisplayName($adminUsername, $adminDisplayName)) {
            $errors[] = 'Username and Display Name must be different — Display Name is shown publicly on posts.';
        } elseif (UserService::isGuessableAdministratorUsername($adminUsername)) {
            $errors[] = 'That username is too easy to guess. Choose something less obvious than "admin".';
        } elseif (strlen($adminPassword) < 10) {
            $errors[] = 'Password must be at least 10 characters long.';
        } elseif ($adminPassword !== $adminPasswordConfirm) {
            $errors[] = 'Passwords do not match.';
        } elseif (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $errors[] = 'Please select a valid timezone.';
        } else {
            try {
                $dbConfig = $_SESSION['install_db'];

                $database = Database::connect(
                    $dbConfig['db_host'],
                    $dbConfig['db_name'],
                    $dbConfig['db_user'],
                    $dbConfig['db_password'],
                    $dbConfig['db_charset'],
                    $dbConfig['db_port'],
                );

                $migrator = new Migrator($database, __DIR__ . '/migrations', $dbConfig['table_prefix']);
                $migrator->migrate();

                $users = new UserService($database, $dbConfig['table_prefix']);

                if ($users->usernameOrEmailExists($adminUsername, $adminEmail)) {
                    throw new RuntimeException('That username or email is already in use.');
                }

                $users->create($adminUsername, $adminEmail, $adminPassword, UserRole::Administrator, $adminDisplayName);

                PressConfig::generate($root . '/config/config.php', [
                    'db_host' => $dbConfig['db_host'],
                    'db_port' => $dbConfig['db_port'],
                    'db_name' => $dbConfig['db_name'],
                    'db_user' => $dbConfig['db_user'],
                    'db_password' => $dbConfig['db_password'],
                    'db_charset' => $dbConfig['db_charset'],
                    'table_prefix' => $dbConfig['table_prefix'],
                    'secret_key' => bin2hex(random_bytes(32)),
                    'debug' => false,
                    'csp_enabled' => true,
                    'timezone' => $timezone,
                    'locale' => $locale,
                    'base_path' => rtrim($baseUrl, '/'),
                    'session_path' => '',
                ]);

                $config = new PressConfig($root . '/config/config.php');
                $config->bindDatabase($database);
                $config->setOption('site_name', $siteName);
                $config->setOption('site_url', $siteUrl);
                $config->setOption('timezone', $timezone);
                $config->setOption('locale', $locale);
                $config->setOption('active_theme', 'default');
                $config->setOption('active_plugins', '[]');

                unset($_SESSION['install_db'], $_SESSION['install_step'], $_SESSION['install_suggested_prefix']);
                $sessions->destroy();

                /*
                 * The success page is fully rendered into a string BEFORE
                 * any cleanup attempt (LP-004), since removing install/
                 * removes views/success.php (and this very script) too —
                 * safe to unlink on Unix-like filesystems while the
                 * process is still running against the already-open
                 * inode, but only once nothing else still needs to read
                 * it from disk. The placeholder comment in success.php is
                 * substituted afterwards so the page can report the
                 * outcome without needing to know it in advance.
                 */
                ob_start();
                require __DIR__ . '/views/success.php';
                $successHtml = (string) ob_get_clean();

                $installer = new InstallerCleanup();
                $cleanupMessage = $installer->remove(__DIR__)
                    ? '<p class="lp-alert lp-alert--success">The installer files have been removed automatically.</p>'
                    : '<p class="lp-alert lp-alert--error">Could not remove the installer files automatically. '
                        . 'For security, please delete the <code>install/</code> directory manually.</p>';

                echo str_replace('<!-- INSTALLER_CLEANUP_STATUS -->', $cleanupMessage, $successHtml);
                exit;
            } catch (Throwable $exception) {
                $errors[] = 'Installation failed: ' . $exception->getMessage();
            }
        }
    }
}

$step = (int) ($_SESSION['install_step'] ?? 1);

if ($step === 2 && !isset($_SESSION['install_db'])) {
    $step = 1;
}

require __DIR__ . "/views/step{$step}.php";
