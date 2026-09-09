<?php

/**
 * Handles the actual POST to /contact-form/{id}/submit: validation, spam checks, persistence, and email.
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
use LumoraPress\Core\Mail\Mailer;
use LumoraPress\Core\PressConfig;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Security\FormTiming;
use LumoraPress\Services\AkismetClient;
use RuntimeException;

/**
 * Validation order: CSRF -> honeypot -> FormTiming -> IP flood guard ->
 * field validation -> reCAPTCHA/Turnstile (fail closed) -> Akismet (fail
 * open, can only flag as spam) -> persist -> email -> redirect.
 */
final class ContactFormSubmissionHandler
{
    private const RATE_LIMIT_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly Mailer $mailer,
        private readonly AkismetClient $akismet,
        private readonly PressConfig $config,
    ) {
    }

    /**
     * @param array<string, string> $params Route params — {id}.
     */
    public function handle(array $params): void
    {
        $forms = new ContactFormService($this->database, $this->tablePrefix);
        $submissions = new ContactSubmissionService($this->database, $this->tablePrefix);

        $id = (int) ($params['id'] ?? 0);
        $form = $forms->findById($id);
        $referer = is_string($_SERVER['HTTP_REFERER'] ?? null) ? $_SERVER['HTTP_REFERER'] : null;

        if ($form === null) {
            header('Location: ' . home_url(''));
            exit;
        }

        $redirectTarget = $this->safeRedirectTarget($referer, $form->redirectUrl);
        $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

        if (!Csrf::verify('contact_form_submit_' . $id, $token)) {
            $this->redirectWithError($redirectTarget, $id, 'expired');
        }

        // Honeypot: fail silently rather than revealing detection to the bot.
        if (trim((string) ($_POST['contact_website'] ?? '')) !== '') {
            $this->redirectWithSuccess($redirectTarget, $id);
        }

        $formTime = is_string($_POST['form_time'] ?? null) ? $_POST['form_time'] : null;
        $formTimeHmac = is_string($_POST['form_time_hmac'] ?? null) ? $_POST['form_time_hmac'] : null;

        if (!FormTiming::verify($formTime, $formTimeHmac)) {
            $this->redirectWithSuccess($redirectTarget, $id);
        }

        $ipAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $userAgent = is_string($_SERVER['HTTP_USER_AGENT'] ?? null) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

        if ($submissions->recentSubmissionFromIpExists($ipAddress, self::RATE_LIMIT_WINDOW_SECONDS)) {
            $this->redirectWithError($redirectTarget, $id, 'rate_limited');
        }

        $uploads = new ContactFormUploadService(LUMORA_ROOT . '/storage/contact-form-uploads');
        $data = $this->collectAndValidateFields($form, $redirectTarget, $id, $uploads);

        $recaptcha = new ReCaptchaClient($this->config);

        if ($recaptcha->isEnabled()) {
            $captchaToken = is_string($_POST['g-recaptcha-response'] ?? null) ? $_POST['g-recaptcha-response'] : '';

            if (!$recaptcha->verify($captchaToken, $ipAddress)) {
                $this->redirectWithError($redirectTarget, $id, 'captcha');
            }
        }

        $turnstile = new TurnstileClient($this->config);

        if ($turnstile->isEnabled()) {
            $captchaToken = is_string($_POST['cf-turnstile-response'] ?? null) ? $_POST['cf-turnstile-response'] : '';

            if (!$turnstile->verify($captchaToken, $ipAddress)) {
                $this->redirectWithError($redirectTarget, $id, 'captcha');
            }
        }

        $isSpam = $this->checkAkismet($form, $data, $ipAddress, $userAgent, $referer);

        // Lets other plugins add their own spam checks; a no-op unless something listens.
        $isSpam = $isSpam || apply_filters('contact_form_is_spam', false, $data, $ipAddress);

        $submissions->create($id, $data, $ipAddress, $referer, $isSpam);

        if (!$isSpam) {
            $this->sendNotification($form, $data);
        }

        $this->redirectWithSuccess($redirectTarget, $id);
    }

    /**
     * @return array<string, string>
     */
    private function collectAndValidateFields(ContactForm $form, string $redirectTarget, int $formId, ContactFormUploadService $uploads): array
    {
        $data = [];
        $storedUploads = [];

        foreach ($form->fields as $field) {
            if ($field->type === ContactFieldType::FileUpload) {
                $data[$field->key] = $this->collectUploadField($field, $formId, $redirectTarget, $uploads, $storedUploads);

                continue;
            }

            $raw = trim((string) ($_POST['field_' . $field->key] ?? ''));

            if ($field->type === ContactFieldType::Checkbox) {
                $raw = $raw !== '' ? 'Yes' : 'No';
            }

            $isBlank = $raw === '' || ($field->type === ContactFieldType::Checkbox && $raw === 'No');

            if ($field->required && $isBlank) {
                $this->cleanupUploads($uploads, $formId, $storedUploads);
                $this->redirectWithError($redirectTarget, $formId, 'validation');
            }

            if ($field->type === ContactFieldType::Email && $raw !== '' && filter_var($raw, FILTER_VALIDATE_EMAIL) === false) {
                $this->cleanupUploads($uploads, $formId, $storedUploads);
                $this->redirectWithError($redirectTarget, $formId, 'validation');
            }

            $data[$field->key] = $raw;
        }

        return $data;
    }

    /**
     * @param array<int, string> $storedUploads Accumulates every filename
     *     stored so far this request, by reference, so a later field's
     *     validation failure can clean up an earlier field's already-stored
     *     file instead of leaking it on disk with no submission to point to.
     */
    private function collectUploadField(ContactFormField $field, int $formId, string $redirectTarget, ContactFormUploadService $uploads, array &$storedUploads): string
    {
        $file = $_FILES['field_' . $field->key] ?? null;
        $hasFile = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if (!$hasFile) {
            if ($field->required) {
                $this->cleanupUploads($uploads, $formId, $storedUploads);
                $this->redirectWithError($redirectTarget, $formId, 'validation');
            }

            return '';
        }

        try {
            /** @var array{name: string, type: string, tmp_name: string, error: int, size: int} $file */
            $storedName = $uploads->store($formId, $file);
        } catch (RuntimeException) {
            $this->cleanupUploads($uploads, $formId, $storedUploads);
            $this->redirectWithError($redirectTarget, $formId, 'validation');
        }

        $storedUploads[] = $storedName;

        return $storedName;
    }

    /**
     * @param array<int, string> $storedNames
     */
    private function cleanupUploads(ContactFormUploadService $uploads, int $formId, array $storedNames): void
    {
        foreach ($storedNames as $storedName) {
            $uploads->delete($formId, $storedName);
        }
    }

    /**
     * Akismet can only push toward Spam, never away — disabled or unreachable leaves $isSpam false.
     *
     * @param array<string, string> $data
     */
    private function checkAkismet(ContactForm $form, array $data, string $ipAddress, ?string $userAgent, ?string $referer): bool
    {
        if ($this->config->option('contact_forms_use_akismet', '0') !== '1' || !$this->akismet->isEnabled()) {
            return false;
        }

        $emailField = $form->fields === [] ? null : $this->firstFieldOfType($form, ContactFieldType::Email);
        $nameField = $this->firstFieldOfType($form, ContactFieldType::Name);

        $result = $this->akismet->checkComment([
            'comment_type' => 'contact-form',
            'comment_author' => $nameField !== null ? ($data[$nameField->key] ?? '') : 'Anonymous',
            'comment_author_email' => $emailField !== null ? ($data[$emailField->key] ?? '') : '',
            'comment_author_url' => null,
            'comment_content' => implode("\n", $data),
            'user_ip' => $ipAddress,
            'user_agent' => $userAgent,
            'referrer' => $referer,
            'permalink' => $referer ?? home_url(''),
        ]);

        return $result === true;
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

    /**
     * @param array<string, string> $data
     */
    private function sendNotification(ContactForm $form, array $data): void
    {
        $subject = 'New submission: ' . $form->title;
        $lines = [];

        foreach ($form->fields as $field) {
            $lines[] = $field->label . ': ' . ($data[$field->key] ?? '');
        }

        // Lets the recipient hit "Reply" and land in the submitter's inbox
        // directly, rather than replying to the site's own From address.
        // NativeMailer re-validates this before adding the header, so an
        // absent or malformed Email field just means no Reply-To, not a
        // rejected notification.
        $emailField = $this->firstFieldOfType($form, ContactFieldType::Email);
        $replyTo = $emailField !== null ? ($data[$emailField->key] ?? null) : null;

        $this->mailer->send($form->recipientEmail, $subject, implode("\n", $lines), $replyTo !== '' ? $replyTo : null);
    }

    /**
     * Falls back to the Referer, validated same-origin first — an
     * unvalidated Referer is attacker-controlled and must never be an open redirect target.
     */
    private function safeRedirectTarget(?string $referer, ?string $formRedirectUrl): string
    {
        if ($formRedirectUrl !== null && $formRedirectUrl !== '') {
            return $formRedirectUrl;
        }

        if ($referer !== null && str_starts_with($referer, home_url(''))) {
            return $referer;
        }

        return home_url('');
    }

    private function redirectWithSuccess(string $target, int $formId): never
    {
        header('Location: ' . $this->appendQuery($target, 'contact_form_sent=' . $formId));
        exit;
    }

    private function redirectWithError(string $target, int $formId, string $reason): never
    {
        header('Location: ' . $this->appendQuery($target, 'contact_form_error=' . $formId . ':' . $reason));
        exit;
    }

    private function appendQuery(string $url, string $query): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . $query;
    }
}
