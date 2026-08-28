<?php

/**
 * Shared SEO sidebar box body for the Post and Page editors (LP-083) —
 * included from posts/new.php and pages/new.php's own 'seo' sidebar
 * case, which byte-for-byte duplicated this markup before being
 * extracted here.
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
/** @var \LumoraPress\Models\Post|\LumoraPress\Models\Page $record The post/page being edited, set by the including view. */
/** @var string $idPrefix 'post' or 'page', matching this project's existing id="post-*"/id="page-*" field convention. */

if (!isset($kernel)) {
    http_response_code(403);
    exit('Direct access is not permitted.');
}
?>
<label for="<?= esc_attr($idPrefix) ?>-meta-title">SEO title</label>
<input type="text" id="<?= esc_attr($idPrefix) ?>-meta-title" name="meta_title" value="<?= esc_attr($record->metaTitle ?? '') ?>" placeholder="Defaults to the title above">
<span class="lp-field__hint">Overrides the browser tab title and search-result headline only — the title above is unchanged everywhere else on the site.</span>

<label for="<?= esc_attr($idPrefix) ?>-meta-description">Meta description</label>
<textarea id="<?= esc_attr($idPrefix) ?>-meta-description" name="meta_description" rows="2" placeholder="Defaults to the excerpt above"><?= esc_html($record->metaDescription ?? '') ?></textarea>
<span class="lp-field__hint">Shown in search results and social share previews. Leave blank to use the excerpt.</span>
