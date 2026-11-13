<?php

declare(strict_types=1);

/**
 * The admin Posts > Shortcodes screen: one reference page for every shortcode, with a tab per source.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 1.0.2
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

// A plugin's tab only exists while that plugin is active, so its reference
// code never runs against classes that aren't loaded.
$tabs = ['media' => 'Media'];

if ($downloadsActive) {
    $tabs['downloads'] = 'Downloads';
}

if ($linkDirectoryActive) {
    $tabs['link-directory'] = 'Link Directory';
}

if ($galleryShortcodesActive) {
    $tabs['lumora-gallery'] = 'Lumora Gallery';
}

$activeTab = in_array($_GET['tab'] ?? '', array_keys($tabs), true) ? $_GET['tab'] : 'media';

// Each tab runs in its own scope so their same-named locals never collide.
$renderTab = static function (string $file) use ($kernel, $currentUser): void {
    require $file;
};
?>
<h1 class="lp-admin__title">Shortcodes</h1>

<div class="lp-tabs">
    <div class="lp-tabs__list" role="tablist" aria-label="Shortcode reference">
        <?php foreach ($tabs as $tabKey => $tabLabel): ?>
            <button type="button" class="lp-tabs__tab" id="lp-tab-<?= esc_attr($tabKey) ?>" role="tab" aria-selected="<?= $activeTab === $tabKey ? 'true' : 'false' ?>" aria-controls="lp-tabpanel-<?= esc_attr($tabKey) ?>" tabindex="<?= $activeTab === $tabKey ? '0' : '-1' ?>"><?= esc_html($tabLabel) ?></button>
        <?php endforeach; ?>
    </div>

    <?php foreach ($tabs as $tabKey => $tabLabel): ?>
        <div class="lp-tabs__panel" id="lp-tabpanel-<?= esc_attr($tabKey) ?>" role="tabpanel" aria-labelledby="lp-tab-<?= esc_attr($tabKey) ?>"<?= $activeTab === $tabKey ? '' : ' hidden' ?>>
            <?php $renderTab(__DIR__ . '/shortcodes/' . $tabKey . '.php'); ?>
        </div>
    <?php endforeach; ?>
</div>
