<?php

/**
 * The admin Settings > Embeds screen (LP-023/LP-070/LP-071): Auto-Embed provider toggles.
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

// A bare provider URL alone on its own line automatically expands into
// an embedded player when the matching toggle below is on. Never makes
// an outbound HTTP request, except Bluesky, which resolves each post
// URL once at save time.
$embeds = $kernel->embeds;
$errors = [];
$form = is_string($_POST['form'] ?? null) ? $_POST['form'] : '';

if ($form === 'embed_settings' && Csrf::verify('embed_settings', is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
    $maxWidth = trim((string) ($_POST['max_width'] ?? ''));

    $providers = [];

    foreach (['youtube', 'vimeo', 'soundcloud', 'spotify', 'codepen', 'twitter', 'bluesky'] as $providerKey) {
        $providers[$providerKey] = isset($_POST['provider_' . $providerKey]);
    }

    $embeds->saveSettings([
        'enabled' => isset($_POST['enabled']),
        'providers' => $providers,
        'max_width' => $maxWidth,
    ]);

    header('Location: ' . admin_url('settings/embeds') . '?saved=1');
    exit;
}

$settings = $embeds->settings();

$providerLabels = [
    'youtube' => 'YouTube',
    'vimeo' => 'Vimeo',
    'soundcloud' => 'SoundCloud',
    'spotify' => 'Spotify',
    'codepen' => 'CodePen',
    'twitter' => 'Twitter/X',
    'bluesky' => 'Bluesky',
];
?>
<h1 class="lp-admin__title">Embeds</h1>

<?php if (isset($_GET['saved']) && $errors === []): ?>
    <div class="lp-alert lp-alert--success">Saved.</div>
<?php endif; ?>

<section class="lp-admin__panel">
    <h2>Settings</h2>
    <p class="lp-field__hint">
        When on, pasting a supported link alone on its own line in a post or
        page automatically turns it into an embedded player &mdash; no HTML
        editing required. A link inline within a sentence is always left as
        a plain link. No provider is contacted over the network to build the
        embed, with one exception: Bluesky links are resolved once against
        Bluesky's own servers when a post or page containing one is saved,
        not on every page view &mdash; see the Bluesky note below.
    </p>

    <form method="post" action="<?= esc_url(admin_url('settings/embeds')) ?>">
        <?= Csrf::field('embed_settings') ?>
        <input type="hidden" name="form" value="embed_settings">

        <p class="lp-field">
            <label class="lp-field--checkbox">
                <input type="checkbox" name="enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?>>
                Enable auto-embed
            </label>
        </p>

        <fieldset class="lp-field">
            <legend>Providers</legend>
            <?php foreach ($providerLabels as $providerKey => $providerLabel): ?>
                <label class="lp-field--checkbox">
                    <input type="checkbox" name="provider_<?= esc_attr($providerKey) ?>" value="1" <?= ($settings['providers'][$providerKey] ?? false) ? 'checked' : '' ?>>
                    <?= esc_html($providerLabel) ?>
                </label>
            <?php endforeach; ?>
            <span class="lp-field__hint">Twitter/X and Bluesky work differently from the others &mdash; neither has a plain-iframe embed, so enabling either loads a small script (from <code>platform.twitter.com</code> or <code>embed.bsky.app</code> respectively) only on pages that actually contain a matching link. Bluesky links are also resolved once against Bluesky's own servers when the post/page is saved, so a rendered Bluesky embed may take an extra save-and-reload to appear the first time a link is pasted. Other providers can be added by a plugin via the <code>embed_providers</code> filter without touching core.</span>
        </fieldset>

        <p class="lp-field">
            <label for="embed-max-width">Maximum embed width</label>
            <input type="text" id="embed-max-width" name="max_width" value="<?= esc_attr($settings['max_width']) ?>" placeholder="640px">
            <span class="lp-field__hint">Any CSS width value (e.g. <code>640px</code> or <code>100%</code>). The embed itself always scales down to fit its container.</span>
        </p>

        <button type="submit" class="lp-button lp-button--primary">Save Settings</button>
    </form>
</section>
