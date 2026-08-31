<?php

/**
 * The Contact Forms plugin's main file (LPP-003): plugin metadata header.
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
 * Description: Build simple contact forms from a fixed set of field types (Name, Email, Subject, Message, Text, Textarea, Checkbox, Select), embed them anywhere with the [contact_form id="1"] shortcode, and read submissions from a dedicated admin screen. Optional Akismet/reCAPTCHA/Turnstile spam protection, off by default.
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
require_once __DIR__ . '/src/ReCaptchaClient.php';
require_once __DIR__ . '/src/TurnstileClient.php';
require_once __DIR__ . '/src/ContactFormShortcode.php';
require_once __DIR__ . '/src/ContactFormSubmissionHandler.php';

/*
 * ContactFormSubmissionHandler is required above (not just referenced)
 * because include/bootstrap.php's own contact-forms-gated route
 * registration constructs it by fully-qualified class name — that
 * registration runs later in the same request, after PluginManager has
 * already required this file, so the class is guaranteed to exist by
 * then. There is no public-route-registration hook a plugin can call
 * itself (see docs/DEVELOPER-APIS.md's "no hook for a plugin to add its
 * own admin menu item or page" — the same limitation applies to public
 * routes: every route is hardcoded in bootstrap.php, itself built and
 * dispatched before any plugin's own code runs again this request).
 * Classic PHP theme templates in this codebase echo directly rather than
 * buffering the whole page, so a shortcode's content_html filter callback
 * (ContactFormShortcode, registered below) runs too late in the response
 * to safely redirect() after processing a POST — exactly the same
 * constraint that gives comment submission its own dedicated
 * bootstrap.php-registered route instead of handling it inline during
 * template rendering.
 *
 * ContactFormShortcode renders public-facing content, so — unlike the
 * admin screens under admin/views/contact-forms/, which construct
 * ContactFormService/ContactSubmissionService directly from $kernel's own
 * already-existing services, the same pattern the Downloads plugin's
 * admin screens use — it needs a real, always-on hook: the same
 * 'content_html' filter Font Awesome's [icon] shortcode and Downloads'
 * own [lumora_downloads] shortcode both use. It needs no Kernel services
 * at *registration* time (only when a page's content actually contains
 * `[contact_form]`, at which point it opens its own database connection
 * — see its own docblock), so it's safe to construct here at plugin-load
 * time.
 */
$contactFormShortcode = new ContactFormShortcode();

add_filter('content_html', static fn (string $html): string => $contactFormShortcode->renderShortcodes($html), 20);

/*
 * LPP-017: picker metadata for the editor toolbar's "Insert Shortcode"
 * button (LP-110) — purely additive, doesn't change how
 * [contact_form id="..."] itself renders (still the content_html filter
 * above). Needs a live list of this site's own contact forms to build
 * `id`'s choices, which requires $kernel->database and isn't available
 * yet at this point in the file — see include/shortcodes.php's own
 * docblock for why this hooks 'register_shortcodes' instead of calling
 * register_shortcode() directly here, mirroring downloads.php's
 * identical category_id-choices pattern.
 */
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
