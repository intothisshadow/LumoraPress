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

use LumoraPress\Controllers\Admin\ThemeCustomizerController;
use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Theme\ThemeOptionField;
use LumoraPress\Core\Theme\ThemeOptionType;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * LP-123: replaces the single flat Theme Options page with six tabs
 * (Header / Welcome Message / Body / Menu / Widgets / Footer). "Body"
 * groups the pre-existing colors/typography/layout/post_display sections
 * under one tab rather than being a registered ThemeOptions section
 * itself — see $tabSections below. POST handling lives in
 * ThemeCustomizerController (LP-082's extracted-controller pattern, first
 * used by ThemesController) — this view only reads the request,
 * dispatches to the matching controller method, and turns the returned
 * AdminActionResult into a redirect or an inline $error string.
 */
$controller = new ThemeCustomizerController($kernel->themeOptions, $kernel->media);

$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$error = null;
$csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;

if ($form !== '') {
    $result = $form === 'theme_options_header'
        ? $controller->saveHeader($_POST, $_FILES, $currentUser->id, $csrfToken)
        : $controller->saveSection(substr($form, strlen('theme_options_')), $_POST, $csrfToken);

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
];
$tabSections = [
    'header' => ['header'],
    'welcome_message' => ['welcome_message'],
    'body' => ['colors', 'typography', 'layout', 'post_display'],
    'menu' => [],
    'widgets' => [],
    'footer' => ['footer'],
];
$activeTab = in_array($_GET['tab'] ?? '', array_keys($tabs), true) ? $_GET['tab'] : 'header';

$currentHeaderImageId = $kernel->themeOptions->headerImageMediaId();
$currentHeaderImage = $currentHeaderImageId > 0 ? $kernel->media->find($currentHeaderImageId) : null;

$activeThemeInfo = null;
foreach ($kernel->themes->discover() as $themeInfo) {
    if ($themeInfo->isActive) {
        $activeThemeInfo = $themeInfo;

        break;
    }
}

/**
 * Renders one ThemeOptionField's control markup — the same branch-on-type
 * logic theme-options.php used inline, now shared by every section's form
 * on this page, plus a new ThemeOptionType::Html branch reusing
 * content-editor.js's WYSIWYG/Markdown/HTML toggle (see
 * admin/views/pages/new.php's reference markup). Image upload/media-picker
 * wiring is deliberately left unconnected here (data-upload-url etc. are
 * all optional and fail soft per content-editor.js's own docblock) — a
 * Welcome Message/Footer field is typically short text, not full post
 * content, so that plumbing is out of scope for this first pass; embedding
 * an already-uploaded image's URL by hand still works.
 *
 * Local closures rather than global function declarations — no other
 * admin view in this project defines global functions, and this view
 * only ever runs once per request anyway (admin/index.php's dispatch
 * requires exactly one view file), so there's no reuse case for these
 * outside this file.
 */
$renderThemeOptionField = function (ThemeOptionField $field, string $currentValue, string $formatValue = 'html'): void {
    $fieldId = 'theme-option-' . str_replace('_', '-', $field->key);
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
            <div class="lp-content-editor" data-lp-content-editor data-format="<?= esc_attr($formatValue) ?>">
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

/**
 * Renders one registered section as its own form — identical to what
 * theme-options.php rendered for every section flat on the page, now
 * reused per-tab and per-sub-section within the Body tab.
 */
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
                // An Html field's companion {key}_format Select field (see
                // ThemeOptions::registerStandardOptions()) picks which
                // editor mode content-editor.js opens in — read it by the
                // established naming convention rather than hardcoding
                // welcome_message/footer_html specifically.
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

                <p class="lp-field">
                    <label for="header-image">Header image</label>
                    <?php if ($currentHeaderImage !== null): ?>
                        <img class="lp-branding-preview" src="<?= esc_url($kernel->media->url($currentHeaderImage)) ?>" alt="Current header image">
                        <label class="lp-field--checkbox"><input type="checkbox" name="remove_header_image" value="1"> Remove current header image</label>
                    <?php endif; ?>
                    <input type="file" id="header-image" name="header_image" accept="image/*">
                </p>

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
</div>
