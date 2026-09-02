# Emoji Picker

A fast, fully offline way to insert emoji into post/page content, without leaving the editor or relying on inconsistent OS-level emoji pickers.

## What this plugin does

- **Settings → Writing → Emoji Picker**: enable/disable, which editors show the picker button (Visual/HTML and/or Markdown), how many recently-used emoji to remember per user, and the category shown by default when the picker opens.
- A picker button in the Post/Page/Downloads editor toolbar (both the Visual/HTML editor and the Markdown editor), opening a dialog with search-as-you-type, browse-by-category, and a per-user "Recently used" category. Clicking or pressing Enter on an emoji inserts the raw Unicode character at the cursor and keeps the dialog open for repeated inserts — closes on Escape or the Cancel button, at which point focus returns to the editor.
- Inserts a plain Unicode character, not an image or shortcode — content stays portable and readable outside Lumora Press.
- 100% offline once the editor screen has loaded: the bundled dataset is embedded directly in the page, so search/browse never makes a network request. Persisting "recently used" across sessions is the one thing that does round-trip to this site's own server, never a third party.

## Data source

`data/emoji.php` is a curated subset of Unicode CLDR emoji annotations — not the full ~1,400+ entry CLDR set. Accurately hand-authoring every language's keyword annotations for that many characters was out of scope for this first pass; skin-tone/gender modifier variants and multi-person family/couple sequences are also omitted to keep the list a single flat set of unmodified base characters. See that file's own docblock for the exact process to refresh or extend it for a new Unicode revision. A site that needs the full set, other languages, or additional entries can extend or replace this dataset via the developer API instead of editing this file directly — see [`docs/DEVELOPER-APIS.md`](../../../docs/DEVELOPER-APIS.md).

## Notes

- No accounts, tracking, or remote lookups of any kind — everything ships with the plugin.
- Comparable in spirit to the WordPress classic editor's native "Insert Special Character" tool, but with search and categorization closer to how Emojipedia itself is browsed — this plugin never contacts Emojipedia or any other third party.
- Building a theme or plugin that needs the picker's developer API (a trigger button outside the default toolbars, extending the emoji dataset)? See [`docs/DEVELOPER-APIS.md`](../../../docs/DEVELOPER-APIS.md).
