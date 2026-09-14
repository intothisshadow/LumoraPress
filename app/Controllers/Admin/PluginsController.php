<?php

/**
 * POST-handling logic for the admin Plugins screen.
 *
 * @package LumoraPress
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.15.0
 */

declare(strict_types=1);

namespace LumoraPress\Controllers\Admin;

use LumoraPress\Core\PressConfig;
use LumoraPress\Core\Plugin\PluginRegistry;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Services\PluginInstaller;
use Throwable;

/**
 * POST-handling logic for the admin Plugins screen, following the same
 * extract-to-controller pattern LP-082 established for Themes/Posts/
 * Pages/Categories (ThemesController et al.). The view stays a thin
 * wrapper: reads $_POST/$_FILES, calls a method here, and turns the
 * returned AdminActionResult into a redirect or an error.
 *
 * The Grid/List view-mode toggle (a pure fire-and-forget JSON sub-action,
 * no redirect/error shape) stays inline in the view, mirroring how
 * CategoriesController leaves the Category Image picker's own JSON query
 * inline — it carries none of the untested branching logic this
 * extraction closes.
 *
 * There is no `active_plugins` column/table — active state is a single
 * JSON-encoded option value, read fresh and rewritten whole by every
 * method that changes it (readActivePlugins()/writeActivePlugins()),
 * exactly like the pre-extraction view's own closures did. Unlike
 * PluginInfo::$isActive (a snapshot fixed when PluginRegistry was built),
 * this stays correct across an activate/deactivate loop within one
 * request — bulkPluginAction() reads once and mutates in memory rather
 * than re-reading per slug.
 */
final class PluginsController
{
    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginInstaller $pluginInstaller,
        private readonly PressConfig $config,
    ) {
    }

    /**
     * @param array<string, mixed> $post
     */
    public function activatePlugin(array $post, ?string $csrfToken): AdminActionResult
    {
        $origin = is_string($post['origin'] ?? null) ? $post['origin'] : '';
        $slug = trim((string) ($post['slug'] ?? ''));

        if (!Csrf::verify('activate_plugin_' . $origin . '_' . $slug, $csrfToken)) {
            return $this->invalidRequest();
        }

        $target = $this->plugins->infoFor($slug);

        if ($target === null) {
            return AdminActionResult::error('That plugin could not be found.');
        }

        if ($target->isDisabled) {
            return AdminActionResult::error('This plugin cannot be activated: it requires a newer PHP version than this server has.');
        }

        $active = $this->readActivePlugins();

        if (!in_array($slug, $active, true)) {
            $active[] = $slug;
            $this->writeActivePlugins($active);
        }

        return AdminActionResult::redirect(admin_url('plugins') . '?activated=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function deactivatePlugin(array $post, ?string $csrfToken): AdminActionResult
    {
        $origin = is_string($post['origin'] ?? null) ? $post['origin'] : '';
        $slug = trim((string) ($post['slug'] ?? ''));

        if (!Csrf::verify('deactivate_plugin_' . $origin . '_' . $slug, $csrfToken)) {
            return $this->invalidRequest();
        }

        $active = array_values(array_filter($this->readActivePlugins(), static fn (string $s): bool => $s !== $slug));
        $this->writeActivePlugins($active);

        return AdminActionResult::redirect(admin_url('plugins') . '?deactivated=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function deletePlugin(array $post, ?string $csrfToken): AdminActionResult
    {
        $origin = is_string($post['origin'] ?? null) ? $post['origin'] : '';
        $slug = trim((string) ($post['slug'] ?? ''));

        if (!Csrf::verify('delete_plugin_' . $origin . '_' . $slug, $csrfToken)) {
            return $this->invalidRequest();
        }

        $target = $this->plugins->infoFor($slug);

        if ($target === null) {
            return AdminActionResult::error('That plugin could not be found.');
        }

        if ($target->isActive) {
            return AdminActionResult::error('The plugin must be deactivated before it can be deleted.');
        }

        try {
            $this->pluginInstaller->delete($slug);
        } catch (Throwable $exception) {
            return AdminActionResult::error($exception->getMessage());
        }

        return AdminActionResult::redirect(admin_url('plugins') . '?deleted=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function bulkPluginAction(array $post, ?string $csrfToken): AdminActionResult
    {
        if (!Csrf::verify('bulk_plugin_action', $csrfToken)) {
            return $this->invalidRequest();
        }

        $bulkAction = is_string($post['bulk_action'] ?? null) ? $post['bulk_action'] : '';
        $slugs = array_values(array_unique(array_map('strval', (array) ($post['plugin_slugs'] ?? []))));

        if (!in_array($bulkAction, ['activate', 'deactivate', 'delete'], true)) {
            return AdminActionResult::error('Choose a bulk action to apply.');
        }

        if ($slugs === []) {
            return AdminActionResult::error('Select at least one plugin.');
        }

        return match ($bulkAction) {
            'activate' => $this->bulkActivate($slugs),
            'deactivate' => $this->bulkDeactivate($slugs),
            default => $this->bulkDelete($slugs),
        };
    }

    /**
     * @param array<string, mixed> $files
     */
    public function installPlugin(array $files, ?string $csrfToken): AdminActionResult
    {
        if (!Csrf::verify('install_plugin', $csrfToken)) {
            return $this->invalidRequest();
        }

        if (!isset($files['plugin_zip']) || $files['plugin_zip']['error'] === UPLOAD_ERR_NO_FILE) {
            return AdminActionResult::error('Please choose a ZIP file to upload.');
        }

        if ($files['plugin_zip']['error'] !== UPLOAD_ERR_OK) {
            return AdminActionResult::error('The file upload failed. Please try again.');
        }

        try {
            $token = $this->pluginInstaller->stage($files['plugin_zip']['tmp_name']);
        } catch (Throwable $exception) {
            return AdminActionResult::error($exception->getMessage());
        }

        return AdminActionResult::redirect(admin_url('plugins') . '?pending=' . urlencode($token));
    }

    /**
     * @param array<string, mixed> $post
     */
    public function confirmInstallPlugin(array $post, ?string $csrfToken): AdminActionResult
    {
        if (!Csrf::verify('confirm_install_plugin', $csrfToken)) {
            return $this->invalidRequest();
        }

        $token = (string) ($post['token'] ?? '');
        $replace = ($post['replace'] ?? '') === '1';

        try {
            $installed = $this->pluginInstaller->finalize($token, $replace);
        } catch (Throwable $exception) {
            return AdminActionResult::error($exception->getMessage());
        }

        if (($post['activate_now'] ?? '') === '1' && !$installed->isDisabled) {
            $active = $this->readActivePlugins();

            if (!in_array($installed->slug, $active, true)) {
                $active[] = $installed->slug;
                $this->writeActivePlugins($active);
            }
        }

        return AdminActionResult::redirect(admin_url('plugins') . '?installed=' . urlencode($installed->name));
    }

    /**
     * @param array<string, mixed> $post
     */
    public function cancelInstallPlugin(array $post, ?string $csrfToken): AdminActionResult
    {
        if (!Csrf::verify('cancel_install_plugin', $csrfToken)) {
            return $this->invalidRequest();
        }

        $token = (string) ($post['token'] ?? '');
        $this->pluginInstaller->discardStaged($token);

        return AdminActionResult::redirect(admin_url('plugins'));
    }

    /**
     * @param array<int, string> $slugs
     */
    private function bulkActivate(array $slugs): AdminActionResult
    {
        $active = $this->readActivePlugins();
        $activatedCount = 0;
        $skippedCount = 0;

        foreach ($slugs as $slug) {
            $target = $this->plugins->infoFor($slug);

            if ($target === null || $target->isDisabled || in_array($slug, $active, true)) {
                $skippedCount++;

                continue;
            }

            $active[] = $slug;
            $activatedCount++;
        }

        $this->writeActivePlugins($active);

        return AdminActionResult::redirect(admin_url('plugins') . '?bulk_activated=' . $activatedCount . '&bulk_activate_skipped=' . $skippedCount);
    }

    /**
     * @param array<int, string> $slugs
     */
    private function bulkDeactivate(array $slugs): AdminActionResult
    {
        $active = $this->readActivePlugins();
        $deactivatedCount = 0;
        $skippedCount = 0;

        foreach ($slugs as $slug) {
            if (!in_array($slug, $active, true)) {
                $skippedCount++;

                continue;
            }

            $active = array_values(array_filter($active, static fn (string $s): bool => $s !== $slug));
            $deactivatedCount++;
        }

        $this->writeActivePlugins($active);

        return AdminActionResult::redirect(admin_url('plugins') . '?bulk_deactivated=' . $deactivatedCount . '&bulk_deactivate_skipped=' . $skippedCount);
    }

    /**
     * @param array<int, string> $slugs
     */
    private function bulkDelete(array $slugs): AdminActionResult
    {
        $deletedCount = 0;
        $skippedCount = 0;

        foreach ($slugs as $slug) {
            $target = $this->plugins->infoFor($slug);

            if ($target === null || $target->isActive) {
                $skippedCount++;

                continue;
            }

            try {
                $this->pluginInstaller->delete($slug);
                $deletedCount++;
            } catch (Throwable) {
                $skippedCount++;
            }
        }

        return AdminActionResult::redirect(admin_url('plugins') . '?bulk_deleted=' . $deletedCount . '&bulk_skipped=' . $skippedCount);
    }

    /**
     * @return array<int, string>
     */
    private function readActivePlugins(): array
    {
        $value = $this->config->option('active_plugins', '[]');
        $decoded = is_string($value) ? (json_decode($value, true) ?: []) : (array) $value;

        return array_values(array_map('strval', $decoded));
    }

    /**
     * @param array<int, string> $slugs
     */
    private function writeActivePlugins(array $slugs): void
    {
        $this->config->setOption('active_plugins', json_encode(array_values($slugs)));
    }

    /**
     * A failed CSRF check gets an actual error message rather than
     * silently falling through to a normal re-render — the one behavior
     * change from the pre-extraction view, matching ThemesController's
     * own precedent (see its docblock).
     */
    private function invalidRequest(): AdminActionResult
    {
        return AdminActionResult::error('Your session expired or the request could not be verified. Please try again.');
    }
}
