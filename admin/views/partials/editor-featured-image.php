<?php

/**
 * Shared Featured Image sidebar box body for the Post and Page editors
 * (LP-083) — included from posts/new.php and pages/new.php's own
 * 'featured_image' sidebar case, which byte-for-byte duplicated this
 * markup before being extracted here.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.8.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\Post|\LumoraPress\Models\Page|null $record The post/page being edited, set by the including view. */
/** @var string $idPrefix 'post' or 'page', matching this project's existing id="post-*"/id="page-*" field convention. */
/** @var array{id: int, file_name: string}|null $currentFeaturedImage */
/** @var array<int, array{id: int, name: string, depth: int}> $editorFolderTree Set by posts/new.php and pages/new.php for the "Insert Image" picker's own Folder filter — reused here for the same filter in this box's picker. */

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}
?>
<?php if ($currentFeaturedImage !== null): ?>
    <img class="lp-branding-preview" src="<?= esc_url($kernel->media->url($currentFeaturedImage)) ?>" alt="">
    <label class="lp-field--checkbox">
        <input type="checkbox" name="remove_featured_image" value="1"> Remove current featured image
    </label>

    <div class="lp-featured-crop" data-lp-featured-crop>
        <button type="button" class="lp-button lp-button--secondary" data-lp-featured-crop-toggle>
            <?= ($record->featuredImageCrop ?? null) !== null ? 'Edit Crop' : 'Add Crop' ?>
        </button>

        <div class="lp-featured-crop__editor" data-lp-featured-crop-editor hidden>
            <div class="lp-featured-crop__stage" data-lp-featured-crop-stage>
                <img src="<?= esc_url($kernel->media->url($currentFeaturedImage)) ?>" alt="" data-lp-featured-crop-image>
                <div class="lp-featured-crop__rect" data-lp-featured-crop-rect hidden>
                    <div class="lp-featured-crop__handle" data-lp-featured-crop-handle></div>
                </div>
            </div>
            <p class="lp-field__hint">Drag to select the area to use as the featured image. Drag inside the selection to move it, or its bottom-right corner to resize it.</p>
            <button type="button" class="lp-button lp-button--link" data-lp-featured-crop-clear>Clear Crop</button>
        </div>

        <input type="hidden" name="featured_image_crop_for_id" value="<?= (int) $currentFeaturedImage['id'] ?>">
        <input type="hidden" name="featured_image_crop_x" data-lp-featured-crop-x value="<?= esc_attr((string) ($record->featuredImageCrop['x'] ?? '')) ?>">
        <input type="hidden" name="featured_image_crop_y" data-lp-featured-crop-y value="<?= esc_attr((string) ($record->featuredImageCrop['y'] ?? '')) ?>">
        <input type="hidden" name="featured_image_crop_width" data-lp-featured-crop-width value="<?= esc_attr((string) ($record->featuredImageCrop['width'] ?? '')) ?>">
        <input type="hidden" name="featured_image_crop_height" data-lp-featured-crop-height value="<?= esc_attr((string) ($record->featuredImageCrop['height'] ?? '')) ?>">
    </div>
<?php endif; ?>

<div
    class="lp-featured-image-picker"
    data-lp-featured-image-picker
    data-picker-url="<?= esc_url(admin_url($idPrefix . 's/new')) ?>"
    data-picker-csrf="<?= esc_attr(Csrf::token('featured_image_picker_query')) ?>"
    data-media-folders="<?= esc_attr((string) json_encode($editorFolderTree)) ?>"
>
    <input type="hidden" name="featured_image_id" data-picker-value value="<?= (int) ($record?->featuredImageId ?? 0) ?>">
    <button type="button" class="lp-button lp-button--secondary" data-picker-trigger>Choose from Media Manager&hellip;</button>
    <span class="lp-featured-image-picker__chosen" data-picker-chosen>
        <?php if ($currentFeaturedImage !== null): ?>
            <img class="lp-featured-image-picker__chosen-thumb" src="<?= esc_url($kernel->media->url($currentFeaturedImage)) ?>" alt="">
            <?= esc_html((string) $currentFeaturedImage['file_name']) ?>
        <?php endif; ?>
    </span>
</div>

<label for="<?= esc_attr($idPrefix) ?>-featured-image-upload">Or upload a new image</label>
<input type="file" id="<?= esc_attr($idPrefix) ?>-featured-image-upload" name="featured_image_upload" accept="image/*">
