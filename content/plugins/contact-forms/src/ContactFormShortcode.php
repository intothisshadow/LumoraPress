<?php

/**
 * Renders the `[contact_form id="1"]` shortcode's GET-time markup.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.7.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\ContactForms;

use LumoraPress\Core\Database\Database;
use LumoraPress\Core\PressConfig;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Security\FormTiming;

/**
 * GET-time rendering only — the submission POST goes to a dedicated route
 * handled by ContactFormSubmissionHandler, since themes echo directly rather
 * than buffering the page, so a redirect from inside content_html is too late.
 * Runs before $kernel exists, so it opens its own Database connection on first use.
 */
final class ContactFormShortcode
{
    private const PATTERN = '/\[contact_form([^\]]*)\]/i';

    /**
     * Given together, or none — tests construct this with injected
     * (SQLite-fixture-backed) service instances so renderShortcodes()
     * never needs a real database connection; the plugin's own bootstrap
     * constructs this with no arguments.
     */
    public function __construct(
        private readonly ?ContactFormService $injectedForms = null,
        private readonly ?ReCaptchaClient $injectedRecaptcha = null,
        private readonly ?TurnstileClient $injectedTurnstile = null,
    ) {
    }

    public function renderShortcodes(string $html): string
    {
        if (!str_contains($html, '[contact_form')) {
            return $html;
        }

        $result = preg_replace_callback(
            self::PATTERN,
            fn (array $matches): string => $this->renderOne($this->parseAttributes($matches[1])),
            $html,
        );

        return $result ?? $html;
    }

    /**
     * @param array<string, string> $attributes
     */
    private function renderOne(array $attributes): string
    {
        $id = (int) ($attributes['id'] ?? 0);

        if ($id <= 0) {
            return '';
        }

        [$forms, $recaptcha, $turnstile] = $this->services();
        $form = $forms->findById($id);

        if ($form === null) {
            return '';
        }

        $hasFileUpload = $this->firstFieldOfType($form, ContactFieldType::FileUpload) !== null;
        $enctypeAttr = $hasFileUpload ? ' enctype="multipart/form-data"' : '';

        $html = '<form class="lp-contact-form" method="post" action="' . esc_url(home_url('contact-form/' . $id . '/submit')) . '"' . $enctypeAttr . '>';
        $html .= $this->renderFlashMessage($id, $form);
        $html .= Csrf::field('contact_form_submit_' . $id);
        $html .= FormTiming::field();

        // Honeypot: a real visitor never fills this — see the identical
        // convention/comment in SiteController's own comment-submission
        // flow (app/Controllers/SiteController.php).
        $html .= '<p class="lp-contact-form__honeypot" aria-hidden="true">'
            . '<label for="contact-website-' . $id . '">Website</label>'
            . '<input type="text" id="contact-website-' . $id . '" name="contact_website" tabindex="-1" autocomplete="off">'
            . '</p>';

        foreach ($form->fields as $field) {
            $html .= $this->renderField($id, $field);
        }

        if ($recaptcha->isEnabled()) {
            $html .= '<p class="lp-field lp-contact-form__captcha">'
                . '<div class="g-recaptcha" data-sitekey="' . esc_attr($recaptcha->siteKey()) . '"></div>'
                . '</p>';
            $html .= '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
        }

        if ($turnstile->isEnabled()) {
            $html .= '<p class="lp-field lp-contact-form__captcha">'
                . '<div class="cf-turnstile" data-sitekey="' . esc_attr($turnstile->siteKey()) . '"></div>'
                . '</p>';
            $html .= '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
        }

        $html .= '<button type="submit" class="lp-contact-form__submit lp-button lp-button--primary">Send</button>';
        $html .= '</form>';

        return $html;
    }

    private function firstFieldOfType(ContactForm $form, ContactFieldType $type): ?ContactFormField
    {
        foreach ($form->fields as $field) {
            if ($field->type === $type) {
                return $field;
            }
        }

        return null;
    }

    private function renderField(int $formId, ContactFormField $field): string
    {
        $inputName = 'field_' . $field->key;
        $inputId = 'contact-' . $formId . '-' . $field->key;
        $requiredAttr = $field->required ? ' required' : '';
        $requiredMark = $field->required ? ' <span class="lp-contact-form__required" aria-hidden="true">*</span>' : '';

        $html = '<p class="lp-field">';
        $html .= '<label for="' . esc_attr($inputId) . '">' . esc_html($field->label) . $requiredMark . '</label>';

        if ($field->type->isMultiline()) {
            $html .= '<textarea id="' . esc_attr($inputId) . '" name="' . esc_attr($inputName) . '" rows="5"' . $requiredAttr . '></textarea>';
        } elseif ($field->type === ContactFieldType::Checkbox) {
            // Wrapped in its own <label> rather than using the shared
            // <label for="..."> above, matching the conventional checkbox
            // pattern; the label text is duplicated but harmless for
            // screen readers.
            $html .= '<label class="lp-field--checkbox"><input type="checkbox" id="' . esc_attr($inputId) . '" name="' . esc_attr($inputName) . '" value="1"' . $requiredAttr . '> ' . esc_html($field->label) . '</label>';
        } elseif ($field->type === ContactFieldType::Select) {
            $html .= '<select id="' . esc_attr($inputId) . '" name="' . esc_attr($inputName) . '"' . $requiredAttr . '>';
            $html .= '<option value="">' . esc_html('— Select —') . '</option>';

            foreach ($field->options as $option) {
                $html .= '<option value="' . esc_attr($option) . '">' . esc_html($option) . '</option>';
            }

            $html .= '</select>';
        } elseif ($field->type === ContactFieldType::FileUpload) {
            $html .= '<input type="file" id="' . esc_attr($inputId) . '" name="' . esc_attr($inputName) . '"' . $requiredAttr . '>';
        } else {
            $html .= '<input type="' . esc_attr($field->type->inputType()) . '" id="' . esc_attr($inputId) . '" name="' . esc_attr($inputName) . '"' . $requiredAttr . '>';
        }

        $html .= '</p>';

        return $html;
    }

    private function renderFlashMessage(int $formId, ContactForm $form): string
    {
        $sentId = (int) ($_GET['contact_form_sent'] ?? 0);

        if ($sentId === $formId) {
            return '<p class="lp-alert lp-alert--success">' . esc_html($form->successMessage) . '</p>';
        }

        $errorParam = is_string($_GET['contact_form_error'] ?? null) ? $_GET['contact_form_error'] : '';
        [$errorFormId, $reason] = array_pad(explode(':', $errorParam, 2), 2, '');

        if ((int) $errorFormId === $formId && $formId > 0) {
            return '<p class="lp-alert lp-alert--error">' . esc_html($this->errorMessage($reason)) . '</p>';
        }

        return '';
    }

    private function errorMessage(string $reason): string
    {
        return match ($reason) {
            'rate_limited' => 'You have submitted this form recently. Please wait a moment and try again.',
            'captcha' => 'Please complete the verification challenge and try again.',
            'expired' => 'Your session expired. Please try again.',
            default => 'Please check your entries and try again.',
        };
    }

    /**
     * @param string $rawAttributes e.g. ` id="1"`
     * @return array<string, string>
     */
    private function parseAttributes(string $rawAttributes): array
    {
        $attributes = [];

        if (preg_match_all('/([a-zA-Z_]+)="([^"]*)"/', $rawAttributes, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attributes[$match[1]] = $match[2];
            }
        }

        return $attributes;
    }

    /**
     * @return array{0: ContactFormService, 1: ReCaptchaClient, 2: TurnstileClient}
     */
    private function services(): array
    {
        if ($this->injectedForms !== null && $this->injectedRecaptcha !== null && $this->injectedTurnstile !== null) {
            return [$this->injectedForms, $this->injectedRecaptcha, $this->injectedTurnstile];
        }

        $configFile = require LUMORA_ROOT . '/config/config.php';
        $database = Database::connect(
            host: (string) $configFile['db_host'],
            database: (string) $configFile['db_name'],
            username: (string) $configFile['db_user'],
            password: (string) $configFile['db_password'],
            port: (int) ($configFile['db_port'] ?? 3306),
        );
        $tablePrefix = (string) $configFile['table_prefix'];

        $config = new PressConfig(LUMORA_ROOT . '/config/config.php');
        $config->bindDatabase($database);

        $forms = new ContactFormService($database, $tablePrefix);
        $recaptcha = new ReCaptchaClient($config);
        $turnstile = new TurnstileClient($config);

        return [$forms, $recaptcha, $turnstile];
    }
}
