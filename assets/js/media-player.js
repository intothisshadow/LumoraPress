/**
 * Media Player (LP-150). Progressively enhances every `<audio>`/`<video>`
 * rendered by the `[lumora_audio]`/`[lumora_video]` shortcodes
 * (MediaPlayerShortcode, see include/bootstrap.php) with Plyr's
 * accessible, consistent-across-browsers skin. Without this script, the
 * native `<audio controls>`/`<video controls>` elements still play fine
 * — nothing is hidden or broken, just not re-skinned.
 *
 * Only ever loaded (see FooterAssets::render()) when
 * ScriptEmbeds::isUsed('plyr') was set — i.e. at least one player was
 * actually rendered — so it never runs on a page with nothing to
 * enhance. Plyr itself loads from jsDelivr at a pinned version, not
 * vendored locally, matching media-viewer.js's PhotoSwipe precedent.
 */
(function () {
    'use strict';

    if (typeof window.Plyr === 'undefined') {
        return;
    }

    var players = document.querySelectorAll('.lp-audio-player__audio, .lp-video-player__video');

    // Plyr's default iconUrl (cdn.plyr.io) isn't on this project's CSP
    // connect-src allowlist — jsdelivr already is (see bootstrap.php),
    // so point Plyr at the same package's own copy of its icon sprite
    // instead of widening the allowlist to a second CDN.
    var iconUrl = 'https://cdn.jsdelivr.net/npm/plyr@3.7.8/dist/plyr.svg';

    players.forEach(function (element) {
        // eslint-disable-next-line no-new
        new window.Plyr(element, { iconUrl: iconUrl });
    });
})();
