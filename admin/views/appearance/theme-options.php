<?php
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Core\Theme\ThemeOptionField;
use LumoraPress\Core\Theme\ThemeOptionType;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * LP-034 (originally scoped as LP-023 — see DECISIONS.md's "LP-023 merged
 * into LP-034" entry): one auto-generated form per registered section
 * (LP-034's "Automatically generate forms" / "Group options into
 * sections"), each with its own CSRF action name — this page renders
 * three of these side by side, and SESSION.md's LP-012 postmortem is
 * explicit that every form actually rendered on one page needs a unique
 * CSRF action name, not just every page.
 */
$errors = [];
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

if ($form === 'theme_options_reset_all' && Csrf::verify('theme_options_reset_all', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $kernel->themeOptions->resetAll();

    header('Location: ' . admin_url('appearance/theme-options') . '?saved=1');
    exit;
}

foreach ($kernel->themeOptions->sections() as $section) {
    $csrfAction = 'theme_options_' . $section->key;

    if ($form !== $csrfAction) {
        continue;
    }

    if (!Csrf::verify($csrfAction, is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        break;
    }

    if (($_POST['submit_action'] ?? 'save') === 'reset') {
        $kernel->themeOptions->resetSection($section->key);
    } else {
        foreach ($kernel->themeOptions->fieldsForSection($section->key) as $field) {
            $postKey = 'opt_' . $field->key;

            if ($field->allowEmpty && ($_POST['reset_' . $field->key] ?? '') === '1') {
                $kernel->themeOptions->reset($field->key);

                continue;
            }

            $rawValue = $field->type === ThemeOptionType::Checkbox
                ? (isset($_POST[$postKey]) ? '1' : '0')
                : (string) ($_POST[$postKey] ?? '');

            if (!$kernel->themeOptions->set($field->key, $rawValue)) {
                $errors[] = $field->label;
            }
        }
    }

    if ($errors === []) {
        header('Location: ' . admin_url('appearance/theme-options') . '?saved=1');
        exit;
    }
}
?>
<h1 class="lp-admin__title">Appearance</h1>

<?php if (isset($_GET['saved']) && $errors === []): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="lp-alert lp-alert--error">Couldn't save: <?= esc_html(implode(', ', $errors)) ?> — please check the value(s) and try again.</div>
<?php endif; ?>

<p class="lp-field__hint">These options override the active theme's own defaults. A theme is always free to ignore any of them if it doesn't use the matching CSS variable.</p>

<?php foreach ($kernel->themeOptions->sections() as $section): ?>
    <?php $csrfAction = 'theme_options_' . $section->key; ?>
    <section class="lp-admin__panel">
        <h2><?= esc_html($section->label) ?></h2>
        <?php if ($section->description !== ''): ?>
            <p class="lp-field__hint"><?= esc_html($section->description) ?></p>
        <?php endif; ?>
        <form method="post" action="<?= esc_url(admin_url('appearance/theme-options')) ?>">
            <?= Csrf::field($csrfAction) ?>
            <input type="hidden" name="form" value="<?= esc_attr($csrfAction) ?>">

            <?php foreach ($kernel->themeOptions->fieldsForSection($section->key) as $field): ?>
                <?php
                /** @var ThemeOptionField $field */
                $fieldId = 'theme-option-' . str_replace('_', '-', $field->key);
                $currentValue = $kernel->themeOptions->value($field->key);
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
                    <?php else: ?>
                        <input type="text" id="<?= esc_attr($fieldId) ?>" name="opt_<?= esc_attr($field->key) ?>" value="<?= esc_attr($currentValue) ?>">
                    <?php endif; ?>
                    <?php if ($field->help !== '' && $field->type !== ThemeOptionType::Checkbox): ?>
                        <span class="lp-field__hint"><?= esc_html($field->help) ?></span>
                    <?php endif; ?>
                </p>
            <?php endforeach; ?>

            <button type="submit" name="submit_action" value="save" class="lp-button lp-button--primary">Save <?= esc_html($section->label) ?></button>
            <button type="submit" name="submit_action" value="reset" class="lp-button lp-button--secondary" data-lp-confirm="Reset every <?= esc_attr(strtolower($section->label)) ?> option back to its default?">Reset to Defaults</button>
        </form>
    </section>
<?php endforeach; ?>

<section class="lp-admin__panel">
    <h2>Reset Everything</h2>
    <p class="lp-field__hint">Resets every Theme Option above back to its default in one step.</p>
    <form method="post" action="<?= esc_url(admin_url('appearance/theme-options')) ?>" data-lp-confirm="Reset every Theme Option back to its default? This cannot be undone.">
        <?= Csrf::field('theme_options_reset_all') ?>
        <input type="hidden" name="form" value="theme_options_reset_all">
        <button type="submit" class="lp-button lp-button--secondary">Reset All Theme Options</button>
    </form>
</section>
