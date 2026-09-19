<?php

/**
 * The admin Link Directory > Add New screen: add a new directory entry, or edit an existing one via ?id= (LPP-026).
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.10.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Plugins\LinkDirectory\LinkDirectoryCategoryService;
use LumoraPress\Plugins\LinkDirectory\LinkService;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Only reachable while the Link Directory plugin is active, so LinkService's
// class is guaranteed to already be loaded.
$linkCategories = new LinkDirectoryCategoryService($kernel->database, (string) $kernel->config->get('table_prefix', 'lp_'));
$links = new LinkService($kernel->database, (string) $kernel->config->get('table_prefix', 'lp_'));

$error = null;

// Mirrors admin/views/posts/new.php's convention: one file serves both
// "Add New" and "Edit", switched on ?id= — there is no separate edit.php
// for Link Directory either.
$editingLink = ($_GET['id'] ?? null) !== null ? $links->findById((int) $_GET['id']) : null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

    if ($form === 'create_link_directory_category' && Csrf::verify('create_link_directory_category', $token)) {
        $name = trim((string) ($_POST['name'] ?? ''));

        if ($name === '') {
            $error = 'A category name is required.';
        } else {
            $linkCategories->create($name);
            $redirectTarget = admin_url('link-directory/add-new') . ($editingLink !== null ? '?id=' . $editingLink->id : '');
            header('Location: ' . $redirectTarget);
            exit;
        }
    } elseif ($form === 'create_link' && Csrf::verify('create_link', $token)) {
        $title = trim((string) ($_POST['title'] ?? ''));
        $url = trim((string) ($_POST['url'] ?? ''));
        $description = (string) ($_POST['description'] ?? '');
        $descriptionFormat = ContentFormat::tryFrom((string) ($_POST['description_format'] ?? '')) ?? get_active_editor($currentUser->id);
        $categoryId = (int) ($_POST['category_id'] ?? 0);

        if ($title === '') {
            $error = 'Please enter a title.';
        } elseif ($url === '') {
            $error = 'Please enter a URL.';
        } else {
            try {
                $thumbnailMediaId = null;

                if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
                    $uploadedThumbnail = $kernel->media->upload($_FILES['thumbnail'], $currentUser->id);
                    $kernel->thumbnails->generate($uploadedThumbnail);
                    $thumbnailMediaId = (int) $uploadedThumbnail['id'];
                }

                $createdLink = $links->create(
                    title: $title,
                    url: $url,
                    description: $description,
                    descriptionFormat: $descriptionFormat,
                    categoryId: $categoryId > 0 ? $categoryId : null,
                    thumbnailMediaId: $thumbnailMediaId,
                );

                // Lands on this screen's Edit view (not All Links) so the
                // freshly uploaded thumbnail's preview is visible immediately.
                header('Location: ' . admin_url('link-directory/add-new') . '?id=' . $createdLink->id . '&saved=1');
                exit;
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($form === 'update_link') {
        $id = (int) ($_POST['id'] ?? 0);

        if (Csrf::verify('update_link_' . $id, $token) && $editingLink !== null) {
            $title = trim((string) ($_POST['title'] ?? ''));
            $url = trim((string) ($_POST['url'] ?? ''));
            $description = (string) ($_POST['description'] ?? '');
            $descriptionFormat = ContentFormat::tryFrom((string) ($_POST['description_format'] ?? '')) ?? get_active_editor($currentUser->id);
            $categoryId = (int) ($_POST['category_id'] ?? 0);

            if ($title === '') {
                $error = 'Please enter a title.';
            } elseif ($url === '') {
                $error = 'Please enter a URL.';
            } else {
                // Resolution order matches Posts/Pages' own featured-image
                // handling: an upload wins over "remove", which wins over
                // keeping the current thumbnail.
                $thumbnailMediaId = $editingLink->thumbnailMediaId;

                if (($_POST['remove_thumbnail'] ?? '') === '1') {
                    $thumbnailMediaId = null;
                }

                if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
                    $uploadedThumbnail = $kernel->media->upload($_FILES['thumbnail'], $currentUser->id);
                    $kernel->thumbnails->generate($uploadedThumbnail);
                    $thumbnailMediaId = (int) $uploadedThumbnail['id'];
                }

                $links->update(
                    id: $id,
                    title: $title,
                    url: $url,
                    description: $description,
                    descriptionFormat: $descriptionFormat,
                    categoryId: $categoryId > 0 ? $categoryId : null,
                    thumbnailMediaId: $thumbnailMediaId,
                );

                header('Location: ' . admin_url('link-directory/all-links') . '?saved=1');
                exit;
            }
        }
    }
}

$allLinkCategories = $linkCategories->listAllForTree();
$currentThumbnail = $editingLink?->thumbnailMediaId !== null ? $kernel->media->find($editingLink->thumbnailMediaId) : null;
?>
<h1 class="lp-admin__title"><?= $editingLink !== null ? 'Edit Link' : 'Add New Link' ?></h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Link saved.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <?php if ($editingLink !== null): ?>
        <form method="post" action="<?= esc_url(admin_url('link-directory/add-new')) ?>?id=<?= (int) $editingLink->id ?>" enctype="multipart/form-data">
            <?= Csrf::field('update_link_' . $editingLink->id) ?>
            <input type="hidden" name="form" value="update_link">
            <input type="hidden" name="id" value="<?= (int) $editingLink->id ?>">

            <p class="lp-field">
                <label for="link-directory-title">Title</label>
                <input type="text" id="link-directory-title" name="title" value="<?= esc_attr($editingLink->title) ?>" required>
            </p>

            <p class="lp-field">
                <label for="link-directory-url">URL</label>
                <input type="text" id="link-directory-url" name="url" value="<?= esc_attr($editingLink->url) ?>" placeholder="https://example.com" required>
            </p>

            <p class="lp-field">
                <label for="link-directory-category">Category</label>
                <select id="link-directory-category" name="category_id">
                    <option value="0">(Uncategorized)</option>
                    <?php foreach ($allLinkCategories as $categoryRow): ?>
                        <option value="<?= (int) $categoryRow['category']->id ?>" <?= $editingLink->categoryId === $categoryRow['category']->id ? 'selected' : '' ?>>
                            <?= str_repeat('&nbsp;&nbsp;&nbsp;', $categoryRow['depth']) . esc_html($categoryRow['category']->name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <fieldset class="lp-field">
                <legend>Thumbnail</legend>
                <?php if ($currentThumbnail !== null): ?>
                    <img class="lp-branding-preview" src="<?= esc_url($kernel->media->url($currentThumbnail)) ?>" alt="">
                    <label class="lp-field--checkbox">
                        <input type="checkbox" name="remove_thumbnail" value="1"> Remove current thumbnail
                    </label>
                <?php endif; ?>
                <p class="lp-field">
                    <label for="link-directory-thumbnail">Upload <?= $currentThumbnail !== null ? 'a replacement' : 'a' ?> thumbnail</label>
                    <input type="file" id="link-directory-thumbnail" name="thumbnail" accept="image/*">
                </p>
            </fieldset>

            <p class="lp-field">
                <label for="link-directory-description-format">Description format</label>
                <select id="link-directory-description-format" name="description_format">
                    <?php foreach (ContentFormat::cases() as $formatOption): ?>
                        <option value="<?= esc_attr($formatOption->value) ?>" <?= $editingLink->descriptionFormat === $formatOption ? 'selected' : '' ?>>
                            <?= esc_html($formatOption->label()) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="lp-field">
                <label for="link-directory-description">Description</label>
                <textarea id="link-directory-description" name="description" rows="4"><?= esc_html($editingLink->description) ?></textarea>
            </p>

            <button type="submit" class="lp-button lp-button--primary">Save Changes</button>
        </form>
    <?php else: ?>
        <?php $activeDescriptionFormat = get_active_editor($currentUser->id); ?>
        <form method="post" action="<?= esc_url(admin_url('link-directory/add-new')) ?>" enctype="multipart/form-data">
            <?= Csrf::field('create_link') ?>
            <input type="hidden" name="form" value="create_link">

            <p class="lp-field">
                <label for="link-directory-title">Title</label>
                <input type="text" id="link-directory-title" name="title" required>
            </p>

            <p class="lp-field">
                <label for="link-directory-url">URL</label>
                <input type="text" id="link-directory-url" name="url" placeholder="https://example.com" required>
            </p>

            <p class="lp-field">
                <label for="link-directory-category">Category</label>
                <select id="link-directory-category" name="category_id">
                    <option value="0">(Uncategorized)</option>
                    <?php foreach ($allLinkCategories as $categoryRow): ?>
                        <option value="<?= (int) $categoryRow['category']->id ?>">
                            <?= str_repeat('&nbsp;&nbsp;&nbsp;', $categoryRow['depth']) . esc_html($categoryRow['category']->name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>

            <p class="lp-field">
                <label for="link-directory-thumbnail">Thumbnail</label>
                <input type="file" id="link-directory-thumbnail" name="thumbnail" accept="image/*">
            </p>

            <p class="lp-field">
                <label for="link-directory-description-format">Description format</label>
                <select id="link-directory-description-format" name="description_format">
                    <?php foreach (ContentFormat::cases() as $formatOption): ?>
                        <option value="<?= esc_attr($formatOption->value) ?>" <?= $activeDescriptionFormat === $formatOption ? 'selected' : '' ?>>
                            <?= esc_html($formatOption->label()) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="lp-field">
                <label for="link-directory-description">Description</label>
                <textarea id="link-directory-description" name="description" rows="4"></textarea>
            </p>

            <button type="submit" class="lp-button lp-button--primary">Add Link</button>
        </form>
    <?php endif; ?>
</section>

<details class="lp-admin__panel">
    <summary>New Category</summary>
    <form method="post" action="<?= esc_url(admin_url('link-directory/add-new')) ?><?= $editingLink !== null ? '?id=' . (int) $editingLink->id : '' ?>">
        <?= Csrf::field('create_link_directory_category') ?>
        <input type="hidden" name="form" value="create_link_directory_category">
        <p class="lp-field">
            <label for="new-link-directory-category-name">Name</label>
            <input type="text" id="new-link-directory-category-name" name="name" required>
        </p>
        <button type="submit" class="lp-button lp-button--primary">Create</button>
    </form>
</details>
