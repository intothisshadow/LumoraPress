<?php

/**
 * Result panel of the GitHub importer: the changes found for an update, the match for a link, or the import preview.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.23.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var int $updateId */
/** @var int $linkId */
/** @var array<string, mixed>|null $updateCheck */
/** @var array<string, mixed>|null $linkPreview */
/** @var array<string, mixed>|null $preview */
/** @var string $endpoint */
/** @var string $csrfRun */
/** @var array<int, \LumoraPress\Plugins\Downloads\DownloadCategory> $allCategories */
/** @var \Closure $formatSize */

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel) || !isset($endpoint, $csrfRun, $formatSize)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}
?>
<?php if ($updateId > 0): ?>
    <?php if ($updateCheck !== null && !$updateCheck['hasChanges']): ?>
        <div class="lp-alert lp-alert--success">This download is up to date with GitHub.</div>
    <?php elseif ($updateCheck !== null): ?>
        <section class="lp-admin__panel">
            <h2>Changes on GitHub</h2>
            <form method="post" action="<?= esc_url($endpoint) ?>?update=<?= (int) $updateId ?>" data-lp-github-import data-lp-github-mode="update" data-lp-github-endpoint="<?= esc_url($endpoint) ?>" data-lp-github-csrf="<?= esc_attr($csrfRun) ?>">
                <input type="hidden" name="download_id" value="<?= (int) $updateId ?>">

                <?php if ($updateCheck['branchChanged']): ?>
                    <p class="lp-field">
                        <label class="lp-field--checkbox">
                            <input type="checkbox" name="apply_branch" value="1" checked>
                            The default branch is now <code><?= esc_html($updateCheck['newBranch']) ?></code> (was <code><?= esc_html($updateCheck['branch']) ?></code>). Point the download link at it.
                        </label>
                    </p>
                <?php endif; ?>

                <?php if ($updateCheck['readmeChanged']): ?>
                    <p class="lp-field">
                        <label class="lp-field--checkbox">
                            <input type="checkbox" name="apply_description" value="1" <?= $updateCheck['descriptionEditedLocally'] ? '' : 'checked' ?>>
                            The README changed. Replace the description with the new text.
                        </label>
                        <?php if ($updateCheck['descriptionEditedLocally']): ?>
                            <span class="lp-field__hint">You have edited this description since it was imported, so this is unticked to keep your changes.</span>
                        <?php endif; ?>
                    </p>
                    <?php if ($updateCheck['titleChanged']): ?>
                        <p class="lp-field">
                            <label class="lp-field--checkbox">
                                <input type="checkbox" name="apply_title" value="1" <?= $updateCheck['titleEditedLocally'] ? '' : 'checked' ?>>
                                Change the title to &ldquo;<?= esc_html($updateCheck['newTitle']) ?>&rdquo;.
                            </label>
                            <?php if ($updateCheck['titleEditedLocally']): ?>
                                <span class="lp-field__hint">You have edited this title since it was imported, so this is unticked.</span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($updateCheck['newImages'] !== []): ?>
                    <fieldset class="lp-field">
                        <legend>New screenshots</legend>
                        <?php foreach ($updateCheck['newImages'] as $image): ?>
                            <label class="lp-field--checkbox"><input type="checkbox" name="add[]" value="<?= esc_attr($image['path']) ?>" checked> <?= esc_html($image['name']) ?> <span class="lp-field__hint"><?= esc_html($formatSize($image['size'])) ?></span></label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>

                <?php if ($updateCheck['changedImages'] !== []): ?>
                    <fieldset class="lp-field">
                        <legend>Changed screenshots</legend>
                        <span class="lp-field__hint">The image is replaced where it is, so anything already pointing at it keeps working.</span>
                        <?php foreach ($updateCheck['changedImages'] as $image): ?>
                            <label class="lp-field--checkbox"><input type="checkbox" name="replace[]" value="<?= esc_attr($image['path']) ?>" checked> <?= esc_html($image['name']) ?></label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>

                <?php if ($updateCheck['removedImages'] !== []): ?>
                    <fieldset class="lp-field">
                        <legend>Removed from GitHub</legend>
                        <span class="lp-field__hint">These are kept here unless you tick them, in which case they are also deleted from the Media library.</span>
                        <?php foreach ($updateCheck['removedImages'] as $image): ?>
                            <label class="lp-field--checkbox"><input type="checkbox" name="remove[]" value="<?= esc_attr($image['path']) ?>"> <?= esc_html(basename($image['path'])) ?></label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>

                <button type="submit" class="lp-button lp-button--primary">Apply Selected Changes</button>
            </form>

            <div data-lp-github-progress hidden>
                <progress data-lp-github-bar max="1" value="0"></progress>
                <p class="lp-field__hint" data-lp-github-status role="status"></p>
                <ul data-lp-github-failures></ul>
            </div>
            <noscript><p class="lp-field__hint">This screen needs JavaScript to apply changes.</p></noscript>
        </section>
    <?php endif; ?>
<?php else: ?>
    <?php if ($linkPreview !== null): ?>
        <?php $linkRef = $linkPreview['ref']; ?>
        <section class="lp-admin__panel">
            <h2>Match: <?= esc_html($linkRef->fullName()) ?></h2>
            <p>
                <?= count($linkPreview['matched']) ?> of this download's screenshots already match a file in the repository.
                <?= count($linkPreview['unmatched']) ?> file<?= count($linkPreview['unmatched']) === 1 ? '' : 's' ?> in the repository <?= count($linkPreview['unmatched']) === 1 ? 'is' : 'are' ?> not on this download yet.
            </p>

            <?php if ($linkPreview['matched'] !== []): ?>
                <table class="lp-table">
                    <thead>
                        <tr><th scope="col">In the repository</th><th scope="col">Matches this download's file</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($linkPreview['matched'] as $match): ?>
                            <tr>
                                <td><code><?= esc_html($match['path']) ?></code></td>
                                <td><?= esc_html($match['mediaName']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="lp-field__hint">None of the images in this download's Media folder are in the repository. Linking still works: the repository's screenshots will be offered as new.</p>
            <?php endif; ?>

            <p class="lp-field__hint">
                Linking records the repository (branch <code><?= esc_html($linkPreview['branch']) ?></code>) and which files match. The README is not applied: after linking, the update screen offers it with the box unticked if your description differs, so your own text is never replaced by accident.
            </p>

            <form method="post" action="<?= esc_url($endpoint) ?>?link=<?= (int) $linkId ?>">
                <?= Csrf::field('github_link') ?>
                <?php if ($linkPreview['switchesLink']): ?>
                    <p class="lp-field">
                        <label>
                            <input type="checkbox" name="switch_link" value="1" checked>
                            Make the download link GitHub's zip of the <code><?= esc_html($linkPreview['branch']) ?></code> branch, as an import does
                        </label>
                        <span class="lp-field__hint"><code><?= esc_html($linkPreview['zipUrl']) ?></code> always serves the latest code, so nothing has to be stored on your server.<?= $linkPreview['replacesFile'] ? ' This download currently serves an uploaded file; that file is removed from the Media Library if nothing else uses it.' : '' ?> Untick it to keep the current link.</span>
                    </p>
                <?php else: ?>
                    <p class="lp-field__hint">This download's link already points at this repository's zip, so it stays as it is.</p>
                <?php endif; ?>
                <input type="hidden" name="form" value="github_link">
                <input type="hidden" name="download_id" value="<?= (int) $linkId ?>">
                <input type="hidden" name="owner" value="<?= esc_attr($linkRef->owner) ?>">
                <input type="hidden" name="repo" value="<?= esc_attr($linkRef->repo) ?>">
                <button type="submit" class="lp-button lp-button--primary">Link to This Repository</button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($preview !== null): ?>
        <?php $previewRef = $preview['ref']; ?>
        <?php if ($preview['existingDownloadId'] !== null): ?>
            <div class="lp-alert lp-alert--warning">
                This repository has already been imported.
                <a href="<?= esc_url($endpoint) ?>?update=<?= (int) $preview['existingDownloadId'] ?>">Update it instead</a>,
                or <a href="<?= esc_url(admin_url('downloads/add-new')) ?>?id=<?= (int) $preview['existingDownloadId'] ?>">open the download</a>.
                To import it again as a second download, tick the box at the bottom of the form.
            </div>
        <?php endif; ?>

        <section class="lp-admin__panel">
            <h2>Preview: <?= esc_html($previewRef->fullName()) ?></h2>
            <form method="post" action="<?= esc_url($endpoint) ?>" data-lp-github-import data-lp-github-mode="import" data-lp-github-endpoint="<?= esc_url($endpoint) ?>" data-lp-github-csrf="<?= esc_attr($csrfRun) ?>">
                <input type="hidden" name="owner" value="<?= esc_attr($previewRef->owner) ?>">
                <input type="hidden" name="repo" value="<?= esc_attr($previewRef->repo) ?>">

                <p class="lp-field">
                    <label for="github-title">Title</label>
                    <input type="text" id="github-title" name="title" value="<?= esc_attr($preview['title']) ?>" required>
                </p>

                <p class="lp-field">
                    <label for="github-category">Category</label>
                    <select id="github-category" name="category_id">
                        <option value="0">(Uncategorized)</option>
                        <?php foreach ($allCategories as $category): ?>
                            <option value="<?= (int) $category->id ?>"><?= esc_html($category->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <p class="lp-field">
                    <label for="github-description">Description (Markdown)</label>
                    <textarea id="github-description" name="description" rows="16" spellcheck="false"><?= esc_html($preview['description']) ?></textarea>
                    <span class="lp-field__hint">
                        <?= $preview['hasReadme'] ? 'The README, without its own screenshots. ' : 'This repository has no README. ' ?>
                        The full editor, with visual editing, is available on the download once it is imported.
                    </span>
                </p>

                <fieldset class="lp-field">
                    <legend>Screenshots</legend>
                    <?php if ($preview['screenshots'] === []): ?>
                        <span class="lp-field__hint">No screenshots were found in this repository. The download is still created without them.</span>
                    <?php else: ?>
                        <span class="lp-field__hint">Copied into a Media folder named <strong><?= esc_html($preview['repoName']) ?></strong> and shown after the description. The first one is the download's thumbnail.</span>
                        <div class="lp-github-shots">
                            <?php foreach ($preview['screenshots'] as $shot): ?>
                                <label class="lp-github-shots__item">
                                    <input type="checkbox" name="paths[]" value="<?= esc_attr($shot['path']) ?>" <?= $shot['tooLarge'] ? 'disabled' : 'checked' ?>>
                                    <img class="lp-github-shots__thumb" src="<?= esc_url($previewRef->rawUrl($preview['branch'], $shot['path'])) ?>" alt="" loading="lazy">
                                    <span class="lp-github-shots__name"><?= esc_html($shot['name']) ?></span>
                                    <span class="lp-field__hint"><?= $shot['tooLarge'] ? 'Too large to import (' . esc_html($formatSize($shot['size'])) . ')' : esc_html($formatSize($shot['size'])) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </fieldset>

                <p class="lp-field">
                    <span class="lp-field__hint">The download links to GitHub's zip of the <code><?= esc_html($preview['branch']) ?></code> branch: <code><?= esc_html($previewRef->archiveUrl($preview['branch'])) ?></code></span>
                </p>

                <?php if ($preview['existingDownloadId'] !== null): ?>
                    <p class="lp-field">
                        <label class="lp-field--checkbox"><input type="checkbox" name="allow_duplicate" value="1"> Import it again as a second download</label>
                    </p>
                <?php endif; ?>

                <button type="submit" class="lp-button lp-button--primary">Import</button>
            </form>

            <div data-lp-github-progress hidden>
                <progress data-lp-github-bar max="1" value="0"></progress>
                <p class="lp-field__hint" data-lp-github-status role="status"></p>
                <ul data-lp-github-failures></ul>
            </div>
            <noscript><p class="lp-field__hint">This screen needs JavaScript to import.</p></noscript>
        </section>
    <?php endif; ?>
<?php endif; ?>
