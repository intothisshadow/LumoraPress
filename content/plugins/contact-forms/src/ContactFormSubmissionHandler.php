<?php

/**
 * Handles the actual POST to /contact-form/{id}/submit (LPP-003): validation, spam checks, persistence, and email.
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

/**
 * Constructed straight from $kernel's own already-existing components by
 * the route closure in include/bootstrap.php (this class is only ever
 * reachable while the plugin is active — see that file's own
 * contact-forms-gated route registration), the same "construct directly
 * from $kernel's services" pattern the Downloads plugin's admin screens
 * already use.
 *
 * Layering mirrors SiteController's existing comment-submission flow
 * exactly: CSRF -> honeypot (fail silently) -> FormTiming (fail silently)
 * -> IP flood guard -> required-field/email validation -> optional
 * reCAPTCHA/Turnstile (fail CLOSED — see those classes' own docblocks for
 * why this differs from Akismet) -> optional Akismet (fail OPEN, can only
 * mark is_spam, never block outright) -> persist -> email (skipped for a
 * spam-flagged submission) -> redirect.
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

        // Honeypot: a real visitor never fills this hidden field. Fail
        // silently (pretend success) rather than revealing detection to
        // the bot filling it in — mirrors the identical convention in
        // SiteController's own comment-submission flow.
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

        $data = $this->collectAndValidateFields($form, $redirectTarget, $id);

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

        // Same additive, can-only-push-toward-Spam shape as Akismet above
        // (and as core's own 'comment_is_spam' filter) — a no-op unless
        // something listens. The Lumora Shield plugin's Contact Form
        // Protection module uses this for content checks (link limits,
        // excessive uppercase/punctuation, hidden Unicode characters)
        // that this plugin has no built-in equivalent for on its own.
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
    private function collectAndValidateFields(ContactForm $form, string $redirectTarget, int $formId): array
    {
        $data = [];

        foreach ($form->fields as $field) {
            $raw = trim((string) ($_POST['field_' . $field->key] ?? ''));

            if ($field->type === ContactFieldType::Checkbox) {
                $raw = $raw !== '' ? 'Yes' : 'No';
            }

            $isBlank = $raw === '' || ($field->type === ContactFieldType::Checkbox && $raw === 'No');

            if ($field->required && $isBlank) {
                $this->redirectWithError($redirectTarget, $formId, 'validation');
            }

            if ($field->type === ContactFieldType::Email && $raw !== '' && filter_var($raw, FILTER_VALIDATE_EMAIL) === false) {
                $this->redirectWithError($redirectTarget, $formId, 'validation');
            }

            $data[$field->key] = $raw;
        }

        return $data;
    }

    /**
     * Akismet, when enabled, can only push a submission toward Spam —
     * never away from it — so this is strictly additive on top of
     * everything already validated above. A null/false result (disabled,
     * or Akismet unreachable/misconfigured) leaves $isSpam false, mirroring
     * SiteController's own comment-Akismet layering exactly.
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

        $this->mailer->send($form->recipientEmail, $subject, implode("\n", $lines));
    }

    /**
     * The redirect target for both success and error cases: the form's
     * own configured redirect_url if set, otherwise the page the
     * submission came from — validated same-origin first, since an
     * unvalidated Referer header is attacker-controlled input and must
     * never be used as an open redirect target.
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
