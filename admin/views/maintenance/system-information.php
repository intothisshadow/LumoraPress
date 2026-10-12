<?php

/**
 * The admin Maintenance > System Information screen: PHP/server/application diagnostics.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

use LumoraPress\Core\Http\BasePath;
use LumoraPress\Core\Http\SiteUrl;
use LumoraPress\Services\SystemInformationService;

$version = require LUMORA_ROOT . '/version.php';

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$systemInformation = new SystemInformationService(
    server: $_SERVER,
    storedSiteUrl: (string) $kernel->config->option('site_url', ''),
    detectedSiteUrl: SiteUrl::detect($isHttps, (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'), BasePath::get()),
    applicationRoot: LUMORA_ROOT,
    directories: [
        'Uploads directory' => LUMORA_ROOT . '/content/uploads',
        'Cache directory' => LUMORA_ROOT . '/storage/cache',
        'Storage directory' => LUMORA_ROOT . '/storage',
    ],
    databaseHost: (string) $kernel->config->get('db_host', ''),
    databaseName: (string) $kernel->config->get('db_name', ''),
    tablePrefix: (string) $kernel->config->get('table_prefix', ''),
    databaseServerVersion: (string) $kernel->database->pdo()->getAttribute(\PDO::ATTR_SERVER_VERSION),
    phpVersion: PHP_VERSION,
    phpLimits: [
        'PHP memory limit' => (string) ini_get('memory_limit'),
        'Upload max file size' => (string) ini_get('upload_max_filesize'),
        'Post max size' => (string) ini_get('post_max_size'),
        'Max execution time' => (string) ini_get('max_execution_time'),
    ],
    cacheDriver: $kernel->cache->driverName(),
    cacheEnabled: $kernel->config->option('cache_enabled', '1') !== '0',
    brotliLoaded: extension_loaded('brotli'),
);

// A plain GET with no state change needs no CSRF check, but it does need
// to clear admin/index.php's output buffer before sending a raw file body.
if (($_GET['export_snapshot'] ?? '') === '1') {
    $snapshot = json_encode(
        [
            'generatedAt' => gmdate('c'),
            'lumoraPressVersion' => (string) $version['version'],
            'activeTheme' => $kernel->theme->activeTheme(),
            'details' => $systemInformation->snapshot(),
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="lumorapress-system-information-' . date('Y-m-d') . '.json"');
    header('Content-Length: ' . (string) strlen($snapshot));
    echo $snapshot;
    exit;
}
?>
<h1 class="lp-admin__title">System Information</h1>

<?php foreach ($systemInformation->warnings() as $warning): ?>
    <p class="lp-alert lp-alert--warning" role="alert"><?= esc_html($warning) ?></p>
<?php endforeach; ?>

<p>
    <a class="lp-button lp-button--secondary" href="<?= esc_url(admin_url('maintenance/system-information') . '?export_snapshot=1') ?>">Export Snapshot (JSON)</a>
</p>

<section class="lp-admin__panel">
    <h2>Environment</h2>
    <ul class="lp-admin__meta-list">
        <li><span>Lumora Press Version</span><span><?= esc_html((string) $version['version']) ?></span></li>
        <li><span>Active Theme</span><span><?= esc_html($kernel->theme->activeTheme() ?? '—') ?></span></li>
    </ul>
</section>

<?php foreach ($systemInformation->sections() as $section): ?>
    <section class="lp-admin__panel">
        <h2><?= esc_html($section['title']) ?></h2>
        <ul class="lp-admin__meta-list lp-admin__meta-list--details">
            <?php foreach ($section['rows'] as $row): ?>
                <li>
                    <span><?= esc_html($row['label']) ?></span>
                    <span>
                        <?php if ($row['value'] !== ''): ?>
                            <?php if ($row['code']): ?><code><?= esc_html($row['value']) ?></code><?php else: ?><?= esc_html($row['value']) ?><?php endif; ?>
                        <?php endif; ?>
                        <?php foreach ($row['badges'] as $badge): ?>
                            <span class="lp-status-badge<?= $badge['variant'] === 'neutral' ? '' : ' lp-status-badge--' . esc_attr($badge['variant']) ?>"><?= esc_html($badge['text']) ?></span>
                        <?php endforeach; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endforeach; ?>
