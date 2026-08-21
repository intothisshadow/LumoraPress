# Font Awesome

First-class Font Awesome icon support for Lumora Press themes and plugins,
so no theme or plugin has to bundle its own copy.

## What this plugin does (first-pass scope)

- **Settings → Appearance → Font Awesome**: enable/disable, CDN vs.
  self-hosted delivery, version, and a compatibility mode that also loads
  Font Awesome's v4-shims stylesheet (for old `fa fa-camera`-style class
  names).
- An `[icon name="camera"]` shortcode, usable in any post/page body
  (Markdown, HTML, or Plain content — it's applied to the final rendered
  HTML). Supports `style`, `size`, `rotate`, `flip`, `animation`, `color`,
  `class`, and `label` attributes.
- A small developer API for themes and other plugins:
  - `lp_fontawesome_enabled()` — whether Font Awesome is currently enabled.
  - `lp_fontawesome_enqueue()` — signal that the current page uses icons
    outside the shortcode (e.g. hand-written `fa-*` classes in a theme).
  - `lp_icon(string $name, array $args = [])` — render one icon's `<i>`
    markup directly from PHP.
  - `lp_register_icon_pack(string $key, array $config)` — register an
    additional icon pack alongside Font Awesome's own.
- Disabled by default, so activating this plugin makes no external request
  on its own — nothing loads until you enable it in Settings.
- A **Diagnostics** section on the same Settings screen shows the active
  version, delivery method, and source, plus a heuristic check for another
  Font Awesome reference hardcoded into the active theme or another active
  plugin (a likely duplicate-loading conflict) — detection only; removing
  the conflicting reference is a manual step once flagged.

## Deferred (see TODO-PLUGINS.md's LPP-002 for the full checklist)

Font Awesome Pro / Kit support, SVG rendering mode, the icon picker
(TinyMCE toolbar button, EasyMDE button, menus/widgets/theme options
integration), automatically preventing a detected duplicate-loading
conflict, and icon metadata caching/search are not built yet.

## Notes

- Delivery is either the official jsDelivr CDN (version-pinned, matching
  every other third-party asset this project loads — see
  `docs/THIRD-PARTY.md`) or a self-hosted URL you provide; this plugin
  does not vendor Font Awesome's own files.
- The admin post/page editor's toolbar icons (EasyMDE) load their own
  pinned Font Awesome 4 copy independently of this plugin — see
  `admin/assets/js/content-editor.js` and `docs/THIRD-PARTY.md`'s "Font
  Awesome" entry. That load is scoped to the editor screen only; this
  plugin's front-end stylesheet load and that one don't currently overlap.
