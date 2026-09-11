# Lumora Gallery Shortcodes

Embed albums and images from a separately-installed [Lumora Gallery](https://coding.unloved-heart.net/scripts/lumoragallery) site into Lumora Press posts and pages.

This is an **optional integration plugin**. Lumora Press remains fully functional with it deactivated, and Lumora Gallery is never required or assumed installed. Lumora Gallery has no API of its own, so this plugin reads its database directly, read-only, via a separately-configured connection entered on its own Settings screen (Lumora Gallery &rsaquo; Settings). No changes to a Lumora Gallery installation are required for this half of the integration.

## Shortcodes

- `[lumora_gallery_album album_id="12"]` (or `folder="some-album"`) — every image in the given album.
- `[lumora_gallery_album album_id="12" count="5"]` — the newest 5 images in that album.
- `[lumora_gallery_album album_id="12,7,15"]` — every image across multiple albums combined (comma-separated ids). Adding `count` shows the newest N images across those albums instead. `folder` is ignored once more than one `album_id` is given.
- `[lumora_gallery_album image_id="4,9,12"]` — one or more specific images, by id (comma-separated). `album_id`/`folder` are optional here — an image id is already globally unique — and only affect which album the "View album" link points at.
- `[lumora_gallery_newest count="10"]` — the newest 10 images across the entire gallery (every public album).

Every thumbnail links to the real full-size image and opens it in Lumora Press's own existing PhotoSwipe lightbox — the same one every other gallery in Lumora Press uses, no extra JavaScript needed. Each rendered block also links back to the album below the images — or, for the multi-album and newest-across-the-gallery variants (no single album to link to), the Gallery site itself.

Only public albums and approved images are ever shown — this plugin has no concept of a Gallery user account or login, so it only ever reads what a logged-out Gallery visitor could see.

Available to whoever can already edit the post/page a shortcode is typed into — no separate role/capability gate, the same as every other shortcode in Lumora Press.

**Lumora Gallery &rsaquo; Shortcodes** in the admin has the full attribute reference and copy-paste examples for both shortcodes.

## Settings

Lumora Gallery &rsaquo; Settings needs:

- The Gallery database's host, port, name, username, password, and table prefix.
- The Gallery site's public base URL — used to build "View album" links and thumbnail/full-image URLs, since this plugin has no access to the Gallery site's own PHP URL-building code.

If the Gallery install's own `config.php` is readable on this server's local filesystem, "Auto-detect from config.php" pre-fills the database fields above straight from it (the same convenience the WordPress Importer plugin offers for a source site's `wp-config.php`) — and, once that connection actually works, the base URL too, read from the Gallery's own database. Every pre-filled field stays fully editable; this is a shortcut, not a requirement.

"Save &amp; Test Connection" confirms the credentials can open a connection and see the Gallery's own tables, without exposing raw connection errors on the settings screen.

Left unconfigured, both shortcodes simply render nothing — never a fatal error.
