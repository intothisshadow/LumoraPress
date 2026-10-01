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
use LumoraPress\Core\Theme\ThemeOptionType;
use LumoraPress\Services\MediaService;
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
    public function __construct(
        private readonly ThemeOptions $themeOptions,
        private readonly MediaService $media,
    ) {
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

        $errors = [];

        foreach ($this->themeOptions->fieldsForSection($sectionKey) as $field) {
            $postKey = 'opt_' . $field->key;

            if ($field->allowEmpty && ($post['reset_' . $field->key] ?? '') === '1') {
                $this->themeOptions->reset($field->key);

                continue;
            }

            $rawValue = $field->type === ThemeOptionType::Checkbox
                ? (isset($post[$postKey]) ? '1' : '0')
                : (string) ($post[$postKey] ?? '');

            if (!$this->themeOptions->set($field->key, $rawValue)) {
                $errors[] = $field->label;
            }
        }

        if ($errors !== []) {
            return AdminActionResult::error("Couldn't save: " . implode(', ', $errors) . ' — please check the value(s) and try again.');
        }

        return AdminActionResult::redirect($this->redirectTarget($sectionKey));
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

        $errors = [];

        foreach ($this->themeOptions->fieldsForSection('header') as $field) {
            $postKey = 'opt_' . $field->key;

            // A choice that wasn't submitted keeps its current value rather than failing validation.
            if ($field->type === ThemeOptionType::Select && !isset($post[$postKey])) {
                continue;
            }

            $rawValue = $field->type === ThemeOptionType::Checkbox
                ? (isset($post[$postKey]) ? '1' : '0')
                : (string) ($post[$postKey] ?? '');

            if (!$this->themeOptions->set($field->key, $rawValue)) {
                $errors[] = $field->label;
            }
        }

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
            'colors', 'typography', 'layout', 'post_display', 'featured_image' => 'body',
            default => 'theme',
        };

        return admin_url('appearance/customize') . '?tab=' . $tab . '&saved=1';
    }

    private function invalidRequest(): AdminActionResult
    {
        return AdminActionResult::error('Your session expired or the request could not be verified. Please try again.');
    }
}
