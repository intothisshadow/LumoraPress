<?php

/**
 * The admin Contact Forms > Submissions screen (LPP-003): every submission across every form.
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
use LumoraPress\Plugins\ContactForms\ContactSubmissionService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// See all-forms.php's identical comment: this view is only ever reachable
// while the Contact Forms plugin is active, so its classes are guaranteed
// to already be loaded.
$tablePrefix = (string) $kernel->config->get('table_prefix', 'lp_');
$forms = new ContactFormService($kernel->database, $tablePrefix);
$submissions = new ContactSubmissionService($kernel->database, $tablePrefix);

$formIdFilter = (int) ($_GET['form_id'] ?? 0);
$statusTab = (string) ($_GET['status'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $formAction = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $id = (int) ($_POST['id'] ?? 0);
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
    $backTo = admin_url('contact-forms/submissions') . '?' . http_build_query(array_filter(['form_id' => $formIdFilter ?: null, 'status' => $statusTab ?: null]));

    if ($formAction === 'mark_read' && Csrf::verify('contact_submission_mark_read_' . $id, $token)) {
        $submissions->markRead($id);
        header('Location: ' . $backTo);
        exit;
    }

    if ($formAction === 'delete_submission' && Csrf::verify('contact_submission_delete_' . $id, $token)) {
        $submissions->delete($id);
        header('Location: ' . $backTo . (str_contains($backTo, '?') ? '&' : '?') . 'deleted=1');
        exit;
    }
}

$allForms = $forms->listAll();
$formsById = [];

foreach ($allForms as $listedForm) {
    $formsById[$listedForm->id] = $listedForm;
}

$spamOnly = $statusTab === 'spam' ? true : ($statusTab === 'inbox' ? false : null);
$items = $submissions->listAll($formIdFilter > 0 ? $formIdFilter : null, $spamOnly);
?>
<h1 class="lp-admin__title">Submissions</h1>

<?php if (isset($_GET['deleted'])): ?>
    <div class="lp-alert lp-alert--success">Submission deleted.</div>
<?php endif; ?>

<p class="lp-admin__filters">
    <?php
    $tabQuery = static fn (string $tab): string => admin_url('contact-forms/submissions') . '?' . http_build_query(array_filter(['form_id' => $formIdFilter ?: null, 'status' => $tab ?: null]));
    ?>
    <a href="<?= esc_url($tabQuery('')) ?>" class="<?= $statusTab === '' ? 'is-active' : '' ?>">All</a>
    <a href="<?= esc_url($tabQuery('inbox')) ?>" class="<?= $statusTab === 'inbox' ? 'is-active' : '' ?>">Inbox</a>
    <a href="<?= esc_url($tabQuery('spam')) ?>" class="<?= $statusTab === 'spam' ? 'is-active' : '' ?>">Spam</a>
</p>

<?php if ($allForms !== []): ?>
    <form method="get" action="<?= esc_url(admin_url('contact-forms/submissions')) ?>" class="lp-admin__inline-form">
        <?php if ($statusTab !== ''): ?>
            <input type="hidden" name="status" value="<?= esc_attr($statusTab) ?>">
        <?php endif; ?>
        <p class="lp-field">
            <label for="submissions-form-filter">Form</label>
            <select id="submissions-form-filter" name="form_id" onchange="this.form.requestSubmit()">
                <option value="0">All forms</option>
                <?php foreach ($allForms as $filterForm): ?>
                    <option value="<?= (int) $filterForm->id ?>" <?= $formIdFilter === $filterForm->id ? 'selected' : '' ?>><?= esc_html($filterForm->title) ?></option>
                <?php endforeach; ?>
            </select>
        </p>
    </form>
<?php endif; ?>

<section class="lp-admin__panel">
    <?php if ($items === []): ?>
        <p class="lp-admin__widget-placeholder">No submissions yet.</p>
    <?php else: ?>
        <table class="lp-table">
            <thead>
                <tr>
                    <th scope="col">Form</th>
                    <th scope="col">Submission</th>
                    <th scope="col">Status</th>
                    <th scope="col">From</th>
                    <th scope="col">Date</th>
                    <th scope="col"><span class="lp-visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $submission): ?>
                    <?php $submissionForm = $formsById[$submission->formId] ?? null; ?>
                    <tr class="<?= $submission->isRead ? '' : 'lp-table__row--unread' ?>">
                        <td><?= esc_html($submissionForm?->title ?? 'Deleted form') ?></td>
                        <td>
                            <?php foreach ($submission->data as $key => $value): ?>
                                <?php $field = $submissionForm?->fieldByKey((string) $key); ?>
                                <div><strong><?= esc_html($field?->label ?? (string) $key) ?>:</strong> <?= esc_html((string) $value) ?></div>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <?php if ($submission->isSpam): ?>
                                <span class="lp-status-badge lp-status-badge--failed">Spam</span>
                            <?php elseif (!$submission->isRead): ?>
                                <span class="lp-status-badge lp-status-badge--pending">Unread</span>
                            <?php else: ?>
                                <span class="lp-status-badge lp-status-badge--success">Read</span>
                            <?php endif; ?>
                        </td>
                        <td><?= esc_html($submission->ipAddress ?? '&mdash;') ?></td>
                        <td><?= esc_html($submission->createdAt->format('M j, Y g:i A')) ?></td>
                        <td class="lp-admin__row-actions">
                            <?php if (!$submission->isRead): ?>
                                <form method="post" action="<?= esc_url(admin_url('contact-forms/submissions')) ?>" class="lp-admin__inline-form">
                                    <?= Csrf::field('contact_submission_mark_read_' . $submission->id) ?>
                                    <input type="hidden" name="form" value="mark_read">
                                    <input type="hidden" name="id" value="<?= (int) $submission->id ?>">
                                    <button type="submit" class="lp-button lp-button--link">Mark Read</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?= esc_url(admin_url('contact-forms/submissions')) ?>" class="lp-admin__inline-form" data-lp-confirm="Delete this submission?">
                                <?= Csrf::field('contact_submission_delete_' . $submission->id) ?>
                                <input type="hidden" name="form" value="delete_submission">
                                <input type="hidden" name="id" value="<?= (int) $submission->id ?>">
                                <button type="submit" class="lp-button lp-button--link lp-button--link--danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
