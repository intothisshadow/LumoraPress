<?php

/**
 * The admin Contact Forms > Add New screen: add a new form, or edit an existing one via ?id= (LPP-003).
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.7.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Plugins\ContactForms\ContactFieldType;
use LumoraPress\Plugins\ContactForms\ContactFormService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// See all-forms.php's identical comment: this view is only ever reachable
// while the Contact Forms plugin is active, so its classes are guaranteed
// to already be loaded.
$tablePrefix = (string) $kernel->config->get('table_prefix', 'lp_');
$forms = new ContactFormService($kernel->database, $tablePrefix);

$editingId = (int) ($_GET['id'] ?? 0);
$editingForm = $editingId > 0 ? $forms->findById($editingId) : null;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
    $csrfAction = $editingForm !== null ? 'contact_form_save_' . $editingForm->id : 'contact_form_save_new';

    if (Csrf::verify($csrfAction, $token)) {
        $title = trim((string) ($_POST['title'] ?? ''));
        $recipientEmail = trim((string) ($_POST['recipient_email'] ?? ''));
        $successMessage = trim((string) ($_POST['success_message'] ?? '')) ?: 'Thanks for your message!';
        $redirectUrl = trim((string) ($_POST['redirect_url'] ?? '')) ?: null;

        $fields = $forms->buildFieldsFromSubmission(
            (array) ($_POST['field_labels'] ?? []),
            (array) ($_POST['field_types'] ?? []),
            (array) ($_POST['field_required'] ?? []),
            (array) ($_POST['field_options'] ?? []),
        );

        if ($title === '') {
            $error = 'A form title is required.';
        } elseif ($recipientEmail === '' || filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            $error = 'A valid recipient email is required.';
        } elseif ($fields === []) {
            $error = 'Add at least one field.';
        } else {
            $saved = $editingForm !== null
                ? $forms->update($editingForm->id, $title, $recipientEmail, $fields, $successMessage, $redirectUrl)
                : $forms->create($title, $recipientEmail, $fields, $successMessage, $redirectUrl);

            header('Location: ' . admin_url('contact-forms/add-new') . '?id=' . $saved->id . '&saved=1');
            exit;
        }
    }
}

// The always-present blank trailing row (LPP-003) mirrors Custom Fields'
// identical convention (admin/views/posts/new.php) — without JavaScript,
// filling it in and saving still adds a field; the JS enhancement
// (contact-form-fields.js) only makes adding/removing/reordering rows
// nicer without a full page reload per change.
$fieldRows = $editingForm !== null
    ? array_map(static fn ($field): array => ['label' => $field->label, 'type' => $field->type->value, 'required' => $field->required, 'options' => implode(', ', $field->options)], $editingForm->fields)
    : [];
$fieldRows[] = ['label' => '', 'type' => ContactFieldType::Text->value, 'required' => false, 'options' => ''];

$csrfAction = $editingForm !== null ? 'contact_form_save_' . $editingForm->id : 'contact_form_save_new';
?>
<h1 class="lp-admin__title"><?= $editingForm !== null ? 'Edit Form' : 'Add New Form' ?></h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <form method="post" action="<?= esc_url(admin_url('contact-forms/add-new') . ($editingForm !== null ? '?id=' . $editingForm->id : '')) ?>">
        <?= Csrf::field($csrfAction) ?>

        <p class="lp-field">
            <label for="contact-form-title">Title</label>
            <input type="text" id="contact-form-title" name="title" value="<?= esc_attr($editingForm->title ?? '') ?>" required>
        </p>

        <p class="lp-field">
            <label for="contact-form-recipient">Recipient email</label>
            <input type="email" id="contact-form-recipient" name="recipient_email" value="<?= esc_attr($editingForm->recipientEmail ?? (string) $kernel->config->option('admin_email', '')) ?>" required>
        </p>

        <p class="lp-field">
            <label for="contact-form-success-message">Success message</label>
            <input type="text" id="contact-form-success-message" name="success_message" value="<?= esc_attr($editingForm->successMessage ?? 'Thanks for your message!') ?>">
        </p>

        <p class="lp-field">
            <label for="contact-form-redirect-url">Redirect URL (optional)</label>
            <input type="text" id="contact-form-redirect-url" name="redirect_url" value="<?= esc_attr($editingForm->redirectUrl ?? '') ?>">
            <span class="lp-field__hint">Leave blank to redirect back to the page the form was submitted from.</span>
        </p>

        <h2>Fields</h2>
        <?php if ($editingForm === null): ?>
            <p>
                <button type="button" class="lp-button lp-button--secondary" data-lp-contact-form-fields-template>Use Contact Form Template</button>
                <span class="lp-field__hint">Replaces the fields below with a ready-made Subject, Name, Email, and Message form, all required.</span>
            </p>
        <?php endif; ?>
        <div data-lp-contact-form-fields>
            <div data-lp-contact-form-fields-rows>
                <?php foreach ($fieldRows as $row): ?>
                    <div class="lp-contact-form-fields__row">
                        <input type="text" name="field_labels[]" value="<?= esc_attr($row['label']) ?>" placeholder="Field label">
                        <select name="field_types[]">
                            <?php foreach (ContactFieldType::cases() as $case): ?>
                                <option value="<?= esc_attr($case->value) ?>" <?= $row['type'] === $case->value ? 'selected' : '' ?>><?= esc_html($case->defaultLabel()) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="field_required[]">
                            <option value="0" <?= !$row['required'] ? 'selected' : '' ?>>Optional</option>
                            <option value="1" <?= $row['required'] ? 'selected' : '' ?>>Required</option>
                        </select>
                        <input type="text" name="field_options[]" value="<?= esc_attr($row['options']) ?>" placeholder="Options (Select only), comma-separated">
                        <button type="button" class="lp-button lp-button--link" data-lp-contact-form-fields-move-up>Move Up</button>
                        <button type="button" class="lp-button lp-button--link" data-lp-contact-form-fields-move-down>Move Down</button>
                        <button type="button" class="lp-button lp-button--link" data-lp-contact-form-fields-remove>Remove</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="lp-button lp-button--secondary" data-lp-contact-form-fields-add>Add Field</button>
        </div>

        <p>
            <button type="submit" class="lp-button lp-button--primary">Save Form</button>
            <a class="lp-button" href="<?= esc_url(admin_url('contact-forms/all-forms')) ?>">Back to All Forms</a>
        </p>
    </form>
</section>
