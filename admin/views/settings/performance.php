<?php

declare(strict_types=1);

/**
 * The admin Settings > Performance screen: a Speed tab (scripts, fonts, proxy, CDN, checks) and a Cache tab.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.24.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

$tabs = ['speed' => 'Speed', 'cache' => 'Cache'];
$activeTab = in_array($_GET['tab'] ?? '', array_keys($tabs), true) ? $_GET['tab'] : 'speed';

// Each tab view runs in its own scope so their same-named locals
// ($form, $token, $driverOverride, ...) can never leak into one another,
// and both run on every request so either tab's POST handler is reachable.
$renderTab = static function (string $file) use ($kernel, $activeTab): void {
    require $file;
};
?>
<h1 class="lp-admin__title">Performance</h1>

<div class="lp-tabs">
    <div class="lp-tabs__list" role="tablist" aria-label="Performance section">
        <?php foreach ($tabs as $tabKey => $tabLabel): ?>
            <button type="button" class="lp-tabs__tab" id="lp-tab-<?= esc_attr($tabKey) ?>" role="tab" aria-selected="<?= $activeTab === $tabKey ? 'true' : 'false' ?>" aria-controls="lp-tabpanel-<?= esc_attr($tabKey) ?>" tabindex="<?= $activeTab === $tabKey ? '0' : '-1' ?>"><?= esc_html($tabLabel) ?></button>
        <?php endforeach; ?>
    </div>

    <?php foreach ($tabs as $tabKey => $tabLabel): ?>
        <div class="lp-tabs__panel" id="lp-tabpanel-<?= esc_attr($tabKey) ?>" role="tabpanel" aria-labelledby="lp-tab-<?= esc_attr($tabKey) ?>"<?= $activeTab === $tabKey ? '' : ' hidden' ?>>
            <?php $renderTab(__DIR__ . '/performance/' . $tabKey . '.php'); ?>
        </div>
    <?php endforeach; ?>
</div>
