<?php

/**
 * POST-handling logic for the admin Appearance &rsaquo; Themes screen.
 *
 * @package LumoraPress
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Controllers\Admin;

use LumoraPress\Core\PressConfig;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Theme\ThemeInfo;
use LumoraPress\Core\Theme\ThemeRegistry;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\ThemeInstaller;

/**
 * Extracted from admin/views/appearance/themes.php (LP-082) — the first
 * admin view to move its POST-handling business logic (CSRF verification,
 * validation, and the actual theme/config/media mutations) out of a
 * template file and into a testable class. The view itself becomes a thin
 * wrapper: it reads $_POST/$_FILES, calls one of these methods, and turns
 * the returned AdminActionResult into either a redirect() call or an
 * inline $error string. See DECISIONS.md's LP-082 entry for why methods
 * here return a result instead of redirecting themselves.
 *
 * Every CSRF action name below reproduces the exact per-slug scoping the
 * view already used (LP-081) — a bare 'activate_theme'/'delete_theme'
 * name shared across every theme card's form would let one theme's
 * request invalidate another's token, since Csrf::field()/verify() are
 * keyed purely by action name.
 */
final class ThemesController
{
    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly ThemeInstaller $themeInstaller,
        private readonly PressConfig $config,
        private readonly MediaService $media,
    ) {
    }

    /**
     * @param array<string, mixed> $post
     */
    public function activateTheme(array $post, ?string $csrfToken): AdminActionResult
    {
        $slug = trim((string) ($post['slug'] ?? ''));

        if (!Csrf::verify('activate_theme_' . $slug, $csrfToken)) {
            return $this->invalidRequest();
        }

        $known = array_filter($this->themes->discover(), static fn (ThemeInfo $info): bool => $info->slug === $slug);

        if ($known === []) {
            return AdminActionResult::error('That theme could not be found.');
        }

        $this->config->setOption('active_theme', $slug);

        return AdminActionResult::redirect(admin_url('appearance/themes') . '?saved=1');
    }

    /**
     * @param array<string, mixed> $post
     */
    public function deleteTheme(array $post, ?string $csrfToken): AdminActionResult
    {
        $slug = trim((string) ($post['slug'] ?? ''));

        if (!Csrf::verify('delete_theme_' . $slug, $csrfToken)) {
            return $this->invalidRequest();
        }

        $target = null;

        foreach ($this->themes->discover() as $info) {
            if ($info->slug === $slug) {
                $target = $info;

                break;
            }
        }

        if ($target === null) {
            return AdminActionResult::error('That theme could not be found.');
        }

        if ($target->isActive) {
            return AdminActionResult::error('The active theme cannot be deleted. Activate a different theme first.');
        }

        try {
            $this->themeInstaller->delete($slug);
        } catch (\Throwable $exception) {
            return AdminActionResult::error($exception->getMessage());
        }

        return AdminActionResult::redirect(admin_url('appearance/themes') . '?deleted=1');
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function updateTheme(array $post, array $files, ?string $csrfToken): AdminActionResult
    {
        $slug = trim((string) ($post['slug'] ?? ''));

        if (!Csrf::verify('update_theme_' . $slug, $csrfToken)) {
            return $this->invalidRequest();
        }

        if (!isset($files['theme_zip']) || $files['theme_zip']['error'] === UPLOAD_ERR_NO_FILE) {
            return AdminActionResult::error('Please choose a ZIP file to upload.');
        }

        if ($files['theme_zip']['error'] !== UPLOAD_ERR_OK) {
            return AdminActionResult::error('The file upload failed. Please try again.');
        }

        try {
            $updated = $this->themeInstaller->update($files['theme_zip']['tmp_name'], $slug);
        } catch (\Throwable $exception) {
            return AdminActionResult::error($exception->getMessage());
        }

        return AdminActionResult::redirect(admin_url('appearance/themes') . '?updated=' . urlencode($updated->name));
    }

    /**
     * @param array<string, mixed> $files
     */
    public function installTheme(array $files, ?string $csrfToken): AdminActionResult
    {
        if (!Csrf::verify('install_theme', $csrfToken)) {
            return $this->invalidRequest();
        }

        if (!isset($files['theme_zip']) || $files['theme_zip']['error'] === UPLOAD_ERR_NO_FILE) {
            return AdminActionResult::error('Please choose a ZIP file to upload.');
        }

        if ($files['theme_zip']['error'] !== UPLOAD_ERR_OK) {
            return AdminActionResult::error('The file upload failed. Please try again.');
        }

        try {
            $installed = $this->themeInstaller->install($files['theme_zip']['tmp_name']);
        } catch (\Throwable $exception) {
            return AdminActionResult::error($exception->getMessage());
        }

        return AdminActionResult::redirect(admin_url('appearance/themes') . '?installed=' . urlencode($installed->name));
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function saveBranding(array $post, array $files, int $currentUserId, ?string $csrfToken): AdminActionResult
    {
        if (!Csrf::verify('branding', $csrfToken)) {
            return $this->invalidRequest();
        }

        $this->config->setOption('site_name', trim((string) ($post['site_name'] ?? '')));

        $error = null;

        if (($post['remove_logo'] ?? '') === '1') {
            $this->config->setOption('site_logo_media_id', '');
        } elseif (isset($files['logo']) && $files['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
            try {
                $uploaded = $this->media->upload($files['logo'], $currentUserId);
                $this->config->setOption('site_logo_media_id', (string) $uploaded['id']);
            } catch (\Throwable $exception) {
                $error = 'Logo upload failed: ' . $exception->getMessage();
            }
        }

        if (($post['remove_favicon'] ?? '') === '1') {
            $this->config->setOption('favicon_media_id', '');
        } elseif (isset($files['favicon']) && $files['favicon']['error'] !== UPLOAD_ERR_NO_FILE) {
            try {
                $uploaded = $this->media->upload($files['favicon'], $currentUserId);
                $this->config->setOption('favicon_media_id', (string) $uploaded['id']);
            } catch (\Throwable $exception) {
                $error = 'Favicon upload failed: ' . $exception->getMessage();
            }
        }

        if ($error !== null) {
            return AdminActionResult::error($error);
        }

        return AdminActionResult::redirect(admin_url('appearance/themes') . '?saved=1');
    }

    /**
     * The view previously left a failed CSRF check as a silent no-op (the
     * request just fell through to a normal re-render with no feedback)
     * — a side effect of the old if/elseif chain requiring both the form
     * name *and* Csrf::verify() to match before anything ran. Now that
     * every branch always executes and returns a result, a failed check
     * gets an actual message instead of vanishing silently.
     */
    private function invalidRequest(): AdminActionResult
    {
        return AdminActionResult::error('Your session expired or the request could not be verified. Please try again.');
    }
}
