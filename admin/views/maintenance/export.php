<?php

/**
 * The admin Maintenance > Export screen: download this site's content as a Lumora Press export or WordPress WXR file.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.19.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Services\ContentExportService;
use LumoraPress\Services\Export\ExportFormatRegistry;
use LumoraPress\Services\Export\ExportOptions;
use LumoraPress\Services\Export\LumoraPressZipWriter;
use LumoraPress\Services\Export\WordPressXmlWriter;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$exportDirectory = LUMORA_ROOT . '/storage/exports';
$exportRegistry = new ExportFormatRegistry([
    new LumoraPressZipWriter($exportDirectory),
    new WordPressXmlWriter($kernel->content, $exportDirectory),
], $kernel->hooks);
$exportFormats = $exportRegistry->all();

// A generated file is only ever meant for one download right after it's
// made; anything older than an hour was abandoned and still holds the
// whole site's content (including user emails), so it's removed.
foreach (glob($exportDirectory . '/*') ?: [] as $staleExport) {
    if (is_file($staleExport) && filemtime($staleExport) < time() - 3600) {
        unlink($staleExport);
    }
}

// The file itself is never addressable by URL: only the session that
// generated it can download it, once.
$pendingExport = is_array($_SESSION['lp_content_export'] ?? null) ? $_SESSION['lp_content_export'] : null;

if (is_string($_GET['download'] ?? null) && $pendingExport !== null && hash_equals((string) $pendingExport['token'], $_GET['download'])) {
    unset($_SESSION['lp_content_export']);

    if (is_file((string) $pendingExport['path'])) {
        set_time_limit(0);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . $pendingExport['mimeType']);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', (string) $pendingExport['fileName']) . '"');
        header('Content-Length: ' . (string) filesize((string) $pendingExport['path']));
        header('X-Content-Type-Options: nosniff');
        readfile((string) $pendingExport['path']);
        unlink((string) $pendingExport['path']);
        exit;
    }

    $pendingExport = null;
}

$exportError = null;
$selectedFormat = is_string($_POST['format'] ?? null) && isset($exportFormats[$_POST['format']]) ? $_POST['format'] : (string) array_key_first($exportFormats);

if (($_POST['form'] ?? null) === 'export_content' && Csrf::verify('export_content', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $writer = $exportFormats[$selectedFormat] ?? null;
    $requestedTypes = is_array($_POST['content_types'] ?? null) ? array_values(array_filter($_POST['content_types'], 'is_string')) : [];

    if ($writer === null) {
        $exportError = 'Choose an export format.';
    } elseif ($requestedTypes === []) {
        $exportError = 'Choose at least one type of content to export.';
    } else {
        // A large site's export is built in one request.
        set_time_limit(0);

        try {
            $exportService = new ContentExportService(
                posts: $kernel->posts,
                pages: $kernel->pages,
                categories: $kernel->categories,
                tags: $kernel->tags,
                comments: $kernel->comments,
                users: $kernel->users,
                media: $kernel->media,
                folders: $kernel->folders,
                menus: $kernel->menus,
                widgets: $kernel->widgets,
                siteName: (string) $kernel->config->option('site_name', 'Lumora Press'),
                siteUrl: home_url(),
                uploadsUrl: home_url('content/uploads'),
                version: (string) (require LUMORA_ROOT . '/version.php')['version'],
            );
            $options = (new ExportOptions($requestedTypes, isset($_POST['include_uploads'])))->restrictedTo($writer);
            $content = $exportService->build($options);
            $path = $writer->write($content, $options);

            // Replaces (and deletes) any earlier export this session never downloaded.
            if ($pendingExport !== null && is_file((string) $pendingExport['path'])) {
                unlink((string) $pendingExport['path']);
            }

            $_SESSION['lp_content_export'] = [
                'token' => bin2hex(random_bytes(16)),
                'path' => $path,
                'fileName' => $writer->downloadFileName($content),
                'mimeType' => $writer->mimeType(),
                'label' => $writer->label(),
                'size' => (int) filesize($path),
                'notices' => $writer->notices(),
            ];

            header('Location: ' . admin_url('maintenance/export') . '?generated=1');
            exit;
        } catch (\RuntimeException $exception) {
            // Only the export classes' own, user-facing failures are shown;
            // anything unexpected reaches the global handler, which logs it.
            $exportError = 'The export could not be created: ' . $exception->getMessage();
        }
    }
}

$contentTypeLabels = [
    ExportOptions::POSTS => ['Posts', 'Including their categories, tags, and custom fields.'],
    ExportOptions::PAGES => ['Pages', ''],
    ExportOptions::COMMENTS => ['Comments', 'Only comments on posts and pages that are also exported. Spam and trashed comments are never included.'],
    ExportOptions::MEDIA => ['Media', 'Every Media Manager item\'s details (file name, alt text, caption, folder).'],
    ExportOptions::USERS => ['Users', 'Usernames, email addresses, display names, and roles. Passwords are never exported.'],
    ExportOptions::MENUS => ['Menus', ''],
    ExportOptions::WIDGETS => ['Widgets', ''],
];
$selectedWriter = $exportFormats[$selectedFormat] ?? null;
$showGenerated = isset($_GET['generated']) && $pendingExport !== null;
?>
<h1 class="lp-admin__title">Export</h1>

<?php if ($exportError !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($exportError) ?></div>
<?php endif; ?>

<?php if ($showGenerated): ?>
    <section class="lp-admin__panel lp-export-ready">
        <div class="lp-alert lp-alert--success">
            Your <?= esc_html((string) $pendingExport['label']) ?> export is ready
            (<?= esc_html(number_format((int) $pendingExport['size'] / 1048576, 1)) ?> MB).
        </div>

        <?php if ($pendingExport['notices'] !== []): ?>
            <div class="lp-alert lp-alert--warning">
                <strong><?= count($pendingExport['notices']) ?> thing<?= count($pendingExport['notices']) === 1 ? '' : 's' ?> couldn't be carried over exactly:</strong>
                <ul>
                    <?php foreach ($pendingExport['notices'] as $notice): ?>
                        <li><?= esc_html((string) $notice) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <p class="lp-field__hint">
            The file can be downloaded once, and is deleted from this server
            right after (or after an hour if it's never downloaded).
        </p>

        <p>
            <a class="lp-button lp-button--primary" href="<?= esc_url(admin_url('maintenance/export') . '?download=' . rawurlencode((string) $pendingExport['token'])) ?>">Download <?= esc_html((string) $pendingExport['fileName']) ?></a>
            <a class="lp-button lp-button--secondary" href="<?= esc_url(admin_url('maintenance/export')) ?>">Start Over</a>
        </p>
    </section>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Export Content</h2>

    <p class="lp-field__hint">
        Downloads your site's content in one file, to move it to another site
        or keep as a backup. Only content is included — never themes,
        plugins, site settings, or passwords. To copy settings between
        Lumora Press sites, use Export Settings under Maintenance &rsaquo; Tools.
    </p>

    <form method="post" action="<?= esc_url(admin_url('maintenance/export')) ?>" class="lp-export-form">
        <?= Csrf::field('export_content') ?>
        <input type="hidden" name="form" value="export_content">

        <fieldset class="lp-field--checklist lp-export-form__formats">
            <legend>Format</legend>
            <?php foreach ($exportFormats as $formatId => $writer): ?>
                <label class="lp-field--checkbox">
                    <input
                        type="radio"
                        name="format"
                        value="<?= esc_attr($formatId) ?>"
                        data-lp-export-format
                        data-lp-export-supported="<?= esc_attr(implode(' ', $writer->supportedContentTypes())) ?>"
                        data-lp-export-bundles-uploads="<?= $writer->bundlesUploads() ? '1' : '0' ?>"
                        <?= $formatId === $selectedFormat ? 'checked' : '' ?>
                    >
                    <strong><?= esc_html($writer->label()) ?></strong>
                    <span class="lp-field__hint"><?= esc_html($writer->description()) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <fieldset class="lp-field--checklist lp-export-form__types">
            <legend>Content to export</legend>
            <?php foreach ($contentTypeLabels as $type => [$label, $hint]): ?>
                <?php $supported = $selectedWriter === null || in_array($type, $selectedWriter->supportedContentTypes(), true); ?>
                <label class="lp-field--checkbox">
                    <input type="checkbox" name="content_types[]" value="<?= esc_attr($type) ?>" data-lp-export-type="<?= esc_attr($type) ?>" checked<?= $supported ? '' : ' disabled' ?>>
                    <?= esc_html($label) ?>
                    <?php if ($hint !== ''): ?>
                        <span class="lp-field__hint"><?= esc_html($hint) ?></span>
                    <?php endif; ?>
                    <span class="lp-field__hint lp-export-form__unsupported" data-lp-export-unsupported-note<?= $supported ? ' hidden' : '' ?>>Not available in this format.</span>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <p class="lp-field">
            <label class="lp-field--checkbox">
                <input type="checkbox" name="include_uploads" value="1" data-lp-export-uploads checked<?= $selectedWriter === null || $selectedWriter->bundlesUploads() ? '' : ' disabled' ?>>
                Include uploaded files
            </label>
            <span class="lp-field__hint">
                Adds the actual image and file uploads to the export, so it's
                complete on its own. Uploads can make the file very large, and on
                some hosts building it can time out — if so, leave this unchecked
                and copy this site's <code>content/uploads</code> folder to the new
                server separately; the import can read each file from that folder.
            </span>
        </p>

        <div class="lp-field lp-export-form__wordpress-note">
            <strong>WordPress Export/Import</strong>
            <p class="lp-field__hint">
                A WordPress export (WXR (.xml) format) never includes files (the
                importing site fetches them from this site's addresses instead).
                WordPress's own importer downloads every file in a single request,
                so with thousands of media files it can outlast the importing
                server's time limit and stop partway; if that happens, raise the
                limit there (or ask your host to) and import again into a fresh site.
            </p>
        </div>

        <button type="submit" class="lp-button lp-button--primary">Create Export File</button>
    </form>
</section>
