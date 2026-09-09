<?php

/**
 * The admin Contact Forms > All Forms screen (LPP-003): a list of every form.
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
use LumoraPress\Plugins\ContactForms\ContactFormService;
use LumoraPress\Plugins\ContactForms\ContactFormUploadService;
use LumoraPress\Plugins\ContactForms\ContactSubmissionService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Only reachable while the Contact Forms plugin is active, so its classes
// are guaranteed to already be loaded.
$tablePrefix = (string) $kernel->config->get('table_prefix', 'lp_');
$forms = new ContactFormService($kernel->database, $tablePrefix);
$submissions = new ContactSubmissionService($kernel->database, $tablePrefix);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $formAction = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $id = (int) ($_POST['id'] ?? 0);
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if ($formAction === 'delete_form' && Csrf::verify('contact_form_delete_' . $id, $token)) {
        $submissions->deleteByFormId($id);
        $forms->delete($id);
        (new ContactFormUploadService(LUMORA_ROOT . '/storage/contact-form-uploads'))->deleteAllForForm($id);
        header('Location: ' . admin_url('contact-forms/all-forms') . '?deleted=1');
        exit;
    }
}
?>
<h1 class="lp-admin__title">Contact Forms</h1>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Form saved.</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="lp-alert lp-alert--success">Form deleted.</div>
<?php endif; ?>

<p><a class="lp-button lp-button--primary" href="<?= esc_url(admin_url('contact-forms/add-new')) ?>">Add New Form</a></p>

<section class="lp-admin__panel">
    <?php $allForms = $forms->listAll(); ?>
    <?php if ($allForms === []): ?>
        <p class="lp-admin__widget-placeholder">No contact forms yet.</p>
    <?php else: ?>
        <table class="lp-table">
            <thead>
                <tr>
                    <th scope="col">Title</th>
                    <th scope="col">Recipient</th>
                    <th scope="col">Fields</th>
                    <th scope="col">Submissions</th>
                    <th scope="col">Shortcode</th>
                    <th scope="col"><span class="lp-visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allForms as $listedForm): ?>
                    <tr>
                        <td><a href="<?= esc_url(admin_url('contact-forms/add-new')) ?>?id=<?= (int) $listedForm->id ?>"><?= esc_html($listedForm->title) ?></a></td>
                        <td><?= esc_html($listedForm->recipientEmail) ?></td>
                        <td><?= count($listedForm->fields) ?></td>
                        <td>
                            <a href="<?= esc_url(admin_url('contact-forms/submissions')) ?>?form_id=<?= (int) $listedForm->id ?>">
                                <?= count($submissions->listAll($listedForm->id)) ?>
                            </a>
                        </td>
                        <td><code>[contact_form id="<?= (int) $listedForm->id ?>"]</code></td>
                        <td class="lp-admin__row-actions">
                            <a class="lp-button lp-button--link" href="<?= esc_url(admin_url('contact-forms/add-new')) ?>?id=<?= (int) $listedForm->id ?>">Edit</a>
                            <form method="post" action="<?= esc_url(admin_url('contact-forms/all-forms')) ?>" class="lp-admin__inline-form" data-lp-confirm="Delete this form? Its submissions will also be lost.">
                                <?= Csrf::field('contact_form_delete_' . $listedForm->id) ?>
                                <input type="hidden" name="form" value="delete_form">
                                <input type="hidden" name="id" value="<?= (int) $listedForm->id ?>">
                                <button type="submit" class="lp-button lp-button--link lp-button--link--danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
