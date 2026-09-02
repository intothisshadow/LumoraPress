# Font Awesome

First-class Font Awesome icon support for Lumora Press themes and plugins, so no theme or plugin has to bundle its own copy.

## What this plugin does

- **Settings → Appearance → Font Awesome**: enable/disable, CDN vs. self-hosted delivery, version, and a compatibility mode that also loads Font Awesome's v4-shims stylesheet (for old `fa fa-camera`-style class names).
- An `[icon name="camera"]` shortcode, usable in any post/page body (Markdown, HTML, or Plain content). Supports `style`, `size`, `rotate`, `flip`, `animation`, `color`, `class`, and `label` attributes.
- Disabled by default, so activating this plugin makes no external request on its own — nothing loads until you enable it in Settings.
- A **Diagnostics** section on the same Settings screen shows the active version, delivery method, and source, plus a heuristic check for another Font Awesome reference hardcoded into the active theme or another active plugin (a likely duplicate-loading conflict) — detection only; removing the conflicting reference is a manual step once flagged.
- An icon picker in the Post/Page/Downloads editor toolbar (both the Visual/HTML editor and the Markdown editor), searching a curated, bundled set of common Font Awesome icon names by name/label/keyword and inserting the matching `[icon ...]` shortcode on selection. Only shown once the plugin is enabled.

## Notes

- Delivery is either the official jsDelivr CDN (version-pinned) or a self-hosted URL you provide; this plugin does not vendor Font Awesome's own files.
- The admin post/page editor's own toolbar icons load a separate, pinned Font Awesome copy independently of this plugin, scoped to the editor screen only — the two loads don't currently overlap.
- Building a theme or plugin that needs Font Awesome's developer API (rendering an icon from PHP, registering an additional icon pack)? See [`docs/DEVELOPER-APIS.md`](../../../docs/DEVELOPER-APIS.md).
