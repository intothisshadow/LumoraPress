<?php

/**
 * The admin Plugins screen: activate, deactivate, install, and remove plugins.
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
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * LP-098: the Grid/List view-mode toggle persists via a fire-and-forget
 * JSON sub-action (the toggle itself switches instantly client-side —
 * see plugin-browser.js — this just remembers the choice for next time,
 * the same way admin/views/posts/new.php's editor_upload is a JSON
 * sub-action rather than its own admin page/route). Handled before the
 * CSRF-gated form dispatch below since it's not a real page submission.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['form'] ?? null) === 'set_list_view') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');

    $requestedMode = ($_POST['mode'] ?? '') === 'list' ? 'list' : 'grid';

    if (!Csrf::verify('set_list_view', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        http_response_code(403);
        echo json_encode(['error' => 'Your session expired. Reload the page and try again.']);
        exit;
    }

    $kernel->users->setListViewMode($currentUser->id, 'plugins', $requestedMode);
    echo json_encode(['csrfToken' => Csrf::token('set_list_view')]);
    exit;
}

/*
 * LP-045: Plugin Browser. Mirrors admin/views/appearance/themes.php's Theme
 * Management section — same "form" + CSRF-action-per-operation dispatch
 * pattern — extended with a two-step install flow (stage → confirm/
 * cancel) so an upload that collides with an already-installed plugin
 * can be reviewed and either replaced or cancelled, rather than either
 * silently overwriting or being rejected outright.
 */
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';
$error = null;
$pendingInstall = null;

/**
 * @return array<int, string>
 */
$readActivePlugins = static function () use ($kernel): array {
    $value = $kernel->config->option('active_plugins', '[]');
    $decoded = is_string($value) ? (json_decode($value, true) ?: []) : (array) $value;

    return array_values(array_map('strval', $decoded));
};

$writeActivePlugins = static function (array $slugs) use ($kernel): void {
    $kernel->config->setOption('active_plugins', json_encode(array_values($slugs)));
};

/*
 * Every plugin renders its Activate/Deactivate/Delete forms twice (once
 * on the card, once in the details panel), and Csrf::field() overwrites
 * the session token for a given action name on every call — so a bare
 * "activate_plugin" action name would leave the card's button silently
 * submitting an already-invalidated token once the details panel's
 * identical form renders after it. Scoping every action name by slug
 * AND by which form rendered it (via a hidden "origin" field) keeps
 * every rendered form's token distinct. See CommentService's identical
 * fix (admin/views/comments.php, action names like
 * "comment_moderate_{id}_{status}") and MEMORY.md for the original
 * incident this mirrors.
 */
$origin = is_string($_POST['origin'] ?? null) ? $_POST['origin'] : '';
$postedSlug = trim((string) ($_POST['slug'] ?? ''));

if ($form === 'activate_plugin' && Csrf::verify('activate_plugin_' . $origin . '_' . $postedSlug, is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $slug = $postedSlug;
    $target = $kernel->pluginRegistry->infoFor($slug);

    if ($target === null) {
        $error = 'That plugin could not be found.';
    } elseif ($target->isDisabled) {
        $error = 'This plugin cannot be activated: it requires a newer PHP version than this server has.';
    } else {
        $active = $readActivePlugins();

        if (!in_array($slug, $active, true)) {
            $active[] = $slug;
            $writeActivePlugins($active);
        }

        header('Location: ' . admin_url('plugins') . '?activated=1');
        exit;
    }
} elseif ($form === 'deactivate_plugin' && Csrf::verify('deactivate_plugin_' . $origin . '_' . $postedSlug, is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $slug = $postedSlug;
    $active = array_values(array_filter($readActivePlugins(), static fn (string $s): bool => $s !== $slug));
    $writeActivePlugins($active);

    header('Location: ' . admin_url('plugins') . '?deactivated=1');
    exit;
} elseif ($form === 'delete_plugin' && Csrf::verify('delete_plugin_' . $postedSlug, is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $slug = $postedSlug;
    $target = $kernel->pluginRegistry->infoFor($slug);

    if ($target === null) {
        $error = 'That plugin could not be found.';
    } elseif ($target->isActive) {
        $error = 'The plugin must be deactivated before it can be deleted.';
    } else {
        try {
            $kernel->pluginInstaller->delete($slug);

            header('Location: ' . admin_url('plugins') . '?deleted=1');
            exit;
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
} elseif ($form === 'install_plugin' && Csrf::verify('install_plugin', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    if (!isset($_FILES['plugin_zip']) || $_FILES['plugin_zip']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Please choose a ZIP file to upload.';
    } elseif ($_FILES['plugin_zip']['error'] !== UPLOAD_ERR_OK) {
        $error = 'The file upload failed. Please try again.';
    } else {
        try {
            $token = $kernel->pluginInstaller->stage($_FILES['plugin_zip']['tmp_name']);

            header('Location: ' . admin_url('plugins') . '?pending=' . urlencode($token));
            exit;
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
} elseif ($form === 'confirm_install_plugin' && Csrf::verify('confirm_install_plugin', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $token = (string) ($_POST['token'] ?? '');
    $replace = ($_POST['replace'] ?? '') === '1';

    try {
        $installed = $kernel->pluginInstaller->finalize($token, $replace);

        if (($_POST['activate_now'] ?? '') === '1' && !$installed->isDisabled) {
            $active = $readActivePlugins();

            if (!in_array($installed->slug, $active, true)) {
                $active[] = $installed->slug;
                $writeActivePlugins($active);
            }
        }

        header('Location: ' . admin_url('plugins') . '?installed=' . urlencode($installed->name));
        exit;
    } catch (\Throwable $exception) {
        $error = $exception->getMessage();
    }
} elseif ($form === 'cancel_install_plugin') {
    $token = (string) ($_POST['token'] ?? '');
    $kernel->pluginInstaller->discardStaged($token);

    header('Location: ' . admin_url('plugins'));
    exit;
}

$pendingToken = is_string($_GET['pending'] ?? null) ? $_GET['pending'] : null;

if ($pendingToken !== null) {
    try {
        $pendingInstall = $kernel->pluginInstaller->inspectStaged($pendingToken);
        $pendingInstall['token'] = $pendingToken;
    } catch (\Throwable $exception) {
        $error = $exception->getMessage();
        $pendingToken = null;
    }
}

$pluginList = $kernel->pluginRegistry->discover();
$listView = $kernel->users->getListViewMode($currentUser->id, 'plugins');
?>
<h1 class="lp-admin__title">Plugins</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['activated'])): ?>
    <div class="lp-alert lp-alert--success">Plugin activated.</div>
<?php endif; ?>

<?php if (isset($_GET['deactivated'])): ?>
    <div class="lp-alert lp-alert--success">Plugin deactivated.</div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="lp-alert lp-alert--success">Plugin deleted.</div>
<?php endif; ?>

<?php if (isset($_GET['installed'])): ?>
    <div class="lp-alert lp-alert--success">Installed &ldquo;<?= esc_html((string) $_GET['installed']) ?>&rdquo;.</div>
<?php endif; ?>

<?php if ($pendingInstall !== null): ?>
    <section class="lp-admin__panel lp-plugin-pending">
        <h2>Confirm Plugin Installation</h2>

        <?php if ($pendingInstall['existing'] !== null): ?>
            <p>
                A plugin named &ldquo;<?= esc_html($pendingInstall['existing']->name) ?>&rdquo;
                (version <?= esc_html($pendingInstall['existing']->version !== '' ? $pendingInstall['existing']->version : 'unknown') ?>)
                is already installed. This archive contains
                &ldquo;<?= esc_html($pendingInstall['name']) ?>&rdquo;
                (version <?= esc_html($pendingInstall['version'] !== '' ? $pendingInstall['version'] : 'unknown') ?>).
            </p>
        <?php else: ?>
            <p>
                Ready to install &ldquo;<?= esc_html($pendingInstall['name']) ?>&rdquo;
                <?php if ($pendingInstall['version'] !== ''): ?>(version <?= esc_html($pendingInstall['version']) ?>)<?php endif; ?>.
            </p>
        <?php endif; ?>

        <form method="post" action="<?= esc_url(admin_url('plugins')) ?>">
            <?= Csrf::field('confirm_install_plugin') ?>
            <input type="hidden" name="form" value="confirm_install_plugin">
            <input type="hidden" name="token" value="<?= esc_attr($pendingInstall['token']) ?>">
            <?php if ($pendingInstall['existing'] !== null): ?>
                <input type="hidden" name="replace" value="1">
            <?php endif; ?>

            <label class="lp-field--checkbox">
                <input type="checkbox" name="activate_now" value="1" checked>
                Activate this plugin now
            </label>

            <button type="submit" class="lp-button lp-button--primary">
                <?= $pendingInstall['existing'] !== null ? 'Replace Installed Plugin' : 'Install Plugin' ?>
            </button>
        </form>
        <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__inline-form">
            <input type="hidden" name="form" value="cancel_install_plugin">
            <input type="hidden" name="token" value="<?= esc_attr($pendingInstall['token']) ?>">
            <button type="submit" class="lp-button">Cancel</button>
        </form>
    </section>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Installed Plugins</h2>

    <?php if (count($pluginList) > 1): ?>
        <div class="lp-plugin-toolbar">
            <p class="lp-field lp-plugin-search">
                <label for="plugin-search">Search plugins</label>
                <input type="search" id="plugin-search" data-lp-plugin-search placeholder="Search by name, author, or tag&hellip;">
            </p>
            <p class="lp-field lp-plugin-filter">
                <label for="plugin-filter">Filter</label>
                <select id="plugin-filter" data-lp-plugin-filter>
                    <option value="all">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="update">Update Available</option>
                </select>
            </p>
        </div>

        <p class="lp-plugin-view-toggle" role="group" aria-label="View" data-lp-plugin-view-toggle data-csrf="<?= esc_attr(Csrf::token('set_list_view')) ?>">
            <button
                type="button"
                class="lp-button<?= $listView === 'grid' ? ' lp-button--primary' : ' lp-button--secondary' ?>"
                data-lp-plugin-view-button="grid"
                aria-pressed="<?= $listView === 'grid' ? 'true' : 'false' ?>"
            >Grid</button>
            <button
                type="button"
                class="lp-button<?= $listView === 'list' ? ' lp-button--primary' : ' lp-button--secondary' ?>"
                data-lp-plugin-view-button="list"
                aria-pressed="<?= $listView === 'list' ? 'true' : 'false' ?>"
            >List</button>
        </p>
    <?php endif; ?>

    <?php if ($pluginList === []): ?>
        <p class="lp-admin__widget-placeholder">No plugins are installed yet. Upload one below to get started.</p>
    <?php else: ?>
        <div class="lp-plugin-view-wrapper" data-lp-plugin-view-wrapper data-view="<?= esc_attr($listView) ?>">
        <table class="lp-table lp-plugin-table" data-lp-plugin-table>
            <thead>
                <tr>
                    <th scope="col">Plugin</th>
                    <th scope="col">Status</th>
                    <th scope="col">Version</th>
                    <th scope="col">Author</th>
                    <th scope="col">Description</th>
                    <th scope="col"><span class="lp-visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pluginList as $info): ?>
                    <?php
                    $rowSearchHaystack = strtolower($info->name . ' ' . $info->author . ' ' . implode(' ', $info->tags));
                    $rowStatusValue = $info->isActive ? 'active' : 'inactive';
                    $rowTemplateId = 'lp-plugin-details-' . $info->slug;
                    ?>
                    <tr
                        data-lp-plugin-row
                        data-plugin-search="<?= esc_attr($rowSearchHaystack) ?>"
                        data-plugin-status="<?= esc_attr($rowStatusValue) ?>"
                    >
                        <td><?= esc_html($info->name) ?></td>
                        <td>
                            <?php if ($info->isActive): ?>
                                <span class="lp-plugin-card__badge lp-plugin-card__badge--active">Active</span>
                            <?php elseif ($info->isDisabled): ?>
                                <span class="lp-plugin-card__badge lp-plugin-card__badge--disabled">Disabled</span>
                            <?php else: ?>
                                <span class="lp-plugin-card__badge lp-plugin-card__badge--inactive">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td><?= esc_html($info->version) ?></td>
                        <td><?= esc_html($info->author) ?></td>
                        <td><?= esc_html($info->description) ?></td>
                        <td class="lp-admin__row-actions">
                            <button type="button" class="lp-button--link" data-lp-plugin-details-trigger data-plugin-template="<?= esc_attr($rowTemplateId) ?>">Details</button>
                            <?php if ($info->isActive): ?>
                                <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__inline-form">
                                    <?= Csrf::field('deactivate_plugin_row_' . $info->slug) ?>
                                    <input type="hidden" name="form" value="deactivate_plugin">
                                    <input type="hidden" name="origin" value="row">
                                    <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                    <button type="submit" class="lp-button--link">Deactivate</button>
                                </form>
                            <?php elseif (!$info->isDisabled): ?>
                                <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__inline-form">
                                    <?= Csrf::field('activate_plugin_row_' . $info->slug) ?>
                                    <input type="hidden" name="form" value="activate_plugin">
                                    <input type="hidden" name="origin" value="row">
                                    <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                    <button type="submit" class="lp-button--link">Activate</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="lp-plugin-grid" data-lp-plugin-grid>
            <?php foreach ($pluginList as $info): ?>
                <?php
                $searchHaystack = strtolower($info->name . ' ' . $info->author . ' ' . implode(' ', $info->tags));
                $templateId = 'lp-plugin-details-' . $info->slug;
                $statusFilterValue = $info->isActive ? 'active' : 'inactive';
                ?>
                <div
                    class="lp-plugin-card<?= $info->isActive ? ' lp-plugin-card--active' : '' ?><?= $info->isDisabled ? ' lp-plugin-card--disabled' : '' ?>"
                    data-lp-plugin-card
                    data-plugin-search="<?= esc_attr($searchHaystack) ?>"
                    data-plugin-status="<?= esc_attr($statusFilterValue) ?>"
                >
                    <div class="lp-plugin-card__screenshot-wrap">
                        <?php if ($info->screenshotUrl !== null): ?>
                            <img class="lp-plugin-card__screenshot" src="<?= esc_url($info->screenshotUrl) ?>" alt="">
                        <?php else: ?>
                            <div class="lp-plugin-card__screenshot lp-plugin-card__screenshot--placeholder" aria-hidden="true"></div>
                        <?php endif; ?>
                    </div>
                    <div class="lp-plugin-card__body">
                        <h3 class="lp-plugin-card__name">
                            <?= esc_html($info->name) ?>
                            <?php if ($info->isActive): ?>
                                <span class="lp-plugin-card__badge lp-plugin-card__badge--active">Active</span>
                            <?php elseif ($info->isDisabled): ?>
                                <span class="lp-plugin-card__badge lp-plugin-card__badge--disabled">Disabled</span>
                            <?php else: ?>
                                <span class="lp-plugin-card__badge lp-plugin-card__badge--inactive">Inactive</span>
                            <?php endif; ?>
                        </h3>
                        <?php if ($info->description !== ''): ?>
                            <p class="lp-plugin-card__description"><?= esc_html($info->description) ?></p>
                        <?php endif; ?>
                        <p class="lp-plugin-card__meta">
                            <?php if ($info->version !== ''): ?><span>Version <?= esc_html($info->version) ?></span><?php endif; ?>
                            <?php if ($info->author !== ''): ?><span>By <?= esc_html($info->author) ?></span><?php endif; ?>
                        </p>
                        <div class="lp-plugin-card__actions">
                            <button type="button" class="lp-button lp-button--secondary" data-lp-plugin-details-trigger data-plugin-template="<?= esc_attr($templateId) ?>">Details</button>
                            <?php if ($info->isActive): ?>
                                <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__inline-form">
                                    <?= Csrf::field('deactivate_plugin_card_' . $info->slug) ?>
                                    <input type="hidden" name="form" value="deactivate_plugin">
                                    <input type="hidden" name="origin" value="card">
                                    <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                    <button type="submit" class="lp-button lp-button--secondary">Deactivate</button>
                                </form>
                            <?php elseif (!$info->isDisabled): ?>
                                <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__inline-form">
                                    <?= Csrf::field('activate_plugin_card_' . $info->slug) ?>
                                    <input type="hidden" name="form" value="activate_plugin">
                                    <input type="hidden" name="origin" value="card">
                                    <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                    <button type="submit" class="lp-button lp-button--primary">Activate</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <template id="<?= esc_attr($templateId) ?>">
                    <div class="lp-plugin-details">
                        <?php if ($info->screenshots !== []): ?>
                            <div class="lp-plugin-details__gallery">
                                <?php foreach ($info->screenshots as $screenshot): ?>
                                    <img src="<?= esc_url($screenshot) ?>" alt="<?= esc_attr($info->name) ?> screenshot">
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="lp-plugin-details__gallery lp-plugin-details__gallery--placeholder" aria-hidden="true"></div>
                        <?php endif; ?>

                        <h3 class="lp-plugin-details__name">
                            <?= esc_html($info->name) ?>
                            <?php if ($info->isActive): ?><span class="lp-plugin-card__badge lp-plugin-card__badge--active">Active</span><?php endif; ?>
                            <?php if ($info->isDisabled): ?><span class="lp-plugin-card__badge lp-plugin-card__badge--disabled">Disabled</span><?php endif; ?>
                        </h3>

                        <?php if ($info->isDisabled): ?>
                            <p class="lp-alert lp-alert--error">This plugin requires PHP <?= esc_html($info->requiresPhp) ?>+ and cannot be activated on this server.</p>
                        <?php endif; ?>

                        <?php if ($info->description !== ''): ?>
                            <p class="lp-plugin-details__description"><?= esc_html($info->description) ?></p>
                        <?php endif; ?>

                        <dl class="lp-plugin-details__facts">
                            <?php if ($info->version !== ''): ?><dt>Version</dt><dd><?= esc_html($info->version) ?></dd><?php endif; ?>
                            <?php if ($info->author !== ''): ?>
                                <dt>Author</dt>
                                <dd><?php if ($info->authorUri !== ''): ?><a href="<?= esc_url($info->authorUri) ?>" target="_blank" rel="noopener noreferrer"><?= esc_html($info->author) ?></a><?php else: ?><?= esc_html($info->author) ?><?php endif; ?></dd>
                            <?php endif; ?>
                            <?php if ($info->pluginUri !== ''): ?><dt>Homepage</dt><dd><a href="<?= esc_url($info->pluginUri) ?>" target="_blank" rel="noopener noreferrer"><?= esc_html($info->pluginUri) ?></a></dd><?php endif; ?>
                            <?php if ($info->license !== ''): ?>
                                <dt>License</dt>
                                <dd><?php if ($info->licenseUri !== ''): ?><a href="<?= esc_url($info->licenseUri) ?>" target="_blank" rel="noopener noreferrer"><?= esc_html($info->license) ?></a><?php else: ?><?= esc_html($info->license) ?><?php endif; ?></dd>
                            <?php endif; ?>
                            <?php if ($info->requiresAtLeast !== ''): ?><dt>Requires Lumora Press</dt><dd><?= esc_html($info->requiresAtLeast) ?>+</dd><?php endif; ?>
                            <?php if ($info->requiresPhp !== ''): ?><dt>Requires PHP</dt><dd><?= esc_html($info->requiresPhp) ?>+</dd><?php endif; ?>
                            <?php if ($info->requiresPlugins !== []): ?>
                                <dt>Dependencies</dt>
                                <dd><?= esc_html(implode(', ', $info->requiresPlugins)) ?></dd>
                            <?php endif; ?>
                            <dt>Folder</dt>
                            <dd><code><?= esc_html($info->relativePath) ?></code></dd>
                        </dl>

                        <?php if ($info->tags !== []): ?>
                            <ul class="lp-plugin-details__tags">
                                <?php foreach ($info->tags as $tag): ?>
                                    <li><?= esc_html($tag) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if ($info->readmeFile !== null): ?>
                            <details class="lp-plugin-details__document">
                                <summary>View README</summary>
                                <pre><?= esc_html((string) $kernel->pluginRegistry->documentContent($info->slug, $info->readmeFile)) ?></pre>
                            </details>
                        <?php endif; ?>

                        <?php if ($info->changelogFile !== null): ?>
                            <details class="lp-plugin-details__document">
                                <summary>View CHANGELOG</summary>
                                <pre><?= esc_html((string) $kernel->pluginRegistry->documentContent($info->slug, $info->changelogFile)) ?></pre>
                            </details>
                        <?php endif; ?>

                        <?php do_action('lp_plugin_details_panel', $info); ?>

                        <div class="lp-plugin-details__actions">
                            <?php if ($info->isActive): ?>
                                <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__inline-form">
                                    <?= Csrf::field('deactivate_plugin_details_' . $info->slug) ?>
                                    <input type="hidden" name="form" value="deactivate_plugin">
                                    <input type="hidden" name="origin" value="details">
                                    <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                    <button type="submit" class="lp-button lp-button--secondary">Deactivate</button>
                                </form>
                            <?php else: ?>
                                <?php if (!$info->isDisabled): ?>
                                    <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__inline-form">
                                        <?= Csrf::field('activate_plugin_details_' . $info->slug) ?>
                                        <input type="hidden" name="form" value="activate_plugin">
                                        <input type="hidden" name="origin" value="details">
                                        <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                        <button type="submit" class="lp-button lp-button--primary">Activate</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" class="lp-admin__inline-form" data-lp-confirm="Delete this plugin permanently? This cannot be undone.">
                                    <?= Csrf::field('delete_plugin_' . $info->slug) ?>
                                    <input type="hidden" name="form" value="delete_plugin">
                                    <input type="hidden" name="slug" value="<?= esc_attr($info->slug) ?>">
                                    <button type="submit" class="lp-button lp-button--danger">Delete</button>
                                </form>
                            <?php endif; ?>
                            <button type="button" class="lp-button" data-lp-plugin-dialog-close>Close</button>
                        </div>
                    </div>
                </template>
            <?php endforeach; ?>
        </div>
        </div>
        <p class="lp-plugin-grid__empty" data-lp-plugin-empty hidden>No plugins match your search.</p>

        <dialog class="lp-plugin-dialog" data-lp-plugin-dialog aria-label="Plugin details"></dialog>
    <?php endif; ?>

    <h3>Install a Plugin</h3>
    <form method="post" action="<?= esc_url(admin_url('plugins')) ?>" enctype="multipart/form-data">
        <?= Csrf::field('install_plugin') ?>
        <input type="hidden" name="form" value="install_plugin">

        <p class="lp-field">
            <label for="plugin-zip">Plugin ZIP file</label>
            <input type="file" id="plugin-zip" name="plugin_zip" accept=".zip" required>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Upload &amp; Review</button>
    </form>
</section>
