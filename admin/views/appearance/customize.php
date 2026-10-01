<?php

/**
 * The admin Appearance > Customize screen (LP-123): a tabbed reorganization of LP-034's Theme Options.
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
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Controllers\Admin\FeaturedImageDefaultsController;
use LumoraPress\Controllers\Admin\ThemeCustomizerController;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Theme\ThemeOptionField;
use LumoraPress\Core\Theme\ThemeOptionType;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// "Body" groups the colors/typography/layout/post_display sections under
// one tab rather than being its own registered ThemeOptions section. POST
// handling lives in ThemeCustomizerController; this view just dispatches
// to it and turns the AdminActionResult into a redirect or $error string.
$controller = new ThemeCustomizerController($kernel->themeOptions, $kernel->media);

$featuredImageDefaults = new FeaturedImageDefaultsController($kernel->config, $kernel->media, $kernel->thumbnails);

$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$error = null;
$csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

// The default-image picker (featured-image-picker.js) queries this page for JSON.
if ($form === FeaturedImageDefaultsController::PICKER_CSRF_ACTION || $form === FeaturedImageDefaultsController::HEADER_PICKER_CSRF_ACTION) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $pickerResponse = $featuredImageDefaults->pickerQuery($_POST, $currentUser->can('upload_files'), $csrfToken, $form);
    http_response_code($pickerResponse['status']);
    header('Content-Type: application/json');
    echo json_encode($pickerResponse['body']);
    exit;
}

if ($form !== '') {
    $result = match (true) {
        $form === 'theme_options_header' => $controller->saveHeader($_POST, $_FILES, $currentUser->id, $csrfToken),
        $form === FeaturedImageDefaultsController::CSRF_ACTION => $featuredImageDefaults->save($_POST, $currentUser->can('manage_options'), $csrfToken),
        default => $controller->saveSection(substr($form, strlen('theme_options_')), $_POST, $csrfToken),
    };

    if ($result->redirectUrl !== null) {
        redirect($result->redirectUrl);
    }

    $error = $result->errorMessage;
}

$tabs = [
    'header' => 'Header',
    'welcome_message' => 'Welcome Message',
    'body' => 'Body',
    'menu' => 'Menu',
    'widgets' => 'Widgets',
    'footer' => 'Footer',
    'theme' => 'Theme',
];
$tabSections = [
    'header' => ['header'],
    'welcome_message' => ['welcome_message'],
    'body' => ['colors', 'typography', 'layout', 'post_display', 'featured_image'],
    'menu' => [],
    'widgets' => [],
    'footer' => ['footer'],
    'theme' => [],
];

// Theme-defined sections are registered after the built-ins; they get a
// tab of their own after Footer, named for the active theme, so this
// screen never needs to know their keys.
$renderedSectionKeys = array_merge(...array_values($tabSections));
foreach ($kernel->themeOptions->sections() as $sectionKey => $section) {
    if (!in_array($sectionKey, $renderedSectionKeys, true)) {
        $tabSections['theme'][] = $sectionKey;
    }
}

if ($tabSections['theme'] === []) {
    unset($tabs['theme'], $tabSections['theme']);
}

$activeTab = in_array($_GET['tab'] ?? '', array_keys($tabs), true) ? $_GET['tab'] : 'header';

$currentHeaderImages = array_values(array_filter(array_map(
    fn (int $mediaId): ?array => $kernel->media->find($mediaId),
    $kernel->themeOptions->headerImageMediaIds(),
)));
$headerPickerFolders = array_map(
    static fn (array $row): array => ['id' => $row['folder']->id, 'name' => $row['folder']->name, 'depth' => $row['depth']],
    $kernel->folders->listAllForTree(),
);

$activeThemeInfo = null;
foreach ($kernel->themes->discover() as $themeInfo) {
    if ($themeInfo->isActive) {
        $activeThemeInfo = $themeInfo;

        break;
    }
}

if (isset($tabs['theme'])) {
    $tabs['theme'] = $activeThemeInfo->name ?? 'Theme';
}

// Renders one ThemeOptionField's control, shared by every section's form
// here. The Html branch reuses content-editor.js's WYSIWYG/Markdown/HTML
// toggle but leaves its media-picker wiring unconnected — a Welcome
// Message/Footer field is typically short text, not full post content.
$renderThemeOptionField = function (ThemeOptionField $field, string $currentValue, string $formatValue = 'html'): void {
    $fieldId = 'theme-option-' . str_replace('_', '-', $field->key);

    // featured_image_position gets a bespoke clickable visual picker
    // (two small layout diagrams) instead of the plain <select> every
    // other Select field falls through to below — a purely presentational
    // choice, not a new generic field type, since nothing else in this
    // codebase needs an illustrated picker yet. The choice→diagram mapping
    // is hardcoded to this field's two known values ('above'/'beside');
    // the "Default" badge itself is data-driven off $field->default, not
    // hardcoded to either value.
    if ($field->key === 'featured_image_position') {
        ?>
        <fieldset class="lp-field lp-field--visual-choice">
            <legend><?= esc_html($field->label) ?></legend>
            <div class="lp-visual-choice">
                <?php foreach ($field->choices as $choiceValue => $choiceLabel): ?>
                    <?php $choiceId = $fieldId . '-' . $choiceValue; ?>
                    <label class="lp-visual-choice__option" for="<?= esc_attr($choiceId) ?>">
                        <input
                            type="radio"
                            id="<?= esc_attr($choiceId) ?>"
                            name="opt_<?= esc_attr($field->key) ?>"
                            value="<?= esc_attr($choiceValue) ?>"
                            class="lp-visual-choice__input"
                            <?= $currentValue === $choiceValue ? 'checked' : '' ?>
                        >
                        <span class="lp-visual-choice__preview">
                            <?php if ($choiceValue === 'beside'): ?>
                                <svg class="lp-visual-choice__diagram" viewBox="0 0 96 68" aria-hidden="true" focusable="false">
                                    <rect class="lp-visual-choice__image" x="4" y="4" width="32" height="60" rx="3"/>
                                    <rect class="lp-visual-choice__heading" x="44" y="8" width="48" height="7" rx="2"/>
                                    <rect class="lp-visual-choice__line" x="44" y="21" width="48" height="4" rx="2"/>
                                    <rect class="lp-visual-choice__line" x="44" y="28" width="48" height="4" rx="2"/>
                                    <rect class="lp-visual-choice__line" x="44" y="35" width="30" height="4" rx="2"/>
                                </svg>
                            <?php else: ?>
                                <svg class="lp-visual-choice__diagram" viewBox="0 0 96 68" aria-hidden="true" focusable="false">
                                    <rect class="lp-visual-choice__image" x="4" y="4" width="88" height="30" rx="3"/>
                                    <rect class="lp-visual-choice__heading" x="4" y="40" width="50" height="7" rx="2"/>
                                    <rect class="lp-visual-choice__line" x="4" y="51" width="88" height="4" rx="2"/>
                                    <rect class="lp-visual-choice__line" x="4" y="58" width="64" height="4" rx="2"/>
                                </svg>
                            <?php endif; ?>
                        </span>
                        <span class="lp-visual-choice__caption">
                            <?= esc_html($choiceLabel) ?>
                            <?php if ($choiceValue === $field->default): ?>
                                <span class="lp-visual-choice__badge">Default</span>
                            <?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php if ($field->help !== ''): ?>
                <span class="lp-field__hint"><?= esc_html($field->help) ?></span>
            <?php endif; ?>
        </fieldset>
        <?php

        return;
    }
    ?>
    <p class="lp-field">
        <label for="<?= esc_attr($fieldId) ?>"><?= esc_html($field->label) ?></label>
        <?php if ($field->type === ThemeOptionType::Color): ?>
            <input
                type="color"
                id="<?= esc_attr($fieldId) ?>"
                name="opt_<?= esc_attr($field->key) ?>"
                value="<?= esc_attr($currentValue !== '' ? $currentValue : (string) $field->previewDefault) ?>"
                data-lp-color-reset-target="<?= esc_attr($fieldId) ?>-reset"
            >
            <label class="lp-field--checkbox">
                <input type="checkbox" id="<?= esc_attr($fieldId) ?>-reset" name="reset_<?= esc_attr($field->key) ?>" value="1" <?= $currentValue === '' ? 'checked' : '' ?>>
                Use theme default
            </label>
        <?php elseif ($field->type === ThemeOptionType::Select): ?>
            <select id="<?= esc_attr($fieldId) ?>" name="opt_<?= esc_attr($field->key) ?>">
                <?php foreach ($field->choices as $choiceValue => $choiceLabel): ?>
                    <option value="<?= esc_attr($choiceValue) ?>" <?= $currentValue === $choiceValue ? 'selected' : '' ?>><?= esc_html($choiceLabel) ?></option>
                <?php endforeach; ?>
            </select>
        <?php elseif ($field->type === ThemeOptionType::Number): ?>
            <input
                type="number"
                id="<?= esc_attr($fieldId) ?>"
                name="opt_<?= esc_attr($field->key) ?>"
                value="<?= esc_attr($currentValue) ?>"
                <?= $field->min !== null ? 'min="' . esc_attr((string) $field->min) . '"' : '' ?>
                <?= $field->max !== null ? 'max="' . esc_attr((string) $field->max) . '"' : '' ?>
            >
        <?php elseif ($field->type === ThemeOptionType::Checkbox): ?>
            <label class="lp-field--checkbox">
                <input type="checkbox" id="<?= esc_attr($fieldId) ?>" name="opt_<?= esc_attr($field->key) ?>" value="1" <?= $currentValue === '1' ? 'checked' : '' ?>>
                <?= esc_html($field->help !== '' ? $field->help : $field->label) ?>
            </label>
        <?php elseif ($field->type === ThemeOptionType::Textarea): ?>
            <textarea id="<?= esc_attr($fieldId) ?>" name="opt_<?= esc_attr($field->key) ?>" rows="4"><?= esc_html($currentValue) ?></textarea>
        <?php elseif ($field->type === ThemeOptionType::Url): ?>
            <input type="url" id="<?= esc_attr($fieldId) ?>" name="opt_<?= esc_attr($field->key) ?>" value="<?= esc_attr($currentValue) ?>" placeholder="https://fonts.googleapis.com/css2?family=...">
        <?php elseif ($field->type === ThemeOptionType::Html): ?>
            <div class="lp-content-editor" data-lp-content-editor data-format="<?= esc_attr($formatValue) ?>" data-more-tag-stylesheet="<?= esc_url(admin_asset_url('css/content-editor-iframe.css')) ?>">
                <textarea id="<?= esc_attr($fieldId) ?>" name="opt_<?= esc_attr($field->key) ?>" rows="8"><?= esc_html($currentValue) ?></textarea>
            </div>
        <?php else: ?>
            <input type="text" id="<?= esc_attr($fieldId) ?>" name="opt_<?= esc_attr($field->key) ?>" value="<?= esc_attr($currentValue) ?>">
        <?php endif; ?>
        <?php if ($field->help !== '' && $field->type !== ThemeOptionType::Checkbox): ?>
            <span class="lp-field__hint"><?= esc_html($field->help) ?></span>
        <?php endif; ?>
    </p>
    <?php
};

// Renders one registered section as its own form, reused per-tab and
// per-sub-section within the Body tab.
$renderThemeOptionSection = function (string $sectionKey) use ($kernel, $renderThemeOptionField): void {
    $section = $kernel->themeOptions->sections()[$sectionKey] ?? null;

    if ($section === null) {
        return;
    }

    $csrfAction = 'theme_options_' . $section->key;
    ?>
    <section class="lp-admin__panel">
        <h2><?= esc_html($section->label) ?></h2>
        <?php if ($section->description !== ''): ?>
            <p class="lp-field__hint"><?= esc_html($section->description) ?></p>
        <?php endif; ?>
        <form method="post" action="<?= esc_url(admin_url('appearance/customize')) ?>?tab=<?= esc_attr($_GET['tab'] ?? 'header') ?>">
            <?= Csrf::field($csrfAction) ?>
            <input type="hidden" name="form" value="<?= esc_attr($csrfAction) ?>">

            <?php foreach ($kernel->themeOptions->fieldsForSection($section->key) as $field): ?>
                <?php
                // An Html field's companion {key}_format Select field picks
                // its content-editor.js mode, read by naming convention.
                $formatValue = $field->type === ThemeOptionType::Html
                    ? $kernel->themeOptions->value($field->key . '_format')
                    : 'html';
                $renderThemeOptionField($field, $kernel->themeOptions->value($field->key), $formatValue);
                ?>
            <?php endforeach; ?>

            <button type="submit" name="submit_action" value="save" class="lp-button lp-button--primary">Save <?= esc_html($section->label) ?></button>
            <button type="submit" name="submit_action" value="reset" class="lp-button lp-button--secondary" data-lp-confirm="Reset every <?= esc_attr(strtolower($section->label)) ?> option back to its default?">Reset to Defaults</button>
        </form>
    </section>
    <?php
};

// Site-wide featured image defaults — not per-theme, so they get their own
// form rather than being part of the Featured Image Theme Options section.
$renderFeaturedImageDefaults = function () use ($kernel, $currentUser, $featuredImageDefaults): void {
    if (!$currentUser->can('manage_options')) {
        return;
    }

    $defaultImageId = (int) $kernel->config->option('default_featured_image_media_id', '0');
    $defaultImage = $defaultImageId > 0 ? $kernel->media->find($defaultImageId) : null;
    $folderTree = array_map(
        static fn (array $row): array => ['id' => $row['folder']->id, 'name' => $row['folder']->name, 'depth' => $row['depth']],
        $kernel->folders->listAllForTree(),
    );
    $currentCropSize = (string) $kernel->config->option('featured_image_crop_size', 'large');
    ?>
    <section class="lp-admin__panel">
        <h2>Featured Image Defaults</h2>
        <p class="lp-field__hint">These apply to every theme, not only the active one.</p>
        <form method="post" action="<?= esc_url(admin_url('appearance/customize')) ?>?tab=body">
            <?= Csrf::field(FeaturedImageDefaultsController::CSRF_ACTION) ?>
            <input type="hidden" name="form" value="<?= esc_attr(FeaturedImageDefaultsController::CSRF_ACTION) ?>">

            <fieldset class="lp-field">
                <legend>Default featured image</legend>
                <div
                    class="lp-featured-image-picker"
                    data-lp-featured-image-picker
                    data-picker-url="<?= esc_url(admin_url('appearance/customize')) ?>"
                    data-picker-csrf="<?= esc_attr(Csrf::token(FeaturedImageDefaultsController::PICKER_CSRF_ACTION)) ?>"
                    data-media-folders="<?= esc_attr((string) json_encode($folderTree)) ?>"
                >
                    <input type="hidden" name="default_featured_image_media_id" data-picker-value value="<?= $defaultImageId ?>">
                    <button type="button" class="lp-button lp-button--secondary" data-picker-trigger>Choose from Media Manager&hellip;</button>
                    <button type="button" class="lp-button lp-button--link" data-picker-remove <?= $defaultImage === null ? 'hidden' : '' ?>>Remove</button>
                    <span class="lp-featured-image-picker__chosen" data-picker-chosen>
                        <?php if ($defaultImage !== null): ?>
                            <img class="lp-featured-image-picker__chosen-thumb" src="<?= esc_url($kernel->media->url($defaultImage)) ?>" alt="">
                            <?= esc_html((string) $defaultImage['file_name']) ?>
                        <?php endif; ?>
                    </span>
                </div>
                <p class="lp-field__hint">Shown as the featured image for posts and pages that don't have one of their own.</p>
            </fieldset>

            <p class="lp-field">
                <label class="lp-field--checkbox">
                    <input type="checkbox" name="default_featured_image_on_pages" value="1" <?= $kernel->config->option('default_featured_image_on_pages', '1') !== '0' ? 'checked' : '' ?>>
                    Use the default featured image on pages
                </label>
                <span class="lp-field__hint">Turn this off to show no automatic image on pages. A page that has its own featured image, set when adding or editing the page, always shows it.</span>
            </p>

            <p class="lp-field">
                <label for="featured-image-crop-size">Featured image crop size</label>
                <select id="featured-image-crop-size" name="featured_image_crop_size">
                    <?php foreach ($featuredImageDefaults->enabledCropSizes() as $cropSizeName): ?>
                        <option value="<?= esc_attr($cropSizeName) ?>" <?= $currentCropSize === $cropSizeName ? 'selected' : '' ?>><?= esc_html(ucfirst($cropSizeName)) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="lp-field__hint">The maximum width a manually cropped featured image (drawn in the post or page editor) is generated at. Defaults to <strong>Large</strong>.</span>
            </p>

            <button type="submit" class="lp-button lp-button--primary">Save Featured Image Defaults</button>
        </form>
    </section>
    <?php
};
?>
<h1 class="lp-admin__title">Appearance</h1>

<?php if (isset($_GET['saved']) && $error === null): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<p class="lp-field__hint">
    These options override the active theme's own defaults. A theme is always free to ignore any of them if it doesn't use the matching CSS variable.
    Values here apply only to the active theme (<?= esc_html($activeThemeInfo->name ?? 'the current theme') ?>) — switching themes shows that theme's own independent set of values.
    Need to start over? Use <a href="<?= esc_url(admin_url('appearance/reset')) ?>">Reset Theme Options</a>.
</p>

<div class="lp-tabs">
    <div class="lp-tabs__list" role="tablist" aria-label="Customize section">
        <?php foreach ($tabs as $tabKey => $tabLabel): ?>
            <button type="button" class="lp-tabs__tab" id="lp-tab-<?= esc_attr($tabKey) ?>" role="tab" aria-selected="<?= $activeTab === $tabKey ? 'true' : 'false' ?>" aria-controls="lp-tabpanel-<?= esc_attr($tabKey) ?>" tabindex="<?= $activeTab === $tabKey ? '0' : '-1' ?>"><?= esc_html($tabLabel) ?></button>
        <?php endforeach; ?>
    </div>

    <div class="lp-tabs__panel" id="lp-tabpanel-header" role="tabpanel" aria-labelledby="lp-tab-header"<?= $activeTab === 'header' ? '' : ' hidden' ?>>
        <section class="lp-admin__panel">
            <h2>Header</h2>
            <p class="lp-field__hint">Controls what appears in the site header above the navigation.</p>
            <form method="post" action="<?= esc_url(admin_url('appearance/customize')) ?>?tab=header" enctype="multipart/form-data">
                <?= Csrf::field('theme_options_header') ?>
                <input type="hidden" name="form" value="theme_options_header">

                <?php foreach ($kernel->themeOptions->fieldsForSection('header') as $field): ?>
                    <?php $renderThemeOptionField($field, $kernel->themeOptions->value($field->key)); ?>
                <?php endforeach; ?>

                <fieldset class="lp-field lp-header-images" data-lp-header-images>
                    <legend>Header images</legend>
                    <input type="hidden" name="header_images_submitted" value="1">
                    <ul class="lp-header-images__list" data-lp-header-images-list>
                        <?php foreach ($currentHeaderImages as $headerImage): ?>
                            <li class="lp-header-images__item" data-media-id="<?= (int) $headerImage['id'] ?>">
                                <input type="hidden" name="header_image_ids[]" value="<?= (int) $headerImage['id'] ?>">
                                <img class="lp-header-images__thumb" src="<?= esc_url($kernel->media->url($headerImage)) ?>" alt="">
                                <span class="lp-header-images__name"><?= esc_html((string) $headerImage['file_name']) ?></span>
                                <button type="button" class="lp-button lp-button--link lp-button--link--danger" data-lp-header-image-remove>Remove</button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="lp-field__hint lp-header-images__empty" data-lp-header-images-empty <?= $currentHeaderImages === [] ? '' : 'hidden' ?>>No header images yet.</p>

                    <div
                        class="lp-featured-image-picker"
                        data-lp-featured-image-picker
                        data-picker-multiple="1"
                        data-picker-action="<?= esc_attr(FeaturedImageDefaultsController::HEADER_PICKER_CSRF_ACTION) ?>"
                        data-picker-url="<?= esc_url(admin_url('appearance/customize')) ?>"
                        data-picker-csrf="<?= esc_attr(Csrf::token(FeaturedImageDefaultsController::HEADER_PICKER_CSRF_ACTION)) ?>"
                        data-media-folders="<?= esc_attr((string) json_encode($headerPickerFolders)) ?>"
                        data-lp-header-images-picker
                    >
                        <button type="button" class="lp-button lp-button--secondary" data-picker-trigger>Choose from Media Manager&hellip;</button>
                    </div>

                    <p class="lp-field">
                        <label for="header-images-upload">Or upload new images</label>
                        <input type="file" id="header-images-upload" name="header_images[]" accept="image/*" multiple>
                    </p>
                    <p class="lp-field__hint">The images are used in the order they were added. With more than one, "Header image order" above decides whether each page view shows the next one or a random one. Changes here take effect when you save.</p>
                </fieldset>

                <button type="submit" name="submit_action" value="save" class="lp-button lp-button--primary">Save Header</button>
                <button type="submit" name="submit_action" value="reset" class="lp-button lp-button--secondary" data-lp-confirm="Reset every header option, including the header image, back to its default?">Reset to Defaults</button>
            </form>
        </section>
    </div>

    <div class="lp-tabs__panel" id="lp-tabpanel-welcome_message" role="tabpanel" aria-labelledby="lp-tab-welcome_message"<?= $activeTab === 'welcome_message' ? '' : ' hidden' ?>>
        <?php $renderThemeOptionSection('welcome_message'); ?>
    </div>

    <div class="lp-tabs__panel" id="lp-tabpanel-body" role="tabpanel" aria-labelledby="lp-tab-body"<?= $activeTab === 'body' ? '' : ' hidden' ?>>
        <?php foreach ($tabSections['body'] as $sectionKey): ?>
            <?php $renderThemeOptionSection($sectionKey); ?>
            <?php if ($sectionKey === 'featured_image'): ?>
                <?php $renderFeaturedImageDefaults(); ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="lp-tabs__panel" id="lp-tabpanel-menu" role="tabpanel" aria-labelledby="lp-tab-menu"<?= $activeTab === 'menu' ? '' : ' hidden' ?>>
        <section class="lp-admin__panel">
            <h2>Menu</h2>
            <p class="lp-field__hint">Assign navigation menus to the theme's primary and footer menu locations.</p>
            <a class="lp-button lp-button--primary" href="<?= esc_url(admin_url('appearance/menus')) ?>">Manage Menus</a>
        </section>
    </div>

    <div class="lp-tabs__panel" id="lp-tabpanel-widgets" role="tabpanel" aria-labelledby="lp-tab-widgets"<?= $activeTab === 'widgets' ? '' : ' hidden' ?>>
        <section class="lp-admin__panel">
            <h2>Widgets</h2>
            <p class="lp-field__hint">Add and arrange widgets in the theme's sidebar and footer widget areas.</p>
            <a class="lp-button lp-button--primary" href="<?= esc_url(admin_url('appearance/widgets')) ?>">Manage Widgets</a>
        </section>
    </div>

    <div class="lp-tabs__panel" id="lp-tabpanel-footer" role="tabpanel" aria-labelledby="lp-tab-footer"<?= $activeTab === 'footer' ? '' : ' hidden' ?>>
        <?php $renderThemeOptionSection('footer'); ?>
    </div>

    <?php if (isset($tabs['theme'])): ?>
        <div class="lp-tabs__panel" id="lp-tabpanel-theme" role="tabpanel" aria-labelledby="lp-tab-theme"<?= $activeTab === 'theme' ? '' : ' hidden' ?>>
            <?php foreach ($tabSections['theme'] as $sectionKey): ?>
                <?php $renderThemeOptionSection($sectionKey); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
