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

use LumoraPress\Controllers\Admin\FeaturedImageDefaultsController;
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

// The button picker (featured-image-picker.js) queries this page for JSON.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === FeaturedImageDefaultsController::LINK_BUTTON_PICKER_CSRF_ACTION) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $pickerResponse = (new FeaturedImageDefaultsController($kernel->config, $kernel->media, $kernel->thumbnails))->pickerQuery(
        $_POST,
        $currentUser->can('upload_files'),
        is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null,
        FeaturedImageDefaultsController::LINK_BUTTON_PICKER_CSRF_ACTION,
    );
    http_response_code($pickerResponse['status']);
    header('Content-Type: application/json');
    echo json_encode($pickerResponse['body']);
    exit;
}

/**
 * Which Media item the submitted form wants as the link button: an upload
 * wins over the picker's value, which wins over keeping $current. Only an
 * existing image is accepted, so a stale or tampered id can't point the
 * button at a non-image.
 */
$resolveButtonMediaId = static function (?int $current) use ($kernel, $currentUser): ?int {
    $submitted = isset($_POST['button_media_id']) ? (int) $_POST['button_media_id'] : (int) $current;
    $buttonMediaId = $submitted > 0 ? $submitted : null;

    if ($buttonMediaId !== null) {
        $item = $kernel->media->find($buttonMediaId);

        if ($item === null || !str_starts_with((string) ($item['mime_type'] ?? ''), 'image/')) {
            $buttonMediaId = null;
        }
    }

    if (isset($_FILES['button_upload']) && $_FILES['button_upload']['error'] === UPLOAD_ERR_OK) {
        $uploadedButton = $kernel->media->upload($_FILES['button_upload'], $currentUser->id);
        $kernel->thumbnails->generate($uploadedButton);
        $buttonMediaId = (int) $uploadedButton['id'];
    }

    return $buttonMediaId;
};

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
                    buttonMediaId: $resolveButtonMediaId(null),
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
                    buttonMediaId: $resolveButtonMediaId($editingLink->buttonMediaId),
                );

                header('Location: ' . admin_url('link-directory/add-new') . '?id=' . $id . '&saved=1');
                exit;
            }
        }
    }
}

$allLinkCategories = $linkCategories->listAllForTree();
$currentThumbnail = $editingLink?->thumbnailMediaId !== null ? $kernel->media->find($editingLink->thumbnailMediaId) : null;

/**
 * The 100×50 button field, shared by the Add and Edit forms. Any image is
 * accepted and never resized on disk; a differently sized one only earns a hint.
 *
 * @param array<string, mixed>|null $currentButton
 */
$renderButtonField = static function (?array $currentButton) use ($kernel): void {
    $folderTree = array_map(
        static fn (array $row): array => ['id' => $row['folder']->id, 'name' => $row['folder']->name, 'depth' => $row['depth']],
        $kernel->folders->listAllForTree(),
    );
    $wrongSize = $currentButton !== null
        && ((int) ($currentButton['width'] ?? 0) !== 100 || (int) ($currentButton['height'] ?? 0) !== 50);
    ?>
    <fieldset class="lp-field lp-link-button-field">
        <legend>Link button (100&times;50)</legend>
        <div
            class="lp-featured-image-picker lp-link-button-picker"
            data-lp-featured-image-picker
            data-picker-url="<?= esc_url(admin_url('link-directory/add-new')) ?>"
            data-picker-action="<?= esc_attr(FeaturedImageDefaultsController::LINK_BUTTON_PICKER_CSRF_ACTION) ?>"
            data-picker-csrf="<?= esc_attr(Csrf::token(FeaturedImageDefaultsController::LINK_BUTTON_PICKER_CSRF_ACTION)) ?>"
            data-media-folders="<?= esc_attr((string) json_encode($folderTree)) ?>"
        >
            <input type="hidden" name="button_media_id" data-picker-value value="<?= (int) ($currentButton['id'] ?? 0) ?>">
            <button type="button" class="lp-button lp-button--secondary" data-picker-trigger>Choose from Media Manager&hellip;</button>
            <button type="button" class="lp-button lp-button--link lp-button--link--danger" data-picker-remove <?= $currentButton === null ? 'hidden' : '' ?>>Remove</button>
            <span class="lp-featured-image-picker__chosen" data-picker-chosen>
                <?php if ($currentButton !== null): ?>
                    <img class="lp-featured-image-picker__chosen-thumb" src="<?= esc_url($kernel->media->url($currentButton)) ?>" alt="">
                    <?= esc_html((string) $currentButton['file_name']) ?>
                <?php endif; ?>
            </span>
        </div>
        <p class="lp-field">
            <label for="link-directory-button-upload">Or upload a button</label>
            <input type="file" id="link-directory-button-upload" name="button_upload" accept="image/*">
        </p>
        <span class="lp-field__hint">The classic badge other sites trade for linking. It's shown at 100&times;50 pixels; an image of another size is scaled, not cropped.</span>
        <?php if ($wrongSize): ?>
            <span class="lp-field__hint lp-link-button-field__warning">The chosen image is <?= (int) $currentButton['width'] ?>&times;<?= (int) $currentButton['height'] ?> pixels, so it will look stretched or squashed at 100&times;50.</span>
        <?php endif; ?>
    </fieldset>
    <?php
};
$currentButton = $editingLink?->buttonMediaId !== null ? $kernel->media->find($editingLink->buttonMediaId) : null;
?>
<h1 class="lp-admin__title"><?= $editingLink !== null ? 'Edit Link' : 'Add New Link' ?></h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Link saved.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2><?= $editingLink !== null ? 'Edit Link' : 'New Link' ?></h2>
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

            <?php $renderButtonField($currentButton); ?>

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

            <?php $renderButtonField(null); ?>

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
