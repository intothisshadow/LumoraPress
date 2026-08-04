# Third-Party Assets

An inventory of every third-party asset Lumora Press bundles or references at
runtime. Keep this updated whenever a third-party asset is added, removed, or
its pinned version is changed as part of a release (LP-051).

Lumora Press deliberately vendors none of these locally. Each is loaded from
jsDelivr at a version pinned in the code (never a floating `@latest`), and the
Content-Security-Policy built in [`include/bootstrap.php`](../include/bootstrap.php)
only allow-lists `cdn.jsdelivr.net` for `script-src`, `style-src`, and
`font-src` — no other third-party origin is reachable from a page. This keeps
core's own PHP dependency-free while still avoiding an unreviewed floating
version.

## TinyMCE

- **Purpose:** WYSIWYG (visual HTML) editor for posts and pages (LP-016).
- **Current version:** 7 (major-version pin only; jsDelivr resolves the
  latest 7.x release — see Notes).
- **Date added:** 2026-07-25 (v0.3.0 "Admin")
- **Date last updated:** 2026-07-25 (v0.3.0 "Admin")
- **Loaded from:** CDN (jsDelivr)
- **Source URL:** https://github.com/tinymce/tinymce
- **License:** GPL v2+ (self-hosted/community edition; no cloud API key)
- **Local installation path:** N/A — not bundled
- **Homepage/Documentation URL:** https://www.tiny.cloud/docs/tinymce/latest/
- **Notes:** Loaded by
  [`admin/assets/js/content-editor.js`](../admin/assets/js/content-editor.js)
  with `license_key: 'gpl'` to force the open-source self-hosted mode (no
  cloud nag/telemetry). The version pin is major-only (`tinymce@7`, unlike
  EasyMDE/PhotoSwipe's exact pins) — deliberately not tightened further; if
  this drifts to an exact patch pin in a future release, update this entry.
  The editor's toolbar includes a custom `lumoraMedia` button wired to
  Lumora Press's own Media Manager picker (no plugin from TinyMCE's
  ecosystem).

## EasyMDE

- **Purpose:** Markdown editor for posts and pages (LP-015).
- **Current version:** 2.18.0
- **Date added:** 2026-07-25 (v0.3.0 "Admin")
- **Date last updated:** 2026-07-25 (v0.3.0 "Admin")
- **Loaded from:** CDN (jsDelivr)
- **Source URL:** https://github.com/Ionaru/easy-markdown-editor
- **License:** MIT
- **Local installation path:** N/A — not bundled
- **Homepage/Documentation URL:** https://github.com/Ionaru/easy-markdown-editor#readme
- **Notes:** Loaded by
  [`admin/assets/js/content-editor.js`](../admin/assets/js/content-editor.js).
  Bundles its own copy of CodeMirror internally — Lumora Press does not load
  or pin CodeMirror separately (see the CodeMirror entry below).
  `autoDownloadFontAwesome` is explicitly disabled and Font Awesome 4 (see
  entry below) is loaded manually instead, so its icon fetch stays on the
  already CSP-allowed `cdn.jsdelivr.net` origin. Autosave is enabled
  (`localStorage`, 15s interval, keyed per-textarea) as a client-side safety
  net independent of the LP-017 server-side Revisions feature.

## PhotoSwipe

- **Purpose:** Lightbox/gallery viewer for images (LP-031), used both in the
  admin Media Manager and in the default theme's public-facing image
  galleries.
- **Current version:** 5.4.4
- **Date added:** 2026-07-25 (v0.3.0 "Admin")
- **Date last updated:** 2026-07-25 (v0.3.0 "Admin")
- **Loaded from:** CDN (jsDelivr)
- **Source URL:** https://github.com/dimsemenov/photoswipe
- **License:** MIT
- **Local installation path:** N/A — not bundled
- **Homepage/Documentation URL:** https://photoswipe.com/
- **Notes:** Loaded independently in two places with the same pinned
  version and a shared `.lp-pswp-caption` UI extension:
  [`admin/assets/js/media-viewer.js`](../admin/assets/js/media-viewer.js)
  (admin Media Manager preview) and
  [`content/themes/default/assets/js/media-viewer.js`](../content/themes/default/assets/js/media-viewer.js)
  (public-facing theme galleries) — deliberately separate copies so the
  admin does not depend on whichever theme happens to be active. The
  stylesheet (`photoswipe.css`) is loaded via `<link>` in both
  `admin/views/layout-footer.php` and `content/themes/default/footer.php`.

## CodeMirror

- **Purpose:** Syntax-highlighting code editor for the Theme File Editor
  (LP-050) — line numbers, code folding, bracket matching/auto-closing,
  find & replace, go-to-line, per-language modes (PHP, CSS, JavaScript,
  HTML, XML/SVG, Markdown, JSON).
- **Current version:** 5.65.16 (CodeMirror 5, not 6)
- **Date added:** 2026-08-01
- **Date last updated:** 2026-08-01
- **Loaded from:** CDN (jsDelivr)
- **Source URL:** https://github.com/codemirror/codemirror5
- **License:** MIT
- **Local installation path:** N/A — not bundled
- **Homepage/Documentation URL:** https://codemirror.net/5/
- **Notes:** Loaded directly (not transitively through EasyMDE) by
  [`admin/assets/js/theme-file-editor.js`](../admin/assets/js/theme-file-editor.js),
  which also separately loads a handful of CM5 mode/addon scripts
  (`mode/php`, `mode/css`, `mode/javascript`, `mode/xml`, `mode/htmlmixed`,
  `mode/markdown`, plus the `fold`/`search`/`dialog`/`edit` addons) from the
  same pinned version, in dependency order. CodeMirror 5 (UMD/global build,
  plain `<script>`/`<link>` tags) was chosen over CodeMirror 6 specifically
  to match this project's existing no-bundler, no-ES-module CDN-loading
  convention — see this file's earlier note (now superseded) that CodeMirror
  was only ever present transitively inside EasyMDE's own bundle; this is
  the "future feature" that note anticipated. The two copies are
  independent: EasyMDE still bundles its own internal CodeMirror 5 copy for
  the Markdown editor, and upgrading one does not affect the other.

## Font Awesome (editor toolbar, v4)

- **Purpose:** Icon glyphs for EasyMDE's default Markdown editor toolbar
  (bold, italic, lists, link, image, preview, fullscreen, and the custom
  "Insert from Media Manager" button).
- **Current version:** 4.7.0
- **Date added:** 2026-07-25 (v0.3.0 "Admin")
- **Date last updated:** 2026-07-25 (v0.3.0 "Admin")
- **Loaded from:** CDN (jsDelivr)
- **Source URL:** https://github.com/FortAwesome/Font-Awesome
- **License:** Font (SIL OFL 1.1) + CSS/icons (MIT)
- **Local installation path:** N/A — not bundled
- **Homepage/Documentation URL:** https://fontawesome.com/v4/
- **Notes:** Loaded explicitly by
  [`admin/assets/js/content-editor.js`](../admin/assets/js/content-editor.js)
  instead of via EasyMDE's own `autoDownloadFontAwesome` option, so the font
  request stays on the CSP-allow-listed `cdn.jsdelivr.net` origin rather
  than an arbitrary one EasyMDE would otherwise pick. Version 4 specifically
  (not a later major) because that is the icon set EasyMDE's own CSS
  references by class name (e.g. `fa fa-bold`). Independent of the
  front-end plugin below — this load is scoped to the post/page editor
  screen only.

## Font Awesome (Font Awesome plugin, v6 — front-end, optional)

- **Purpose:** Icon glyphs for the front-end `[icon]` shortcode and
  `lp_icon()` theme/plugin helper, provided by the bundled first-party
  `content/plugins/font-awesome` plugin (LPP-002, see `TODO-PLUGINS.md`).
- **Current version:** 6.5.2 (default; an administrator can pin a
  different Font Awesome Free release number in Settings &rsaquo;
  Appearance &rsaquo; Font Awesome).
- **Date added:** 2026-08-04
- **Date last updated:** 2026-08-04
- **Loaded from:** CDN (jsDelivr) by default; an administrator can switch
  to a self-hosted URL of their own instead — see the plugin's own
  README.md. Either way, nothing loads unless the plugin is both active
  and enabled in its own settings (off by default).
- **Source URL:** https://github.com/FortAwesome/Font-Awesome
- **License:** Font (SIL OFL 1.1) + CSS/icons (MIT)
- **Local installation path:** N/A — not bundled (self-hosted mode still
  points at a URL the administrator controls; this project doesn't vendor
  the files itself)
- **Homepage/Documentation URL:** https://fontawesome.com/
- **Notes:** A self-hosted delivery URL's origin is added to the
  Content-Security-Policy's `style-src`/`font-src` directives at runtime
  (`FontAwesomeService::filterCsp()`) — CDN delivery needs no CSP change,
  since `cdn.jsdelivr.net` is already allow-listed for the reasons
  documented throughout this file.

## CSS frameworks

None. The admin UI and every bundled theme (`content/themes/*/style.css`)
are hand-written CSS with no third-party framework (Bootstrap, Tailwind,
etc.) at any layer, per the project's "lightweight, understandable" goal.

## Bundled PHP libraries

None. Lumora Press has no `composer.json`/`vendor/` runtime dependency
(`vendor/` exists only as an empty placeholder with a `.htaccess` deny rule)
— all application code is first-party. The `PHP Test Suite/` has its own,
separate `composer.json` for PHPUnit/PHPStan, but those are dev/test-only
tooling, not something the running application ships or depends on.

## Bundled icon libraries

None beyond Font Awesome above (editor-toolbar-only, CDN-loaded). No icon
font or SVG icon set is bundled locally or used in the public-facing themes
or the rest of the admin UI.

## Bundled fonts

None. Every theme and the admin UI rely on the visitor's/admin's system font
stack (`font-family` falls back through generic system fonts) rather than
bundling or CDN-loading a webfont.

## Any future JavaScript libraries

None currently planned beyond the four above. Add a new entry to this file
in the same release that introduces one, following the format used here.

A cropping library (e.g. Cropper.js) was deliberately **not** added for
LP-040's manual featured-image cropping — a single drag/move/resize
rectangle over one `<img>` is straightforward plain-DOM/mouse-event code
(`admin/assets/js/featured-image-crop.js`), so it didn't clear the bar for
a new CDN dependency the way EasyMDE/TinyMCE/CodeMirror's much larger
surface area did. Revisit this note if a future ticket needs real
multi-touch gestures, aspect-ratio locking, or rotation — genuine
justification for reaching for a library instead.
