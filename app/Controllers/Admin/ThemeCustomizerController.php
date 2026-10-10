<?php

/**
 * POST-handling logic for the admin Appearance &rsaquo; Customize and Reset Theme Options screens.
 *
 * @package LumoraPress
 * @subpackage Controllers
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.8.0
 */

declare(strict_types=1);

namespace LumoraPress\Controllers\Admin;

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Theme\ThemeOptions;
use InvalidArgumentException;
use LumoraPress\Services\MediaService;
use LumoraPress\Services\ThemeSettingsPortability;
use Throwable;

/**
 * POST handling for the Customize/Reset Theme Options screens. Admin
 * views stay thin, reading $_POST/$_FILES and turning the returned
 * AdminActionResult into a redirect or an inline error.
 *
 * Every CSRF action name is scoped per-section: every form actually
 * rendered on the Customize screen needs its own unique action name.
 */
final class ThemeCustomizerController
{
    public const PREVIEW_CSRF_ACTION = 'theme_options_preview';

    public const PREVIEW_SESSION_KEY = 'lp_options_preview';

    public const EXPORT_CSRF_ACTION = 'theme_settings_export';

    public const IMPORT_CSRF_ACTION = 'theme_settings_import';

    private const PREVIEW_LIFETIME_SECONDS = 1800;

    private readonly ThemeSettingsPortability $portability;

    public function __construct(
        private readonly ThemeOptions $themeOptions,
        private readonly MediaService $media,
    ) {
        $this->portability = new ThemeSettingsPortability($themeOptions);
    }

    /**
     * The active theme's settings as a downloadable file.
     *
     * @return array{filename: string, body: string}|null Null when the CSRF check fails.
     */
    public function exportSettings(?string $csrfToken): ?array
    {
        if (!Csrf::verify(self::EXPORT_CSRF_ACTION, $csrfToken)) {
            return null;
        }

        return ['filename' => $this->portability->filename(), 'body' => $this->portability->export()];
    }

    /**
     * Replaces the active theme's settings with an uploaded export.
     *
     * @param array<string, mixed> $files
     */
    public function importSettings(array $files, ?string $csrfToken): AdminActionResult
    {
        if (!Csrf::verify(self::IMPORT_CSRF_ACTION, $csrfToken)) {
            return $this->invalidRequest();
        }

        $file = $files['settings_file'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return AdminActionResult::error('Please choose a settings file to import.');
        }

        if ($file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)) {
            return AdminActionResult::error('The file upload failed. Please try again.');
        }

        $json = file_get_contents($file['tmp_name'], false, null, 0, ThemeSettingsPortability::MAX_BYTES + 1);

        if ($json === false) {
            return AdminActionResult::error('The file could not be read.');
        }

        try {
            $result = $this->portability->import($json);
        } catch (InvalidArgumentException $exception) {
            return AdminActionResult::error($exception->getMessage());
        }

        $query = '?tab=body&imported=' . $result['imported'];

        if ($result['skipped'] !== []) {
            $query .= '&skipped=' . rawurlencode(implode(', ', array_slice($result['skipped'], 0, 10)));
        }

        return AdminActionResult::redirect(admin_url('appearance/customize') . $query);
    }

    /**
     * Saves or resets every field in one registered section (Colors,
     * Typography, Layout, Post Display, Welcome Message, Footer).
     *
     * @param array<string, mixed> $post
     */
    public function saveSection(string $sectionKey, array $post, ?string $csrfToken): AdminActionResult
    {
        $csrfAction = 'theme_options_' . $sectionKey;

        if (!Csrf::verify($csrfAction, $csrfToken)) {
            return $this->invalidRequest();
        }

        if (($post['submit_action'] ?? 'save') === 'reset') {
            $this->themeOptions->resetSection($sectionKey);

            return AdminActionResult::redirect($this->redirectTarget($sectionKey));
        }

        $draft = $this->themeOptions->draftFromInput($sectionKey, $post);
        $this->themeOptions->applyDraft($draft);

        if ($draft['errors'] !== []) {
            return AdminActionResult::error("Couldn't save: " . implode(', ', $draft['errors']) . ' — please check the value(s) and try again.');
        }

        return AdminActionResult::redirect($this->redirectTarget($sectionKey));
    }

    /**
     * Stores a section's unsaved form values for a front-end preview. Nothing is written to the
     * saved options: the draft lives in the session, expires, and is only read by a request that
     * presents its token. The CSRF token is single-use, so the response carries a fresh one for
     * the next click.
     *
     * @param array<string, mixed> $post
     * @return array{status: int, body: array<string, mixed>}
     */
    public function previewSection(string $sectionKey, array $post, ?string $csrfToken): array
    {
        if (!Csrf::verify(self::PREVIEW_CSRF_ACTION, $csrfToken)) {
            return ['status' => 403, 'body' => ['error' => 'Your session expired. Reload the page and try again.', 'csrfToken' => Csrf::token(self::PREVIEW_CSRF_ACTION)]];
        }

        if (!isset($this->themeOptions->sections()[$sectionKey])) {
            return ['status' => 404, 'body' => ['error' => 'That section could not be found.', 'csrfToken' => Csrf::token(self::PREVIEW_CSRF_ACTION)]];
        }

        $draft = $this->themeOptions->draftFromInput($sectionKey, $post);

        if ($draft['errors'] !== []) {
            return ['status' => 422, 'body' => ['error' => 'Check the value(s): ' . implode(', ', $draft['errors']) . '.', 'csrfToken' => Csrf::token(self::PREVIEW_CSRF_ACTION)]];
        }

        $token = bin2hex(random_bytes(16));
        $_SESSION[self::PREVIEW_SESSION_KEY] = [
            'token' => $token,
            'theme' => $this->themeOptions->themeSlug(),
            'set' => $draft['set'],
            'reset' => $draft['reset'],
            'expires' => time() + self::PREVIEW_LIFETIME_SECONDS,
        ];

        return ['status' => 200, 'body' => [
            'url' => site_url('') . '?lp_preview_theme=' . rawurlencode($this->themeOptions->themeSlug()) . '&lp_preview_options=' . $token,
            'csrfToken' => Csrf::token(self::PREVIEW_CSRF_ACTION),
        ]];
    }

    /**
     * The Header tab combines two plain ThemeOptionFields with a bespoke
     * header-image upload/remove, so it needs its own method rather than
     * saveSection()'s plain-field loop.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    public function saveHeader(array $post, array $files, int $currentUserId, ?string $csrfToken): AdminActionResult
    {
        if (!Csrf::verify('theme_options_header', $csrfToken)) {
            return $this->invalidRequest();
        }

        if (($post['submit_action'] ?? 'save') === 'reset') {
            $this->themeOptions->resetSection('header');

            return AdminActionResult::redirect($this->redirectTarget('header'));
        }

        $draft = $this->themeOptions->draftFromInput('header', $post);
        $this->themeOptions->applyDraft($draft);
        $errors = $draft['errors'];

        // The Header tab posts the images still in its list (and says so with
        // header_images_submitted, so an emptied list clears them); a caller
        // that doesn't keeps the current images and only adds to them.
        $ids = isset($post['header_images_submitted'])
            ? (is_array($post['header_image_ids'] ?? null) ? $post['header_image_ids'] : [])
            : $this->themeOptions->headerImageMediaIds();

        if (($post['remove_header_image'] ?? '') === '1') {
            $ids = [];
        } else {
            foreach ($this->uploadedHeaderFiles($files) as $file) {
                try {
                    $uploaded = $this->media->upload($file, $currentUserId);
                    $ids[] = (int) $uploaded['id'];
                } catch (Throwable $exception) {
                    $errors[] = 'Header image (' . $exception->getMessage() . ')';
                }
            }
        }

        $this->themeOptions->setHeaderImageMediaIds($ids);

        if ($errors !== []) {
            return AdminActionResult::error("Couldn't save: " . implode(', ', $errors) . ' — please check the value(s) and try again.');
        }

        return AdminActionResult::redirect($this->redirectTarget('header'));
    }

    /**
     * Every file chosen in the Header tab's upload field, as the single-file
     * arrays MediaService::upload() takes. `header_images` is the multiple-file
     * field; `header_image` is the older single one.
     *
     * @param array<string, mixed> $files
     * @return array<int, array<string, mixed>>
     */
    private function uploadedHeaderFiles(array $files): array
    {
        $uploads = [];

        if (isset($files['header_image']) && is_array($files['header_image']) && ($files['header_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $uploads[] = $files['header_image'];
        }

        $multiple = $files['header_images'] ?? null;

        if (is_array($multiple) && is_array($multiple['name'] ?? null)) {
            foreach (array_keys($multiple['name']) as $index) {
                if (($multiple['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                $uploads[] = [
                    'name' => $multiple['name'][$index],
                    'type' => $multiple['type'][$index] ?? '',
                    'tmp_name' => $multiple['tmp_name'][$index] ?? '',
                    'error' => $multiple['error'][$index],
                    'size' => $multiple['size'][$index] ?? 0,
                ];
            }
        }

        return $uploads;
    }

    public function resetAll(?string $csrfToken): AdminActionResult
    {
        if (!Csrf::verify('theme_options_reset_all', $csrfToken)) {
            return $this->invalidRequest();
        }

        $this->themeOptions->resetAll();

        return AdminActionResult::redirect(admin_url('appearance/customize') . '?tab=header&saved=1');
    }

    private function redirectTarget(string $sectionKey): string
    {
        // Built-in sections live in the first tabs; anything a theme registered has its own.
        $tab = match ($sectionKey) {
            'header' => 'header',
            'welcome_message' => 'welcome_message',
            'footer' => 'footer',
            'colors', 'colors_dark', 'typography', 'layout', 'post_display', 'post_meta', 'page_elements', 'featured_image', 'images' => 'body',
            default => 'theme',
        };

        return admin_url('appearance/customize') . '?tab=' . $tab . '&saved=1';
    }

    private function invalidRequest(): AdminActionResult
    {
        return AdminActionResult::error('Your session expired or the request could not be verified. Please try again.');
    }
}
