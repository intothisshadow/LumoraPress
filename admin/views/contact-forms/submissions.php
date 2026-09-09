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
use LumoraPress\Plugins\ContactForms\ContactSubmission;
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
$searchFilter = trim((string) ($_GET['q'] ?? ''));
$dateFromFilter = (string) ($_GET['date_from'] ?? '');
$dateToFilter = (string) ($_GET['date_to'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $formAction = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $id = (int) ($_POST['id'] ?? 0);
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
    $backTo = admin_url('contact-forms/submissions') . '?' . http_build_query(array_filter([
        'form_id' => $formIdFilter ?: null,
        'status' => $statusTab ?: null,
        'q' => $searchFilter ?: null,
        'date_from' => $dateFromFilter ?: null,
        'date_to' => $dateToFilter ?: null,
    ]));

    if ($formAction === 'mark_read' && Csrf::verify('contact_submission_mark_read_' . $id, $token)) {
        $submissions->markRead($id);
        header('Location: ' . $backTo);
        exit;
    }

    if ($formAction === 'mark_unread' && Csrf::verify('contact_submission_mark_unread_' . $id, $token)) {
        $submissions->markUnread($id);
        header('Location: ' . $backTo);
        exit;
    }

    if ($formAction === 'archive_submission' && Csrf::verify('contact_submission_archive_' . $id, $token)) {
        $submissions->archive($id);
        header('Location: ' . $backTo);
        exit;
    }

    if ($formAction === 'unarchive_submission' && Csrf::verify('contact_submission_unarchive_' . $id, $token)) {
        $submissions->unarchive($id);
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
// Archived submissions are hidden from every tab except "Archived" itself,
// the same way archiving a message in an inbox client removes it from view
// without deleting it.
$archivedOnly = $statusTab === 'archived' ? true : false;
$listFilters = [
    'archivedOnly' => $archivedOnly,
    'search' => $searchFilter,
    'dateFrom' => $dateFromFilter,
    'dateTo' => $dateToFilter,
];

// A CSV/JSON export applies the same filters as the current view but with
// no row cap, streamed directly rather than saved to disk — the data
// already lives in the database (see maintenance/import.php's identical
// redirect-mapping export for the same pattern).
$exportFormat = (string) ($_GET['export'] ?? '');

if ($exportFormat === 'csv' || $exportFormat === 'json') {
    $exportItems = $submissions->listAll($formIdFilter > 0 ? $formIdFilter : null, $spamOnly, 100000, $listFilters);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if ($exportFormat === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="contact-form-submissions-' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'wb');
        $fieldKeys = [];

        foreach ($exportItems as $exportItem) {
            foreach (array_keys($exportItem->data) as $exportKey) {
                if (!in_array($exportKey, $fieldKeys, true)) {
                    $fieldKeys[] = $exportKey;
                }
            }
        }

        // PHP 8.4 deprecates fputcsv()'s implicit default $escape value
        // (LumoraPress's error handler turns that deprecation into a fatal
        // exception), so the escape character must be passed explicitly.
        fputcsv($output, ['Form', 'Status', 'IP Address', 'Date', ...$fieldKeys], ',', '"', '\\');

        foreach ($exportItems as $exportItem) {
            $exportForm = $formsById[$exportItem->formId] ?? null;
            $status = $exportItem->isSpam ? 'Spam' : ($exportItem->isArchived ? 'Archived' : ($exportItem->isRead ? 'Read' : 'Unread'));
            $row = [
                $exportForm?->title ?? 'Deleted form',
                $status,
                $exportItem->ipAddress ?? '',
                $exportItem->createdAt->format('Y-m-d H:i:s'),
            ];

            foreach ($fieldKeys as $fieldKey) {
                $row[] = $exportItem->data[$fieldKey] ?? '';
            }

            fputcsv($output, $row, ',', '"', '\\');
        }

        fclose($output);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="contact-form-submissions-' . date('Y-m-d') . '.json"');

    echo json_encode(array_map(static function (ContactSubmission $exportItem) use ($formsById): array {
        $exportForm = $formsById[$exportItem->formId] ?? null;

        return [
            'form' => $exportForm?->title ?? 'Deleted form',
            'status' => $exportItem->isSpam ? 'spam' : ($exportItem->isArchived ? 'archived' : ($exportItem->isRead ? 'read' : 'unread')),
            'ip_address' => $exportItem->ipAddress,
            'date' => $exportItem->createdAt->format(DATE_ATOM),
            'data' => $exportItem->data,
        ];
    }, $exportItems), JSON_PRETTY_PRINT);
    exit;
}

$items = $submissions->listAll($formIdFilter > 0 ? $formIdFilter : null, $spamOnly, 100, $listFilters);
?>
<h1 class="lp-admin__title">Submissions</h1>

<?php if (isset($_GET['deleted'])): ?>
    <div class="lp-alert lp-alert--success">Submission deleted.</div>
<?php endif; ?>

<p class="lp-admin__filters">
    <?php
    $tabQuery = static fn (string $tab): string => admin_url('contact-forms/submissions') . '?' . http_build_query(array_filter([
        'form_id' => $formIdFilter ?: null,
        'status' => $tab ?: null,
        'q' => $searchFilter ?: null,
        'date_from' => $dateFromFilter ?: null,
        'date_to' => $dateToFilter ?: null,
    ]));
    ?>
    <a href="<?= esc_url($tabQuery('')) ?>" class="<?= $statusTab === '' ? 'is-active' : '' ?>">All</a>
    <a href="<?= esc_url($tabQuery('inbox')) ?>" class="<?= $statusTab === 'inbox' ? 'is-active' : '' ?>">Inbox</a>
    <a href="<?= esc_url($tabQuery('spam')) ?>" class="<?= $statusTab === 'spam' ? 'is-active' : '' ?>">Spam</a>
    <a href="<?= esc_url($tabQuery('archived')) ?>" class="<?= $statusTab === 'archived' ? 'is-active' : '' ?>">Archived</a>
</p>

<section class="lp-admin__panel">
    <details class="lp-admin__collapsible" <?= ($searchFilter !== '' || $dateFromFilter !== '' || $dateToFilter !== '') ? 'open' : '' ?>>
        <summary>Search &amp; Filter</summary>
        <div class="lp-admin__collapsible__body">
            <form method="get" action="<?= esc_url(admin_url('contact-forms/submissions')) ?>" class="lp-admin__filter-form">
                <?php if ($statusTab !== ''): ?>
                    <input type="hidden" name="status" value="<?= esc_attr($statusTab) ?>">
                <?php endif; ?>
                <p class="lp-field">
                    <label for="submissions-q">Search</label>
                    <input type="text" id="submissions-q" name="q" value="<?= esc_attr($searchFilter) ?>" placeholder="Name, email, message&hellip;">
                </p>
                <?php if ($allForms !== []): ?>
                    <p class="lp-field">
                        <label for="submissions-form-filter">Form</label>
                        <select id="submissions-form-filter" name="form_id">
                            <option value="0">All forms</option>
                            <?php foreach ($allForms as $filterForm): ?>
                                <option value="<?= (int) $filterForm->id ?>" <?= $formIdFilter === $filterForm->id ? 'selected' : '' ?>><?= esc_html($filterForm->title) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                <?php endif; ?>
                <p class="lp-field">
                    <label for="submissions-date-from">Date from</label>
                    <input type="date" id="submissions-date-from" name="date_from" value="<?= esc_attr($dateFromFilter) ?>">
                </p>
                <p class="lp-field">
                    <label for="submissions-date-to">Date to</label>
                    <input type="date" id="submissions-date-to" name="date_to" value="<?= esc_attr($dateToFilter) ?>">
                </p>
                <button type="submit" class="lp-button">Filter</button>
            </form>
        </div>
    </details>
</section>

<p class="lp-admin__filters">
    <?php
    $exportQuery = static fn (string $format): string => admin_url('contact-forms/submissions') . '?' . http_build_query(array_filter([
        'form_id' => $formIdFilter ?: null,
        'status' => $statusTab ?: null,
        'q' => $searchFilter ?: null,
        'date_from' => $dateFromFilter ?: null,
        'date_to' => $dateToFilter ?: null,
        'export' => $format,
    ]));
    ?>
    <a href="<?= esc_url($exportQuery('csv')) ?>" class="lp-button lp-button--secondary">Export CSV</a>
    <a href="<?= esc_url($exportQuery('json')) ?>" class="lp-button lp-button--secondary">Export JSON</a>
</p>

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
                            <?php elseif ($submission->isArchived): ?>
                                <span class="lp-status-badge">Archived</span>
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
                            <?php else: ?>
                                <form method="post" action="<?= esc_url(admin_url('contact-forms/submissions')) ?>" class="lp-admin__inline-form">
                                    <?= Csrf::field('contact_submission_mark_unread_' . $submission->id) ?>
                                    <input type="hidden" name="form" value="mark_unread">
                                    <input type="hidden" name="id" value="<?= (int) $submission->id ?>">
                                    <button type="submit" class="lp-button lp-button--link">Mark Unread</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($submission->isArchived): ?>
                                <form method="post" action="<?= esc_url(admin_url('contact-forms/submissions')) ?>" class="lp-admin__inline-form">
                                    <?= Csrf::field('contact_submission_unarchive_' . $submission->id) ?>
                                    <input type="hidden" name="form" value="unarchive_submission">
                                    <input type="hidden" name="id" value="<?= (int) $submission->id ?>">
                                    <button type="submit" class="lp-button lp-button--link">Unarchive</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="<?= esc_url(admin_url('contact-forms/submissions')) ?>" class="lp-admin__inline-form">
                                    <?= Csrf::field('contact_submission_archive_' . $submission->id) ?>
                                    <input type="hidden" name="form" value="archive_submission">
                                    <input type="hidden" name="id" value="<?= (int) $submission->id ?>">
                                    <button type="submit" class="lp-button lp-button--link">Archive</button>
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
