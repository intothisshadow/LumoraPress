# Lumora Gallery Shortcodes

Embed albums and images from a separately-installed [Lumora
Gallery](https://coding.unloved-heart.net/scripts/lumoragallery) site
into Lumora Press posts and pages.

This is an **optional integration plugin**. Lumora Press remains fully
functional with it deactivated, and Lumora Gallery is never required or
assumed installed — see `CLAUDE.md`'s Independence rule. Lumora Gallery
has no API of its own, so this plugin reads its database directly,
read-only, via a separately-configured connection entered on its own
Settings screen (Lumora Gallery Shortcodes &rsaquo; Settings). No
changes to a Lumora Gallery installation are required for this half of
the integration.

## Shortcodes

- `[lumora_gallery_album album_id="12"]` (or `folder="some-album"`) —
  every image in the given album.
- `[lumora_gallery_album album_id="12" count="5"]` — the newest 5 images
  in that album.
- `[lumora_gallery_album image_id="4,9,12"]` — one or more specific
  images, by id (comma-separated). `album_id`/`folder` are optional
  here — an image id is already globally unique — and only affect which
  album the "View album" link points at.
- `[lumora_gallery_newest count="10"]` — the newest 10 images across the
  entire gallery (every public album).

Every thumbnail links to the real full-size image and opens it in
Lumora Press's own existing PhotoSwipe lightbox — the same one every
other gallery in Lumora Press uses, no extra JavaScript needed. Each
rendered block also links back to the album (or the Gallery site
itself, for the newest-across-the-gallery variant) below the images.

Only public albums (`visibility = 0`) and approved images
(`approved = 1`) are ever shown — this plugin has no concept of a
Gallery user account or login, so it only ever reads what a logged-out
Gallery visitor could see.

Available to whoever can already edit the post/page a shortcode is
typed into — no separate role/capability gate, the same as every other
shortcode in Lumora Press.

## Settings

Lumora Gallery Shortcodes &rsaquo; Settings needs:

- The Gallery database's host, port, name, username, password, and
  table prefix.
- The Gallery site's public base URL — used to build "View album" links
  and thumbnail/full-image URLs, since this plugin has no access to the
  Gallery site's own PHP URL-building code.

"Save &amp; Test Connection" confirms the credentials can open a
connection and see the `albums`/`images` tables, without exposing raw
connection errors on the settings screen.

Left unconfigured, both shortcodes simply render nothing — never a
fatal error.
