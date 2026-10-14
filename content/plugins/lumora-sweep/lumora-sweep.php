<?php

/**
 * The Lumora Sweep plugin's main file: plugin metadata header and bootstrap.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.15.0
 */

declare(strict_types=1);

/*
 * Plugin Name: Lumora Sweep
 * Plugin URI: https://lumorapress.org/plugins/lumora-sweep
 * Description: Database cleanup utility for excess revisions, old trashed posts/pages, old spam/trashed comments, and orphaned or duplicate post_meta rows, with an optional unused-categories/tags check. Manual, preview-before-delete, per category — a genuine maintenance convenience, not something core depends on.
 * Version: 0.1.0
 * Author: Lumora Press
 * Author URI: https://lumorapress.org
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Tags: maintenance, database, cleanup
 * Requires at least: 0.14.0
 * Requires PHP: 8.2
 */

namespace LumoraPress\Plugins\LumoraSweep;

require_once __DIR__ . '/src/SweepService.php';
require_once __DIR__ . '/src/AccountSweepService.php';
require_once __DIR__ . '/src/SweepReminderService.php';

// The Sweep tab itself is a gated tab on the existing Maintenance > Tools screen, which constructs
// its services from $kernel directly, the same as the Dummy Content plugin's own section there.
// Only the inbox reminder needs a hook.
register_notification_type(
    SweepReminderService::TYPE,
    'Clean-up reminder',
    'Open Sweep',
    '🧹',
    'manage_options',
    'Unused sign-up accounts are piling up and can be cleaned up in Sweep',
);

// Fires on every admin page load; the service caches its count for an hour, so this is cheap.
add_action('admin_loaded', static function (\LumoraPress\Models\User $user): void {
    if (!$user->can('manage_options')) {
        return;
    }

    $kernel = \LumoraPress\Core\ActiveKernel::instance();
    $prefix = (string) $kernel->config->get('table_prefix', 'lp_');

    try {
        (new SweepReminderService(
            $kernel->config,
            AccountSweepService::fromKernel($kernel),
            new \LumoraPress\Services\NotificationService($kernel->database, $prefix, $kernel->hooks),
            $kernel->users,
            $kernel->database,
            $prefix,
        ))->maybeRemind();
    } catch (\Throwable $exception) {
        // A reminder must never break the admin page it piggybacks on.
        error_log('[LumoraSweep] ' . $exception::class . ': ' . $exception->getMessage());
    }
});

add_filter('plugin_action_links_lumora-sweep', static fn (array $links): array => [
    ...$links,
    ['label' => 'Settings', 'url' => admin_url('maintenance/tools') . '?tab=sweep'],
]);
