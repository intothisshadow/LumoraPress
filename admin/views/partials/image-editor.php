<?php

/**
 * Image editor markup (crop, rotate, flip), shared by the Media Manager's
 * edit screen (inline) and the editor dialog (a template cloned by
 * image-editor.js on any admin page where images are picked).
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.21.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var bool $imageEditorInline True on the Media Manager's edit screen; false for the dialog template. */
/** @var array<string, mixed>|null $imageEditorMedia The image being edited (inline only). */

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// Wrapping labels (not for/id pairs) keep every id out of this markup, so the dialog's
// copy never duplicates ids already on the page.
?>
<div
    class="lp-image-editor<?= $imageEditorInline ? '' : ' lp-image-editor--dialog' ?>"
    data-lp-image-editor
    <?php if ($imageEditorInline && $imageEditorMedia !== null): ?>
        data-src="<?= esc_url($kernel->media->url($imageEditorMedia)) ?>"
    <?php endif; ?>
>
    <?php if ($imageEditorInline): ?>
        <button type="button" class="lp-button lp-button--secondary" data-lp-image-editor-toggle aria-expanded="false" aria-controls="lp-image-editor-form">Edit Image</button>
    <?php endif; ?>

    <form
        method="post"
        <?php if ($imageEditorInline && $imageEditorMedia !== null): ?>
            action="<?= esc_url(admin_url('media/media')) ?>"
            id="lp-image-editor-form"
            hidden
        <?php endif; ?>
        class="lp-image-editor__form"
        data-lp-image-editor-form
    >
        <?php if ($imageEditorInline && $imageEditorMedia !== null): ?>
            <?= Csrf::field('edit_image') ?>
            <input type="hidden" name="form" value="edit_image">
            <input type="hidden" name="id" value="<?= (int) $imageEditorMedia['id'] ?>">
        <?php endif; ?>
        <input type="hidden" name="rotate" value="0" data-lp-image-editor-rotate>
        <input type="hidden" name="flip_h" value="0" data-lp-image-editor-flip-h>
        <input type="hidden" name="flip_v" value="0" data-lp-image-editor-flip-v>

        <p class="lp-alert lp-alert--error" role="alert" data-lp-image-editor-error hidden></p>

        <div class="lp-image-editor__toolbar" role="toolbar" aria-label="Image tools">
            <button type="button" class="lp-button lp-button--secondary" data-lp-image-editor-action="rotate-left">Rotate Left</button>
            <button type="button" class="lp-button lp-button--secondary" data-lp-image-editor-action="rotate-right">Rotate Right</button>
            <button type="button" class="lp-button lp-button--secondary" data-lp-image-editor-action="flip-h" aria-pressed="false">Flip Horizontal</button>
            <button type="button" class="lp-button lp-button--secondary" data-lp-image-editor-action="flip-v" aria-pressed="false">Flip Vertical</button>
            <button type="button" class="lp-button lp-button--link" data-lp-image-editor-action="reset">Reset</button>
        </div>

        <div class="lp-image-editor__stage" data-lp-image-editor-stage>
            <canvas class="lp-image-editor__canvas" data-lp-image-editor-canvas role="img" aria-label="Preview of the image being edited"></canvas>
            <div class="lp-image-editor__rect" data-lp-image-editor-rect hidden>
                <span class="lp-image-editor__handle lp-image-editor__handle--nw" data-handle="nw"></span>
                <span class="lp-image-editor__handle lp-image-editor__handle--ne" data-handle="ne"></span>
                <span class="lp-image-editor__handle lp-image-editor__handle--sw" data-handle="sw"></span>
                <span class="lp-image-editor__handle lp-image-editor__handle--se" data-handle="se"></span>
            </div>
        </div>
        <p class="lp-field__hint">Drag on the image to select a crop area, drag inside it to move it, or drag a corner to resize it. Rotating or flipping clears the crop area.</p>
        <p class="lp-field__hint" role="status" data-lp-image-editor-status></p>

        <label class="lp-image-editor__field">
            <span>Crop shape</span>
            <select data-lp-image-editor-ratio>
                <option value="free">Free</option>
                <option value="original">Same as image</option>
                <option value="1:1">Square (1:1)</option>
                <option value="4:3">4:3</option>
                <option value="3:2">3:2</option>
                <option value="16:9">16:9</option>
            </select>
        </label>

        <fieldset class="lp-image-editor__numbers">
            <legend>Crop area (pixels, after any rotation)</legend>
            <label>X <input type="number" min="0" name="crop_x" data-lp-image-editor-x></label>
            <label>Y <input type="number" min="0" name="crop_y" data-lp-image-editor-y></label>
            <label>Width <input type="number" min="1" name="crop_width" data-lp-image-editor-width></label>
            <label>Height <input type="number" min="1" name="crop_height" data-lp-image-editor-height></label>
            <button type="button" class="lp-button lp-button--link" data-lp-image-editor-action="clear-crop">Clear crop</button>
        </fieldset>

        <label class="lp-image-editor__field">
            <span>Output size</span>
            <select name="output_size">
                <option value="original">Keep full resolution</option>
                <option value="featured">Shrink to the featured image width (<?= (int) $kernel->thumbnails->featuredCropMaxWidth() ?> px)</option>
            </select>
        </label>

        <div class="lp-image-editor__actions">
            <button type="submit" name="save_mode" value="copy" class="lp-button lp-button--primary">Save as New Copy</button>
            <button type="submit" name="save_mode" value="replace" class="lp-button lp-button--danger" data-lp-confirm="Replace the original file with the edited image? This can't be undone.">Replace Original</button>
            <?php if (!$imageEditorInline): ?>
                <button type="button" class="lp-button" data-lp-image-editor-cancel>Cancel</button>
            <?php endif; ?>
        </div>
    </form>
</div>
