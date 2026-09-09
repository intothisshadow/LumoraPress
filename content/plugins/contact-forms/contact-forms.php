<?php

/**
 * The Contact Forms plugin's main file: plugin metadata header.
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

/*
 * Plugin Name: Contact Forms
 * Plugin URI: https://lumorapress.org/plugins/contact-forms
 * Description: Build simple contact forms from a fixed set of field types (Name, Email, Subject, Message, Text, Textarea, Checkbox, Select, File Upload), embed them anywhere with the [contact_form id="1"] shortcode, and read submissions from a dedicated admin screen. Optional Akismet/reCAPTCHA/Turnstile spam protection, off by default.
 * Version: 0.1.0
 * Author: Lumora Press
 * Author URI: https://lumorapress.org
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Tags: contact form, forms, spam protection
 * Requires at least: 0.7.0
 * Requires PHP: 8.2
 */

namespace LumoraPress\Plugins\ContactForms;

use LumoraPress\Core\Kernel;
use LumoraPress\Core\Shortcodes\ShortcodeField;
use LumoraPress\Core\Shortcodes\ShortcodeFieldType;

require_once __DIR__ . '/src/ContactFieldType.php';
require_once __DIR__ . '/src/ContactFormField.php';
require_once __DIR__ . '/src/ContactForm.php';
require_once __DIR__ . '/src/ContactFormService.php';
require_once __DIR__ . '/src/ContactSubmission.php';
require_once __DIR__ . '/src/ContactSubmissionService.php';
require_once __DIR__ . '/src/ContactFormUploadService.php';
require_once __DIR__ . '/src/ReCaptchaClient.php';
require_once __DIR__ . '/src/TurnstileClient.php';
require_once __DIR__ . '/src/ContactFormShortcode.php';
require_once __DIR__ . '/src/ContactFormSubmissionHandler.php';

// No hook exists for a plugin to register its own public route, and a content_html
// filter runs too late to safely redirect() after a POST — submissions need this
// dedicated route (constructed by class name in bootstrap.php) instead.
$contactFormShortcode = new ContactFormShortcode();

add_filter('content_html', static fn (string $html): string => $contactFormShortcode->renderShortcodes($html), 20);

// Choices need a live form list ($kernel->database isn't available yet here),
// so this hooks 'register_shortcodes' rather than calling register_shortcode() directly.
add_action('register_shortcodes', static function (mixed $registry, Kernel $kernel): void {
    $formsService = new ContactFormService($kernel->database, (string) $kernel->config->get('table_prefix', 'lp_'));
    $formChoices = [];

    foreach ($formsService->listAll() as $form) {
        $formChoices[(string) $form->id] = $form->title;
    }

    register_shortcode('contact_form', 'Contact Form', [
        new ShortcodeField('id', 'Form', ShortcodeFieldType::Select, required: true, choices: $formChoices),
    ]);
});
