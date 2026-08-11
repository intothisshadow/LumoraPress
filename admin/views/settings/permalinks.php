<?php

/**
 * The admin Settings > Permalinks screen: post URL structure and category/tag base prefixes.
 *
 * @package LumoraPress
 * @subpackage Admin
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */
/** @var \LumoraPress\Core\Kernel $kernel */
/** @var \LumoraPress\Models\User $currentUser */

use LumoraPress\Core\Security\Csrf;
use LumoraPress\Models\Post;
use LumoraPress\Models\PostStatus;

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}

/*
 * LP-078. Structure presets mirror classic WordPress's own Settings >
 * Permalinks page — "Post name" is this application's original, always
 * `/post/%postname%/` behavior and stays the default, so an unconfigured
 * site's URLs never change (see PermalinkService::postRoutePattern()'s
 * docblock). "Plain"/numeric permalinks and a Pages structure setting are
 * both deliberately out of scope — see TODO.md's LP-078 entry.
 */
$permalinkPresets = [
    'postname' => '/post/%postname%/',
    'day_name' => '/%year%/%monthnum%/%day%/%postname%/',
    'month_name' => '/%year%/%monthnum%/%postname%/',
];

/*
 * Reserved top-level path segments a category/tag base must not collide
 * with — every one of these is either a fixed front-end route
 * (bootstrap.php) or the admin/API mount point; a collision would make
 * that route (or every post, if it collided with the configured post
 * structure's own leading segment) permanently unreachable.
 */
$reservedBaseSegments = ['post', 'page', 'author', 'archive', 'search', 'feed', 'media', 'admin', 'api', 'preview'];

$error = null;
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

if ($form === 'structure_settings' && Csrf::verify('permalink_structure_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $preset = is_string($_POST['structure_preset'] ?? null) ? $_POST['structure_preset'] : 'postname';
    $customStructureInput = trim((string) ($_POST['custom_structure'] ?? ''));

    $structure = $preset === 'custom' ? $customStructureInput : ($permalinkPresets[$preset] ?? $permalinkPresets['postname']);

    if ($structure === '') {
        $error = 'Please enter a custom permalink structure.';
    } elseif (!str_contains($structure, '%postname%')) {
        $error = 'The permalink structure must include the %postname% tag.';
    } elseif (preg_match('/^[a-zA-Z0-9%_\-\/]+$/', $structure) !== 1) {
        $error = 'The permalink structure may only contain letters, numbers, hyphens, underscores, slashes, and %tag% placeholders.';
    } else {
        $kernel->config->setOption('permalink_structure', '/' . trim($structure, '/') . '/');

        header('Location: ' . admin_url('settings/permalinks') . '?saved=1');
        exit;
    }
} elseif ($form === 'base_settings' && Csrf::verify('permalink_base_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $categoryBaseInput = strtolower(trim((string) ($_POST['category_base'] ?? ''), " \t\n\r\0\x0B/"));
    $tagBaseInput = strtolower(trim((string) ($_POST['tag_base'] ?? ''), " \t\n\r\0\x0B/"));
    $categoryBaseInput = $categoryBaseInput !== '' ? $categoryBaseInput : 'category';
    $tagBaseInput = $tagBaseInput !== '' ? $tagBaseInput : 'tag';

    if (preg_match('/^[a-z0-9\-]+$/', $categoryBaseInput) !== 1 || preg_match('/^[a-z0-9\-]+$/', $tagBaseInput) !== 1) {
        $error = 'Category/Tag base may only contain lowercase letters, numbers, and hyphens.';
    } elseif (in_array($categoryBaseInput, $reservedBaseSegments, true) || in_array($tagBaseInput, $reservedBaseSegments, true)) {
        $error = 'That base collides with a reserved application path (' . implode(', ', $reservedBaseSegments) . ').';
    } elseif ($categoryBaseInput === $tagBaseInput) {
        $error = 'Category base and Tag base must be different from each other.';
    } else {
        $kernel->config->setOption('category_base', $categoryBaseInput);
        $kernel->config->setOption('tag_base', $tagBaseInput);

        header('Location: ' . admin_url('settings/permalinks') . '?saved=1');
        exit;
    }
}

$currentStructure = $kernel->permalinks->structure();
$currentPreset = array_search($currentStructure, $permalinkPresets, true);
$currentPreset = $currentPreset !== false ? $currentPreset : 'custom';

$hasExistingPosts = $kernel->posts->countByStatus(PostStatus::Published) > 0;

/*
 * A sample Post purely for the live preview below — never persisted, only
 * ever passed to PermalinkService::postUrl(). LP-008 author archive
 * slugs and category assignment don't apply to it, so postUrl() falls
 * back to the sample slug for those tokens (see PermalinkService::
 * buildPostUrl()'s docblock) — an accurate illustration either way, since
 * the same fallback would apply to any of the admin's own uncategorized
 * posts under a %category%-based structure.
 */
$samplePost = new Post(
    id: 0,
    title: 'Sample Post',
    slug: 'sample-post',
    content: '',
    excerpt: '',
    status: PostStatus::Published,
    authorId: 0,
    featuredImageId: null,
    publishedAt: new \DateTimeImmutable(),
    createdAt: new \DateTimeImmutable(),
    updatedAt: new \DateTimeImmutable(),
);
?>
<h1 class="lp-admin__title">Permalinks</h1>

<?php if ($error !== null): ?>
    <div class="lp-alert lp-alert--error"><?= esc_html($error) ?></div>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<?php if ($hasExistingPosts): ?>
    <div class="lp-alert lp-alert--warning">
        This site already has published posts. Changing the permalink structure below will change
        those posts' URLs going forward &mdash; any link, bookmark, or search-engine result pointing
        at the old URL will 404 once you save. Consider setting up redirects on the
        <a href="<?= esc_url(admin_url('settings/redirects')) ?>">Redirects</a> page for any URL you need to preserve.
    </div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Post URL Structure</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/permalinks')) ?>" data-lp-permalink-structure-form>
        <?= Csrf::field('permalink_structure_settings') ?>
        <input type="hidden" name="form" value="structure_settings">

        <fieldset class="lp-field">
            <legend>Common settings</legend>

            <?php foreach ($permalinkPresets as $presetKey => $presetStructure): ?>
                <label class="lp-field--radio">
                    <input type="radio" name="structure_preset" value="<?= esc_attr($presetKey) ?>" data-lp-permalink-preset-value="<?= esc_attr($presetStructure) ?>" <?= $currentPreset === $presetKey ? 'checked' : '' ?>>
                    <?= esc_html(match ($presetKey) {
                        'postname' => 'Post name',
                        'day_name' => 'Day and name',
                        'month_name' => 'Month and name',
                        default => $presetKey,
                    }) ?>
                    <code><?= esc_html($presetStructure) ?></code>
                </label>
            <?php endforeach; ?>

            <label class="lp-field--radio">
                <input type="radio" name="structure_preset" value="custom" <?= $currentPreset === 'custom' ? 'checked' : '' ?>>
                Custom Structure
            </label>
        </fieldset>

        <p class="lp-field">
            <label for="custom-structure">Custom Structure</label>
            <input type="text" id="custom-structure" name="custom_structure" value="<?= esc_attr($currentPreset === 'custom' ? $currentStructure : '') ?>" data-lp-permalink-custom-input>
            <span class="lp-field__hint">
                Available tags: <code>%postname%</code> <code>%year%</code> <code>%monthnum%</code> <code>%day%</code>
                <code>%category%</code> <code>%author%</code>. Must include <code>%postname%</code>.
            </span>
        </p>

        <p class="lp-field">
            <span class="lp-field__hint">Preview: <code data-lp-permalink-preview><?= esc_html(post_permalink($samplePost)) ?></code></span>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>

<section class="lp-admin__panel">
    <h2>Category &amp; Tag Base</h2>
    <form method="post" action="<?= esc_url(admin_url('settings/permalinks')) ?>">
        <?= Csrf::field('permalink_base_settings') ?>
        <input type="hidden" name="form" value="base_settings">

        <p class="lp-field">
            <label for="category-base">Category base</label>
            <input type="text" id="category-base" name="category_base" value="<?= esc_attr($kernel->permalinks->categoryBase()) ?>">
            <span class="lp-field__hint">Currently: <code><?= esc_html(home_url($kernel->permalinks->categoryBase() . '/sample-category')) ?></code></span>
        </p>

        <p class="lp-field">
            <label for="tag-base">Tag base</label>
            <input type="text" id="tag-base" name="tag_base" value="<?= esc_attr($kernel->permalinks->tagBase()) ?>">
            <span class="lp-field__hint">Currently: <code><?= esc_html(home_url($kernel->permalinks->tagBase() . '/sample-tag')) ?></code></span>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save</button>
    </form>
</section>
