# Emoji Picker

A fast, fully offline way to insert emoji into post/page content, without
leaving the editor or relying on inconsistent OS-level emoji pickers.

## What this plugin does (first-pass scope)

- **Settings → Writing → Emoji Picker**: enable/disable, which editors show
  the picker button (Visual/HTML and/or Markdown), how many recently-used
  emoji to remember per user, and the category shown by default when the
  picker opens.
- A picker button in the Post/Page/Downloads editor toolbar (both the
  Visual/HTML editor and the Markdown editor — this codebase's "HTML
  editor mode" is the same TinyMCE instance as "Visual", there's no
  separate raw-source editor), opening a dialog with search-as-you-type,
  browse-by-category, and a per-user "Recently used" category. Clicking or
  pressing Enter on an emoji inserts the raw Unicode character at the
  cursor and keeps the dialog open for repeated inserts — closes on
  Escape or the Cancel button, at which point focus returns to the editor.
- Inserts a plain Unicode character, not an image or shortcode — content
  stays portable and readable outside Lumora Press, and needs no database
  schema changes of its own (emoji live in post content as ordinary UTF-8
  text; existing content sanitization/escaping already handles it
  correctly with no special-casing).
- 100% offline once the editor screen has loaded: the bundled dataset is
  embedded directly in the page (`data-emoji-dataset` on the editor
  container), so search/browse never makes a network request. Persisting
  "recently used" across sessions is the one thing that does round-trip
  to this site's own server (never a third party) — see
  `UserService::addRecentEmoji()`.
- A small developer API:
  - `lp_emoji_picker_enabled()` — whether the picker is currently enabled.
  - `lp_emoji_picker_editor_enabled(string $editor)` — whether it shows
    for a given editor ('wysiwyg' or 'markdown').
  - `lp_emoji_picker_button(array $args = [])` — render a self-contained
    trigger button usable outside the default editor toolbars (e.g. a
    theme's comment form); `$args['target']` is a CSS selector for the
    insertion target (default: the nearest `<textarea>` in the same
    `<form>`).
  - `lp_emoji_dataset` filter — extend or replace the bundled dataset
    (`array<int, array{emoji: string, name: string, category: string,
    keywords: array<int, string>}>`).
  - `lp_emoji_inserted` action — fired (with the emoji character and the
    inserting user's id) whenever a "recently used" entry is recorded.

## Data source

`data/emoji.php` is a curated subset of Unicode CLDR emoji annotations —
not the full ~1,400+ entry CLDR set. Accurately hand-authoring every
language's keyword annotations for that many characters was out of scope
for this first pass; skin-tone/gender modifier variants and multi-person
family/couple sequences are also omitted to keep the list a single flat
set of unmodified base characters. See that file's own docblock for the
exact process to refresh or extend it for a new Unicode revision — no
code changes are needed elsewhere, since the picker reads the whole file
at runtime. A site that needs the full set, other languages, or
additional entries can extend or replace this array entirely via the
`lp_emoji_dataset` filter instead of editing this file directly, so an
update never has to be reapplied by hand after a core upgrade.

## Deferred (see TODO-PLUGINS.md's LPP-006 for the full checklist)

Animated/custom emoji, emoji reactions on comments, and `:smile:`-style
autocomplete-while-typing expansion are explicitly out of scope for this
ticket — see its own Notes section.

## Notes

- No accounts, tracking, or remote lookups of any kind — everything ships
  with the plugin.
- Comparable in spirit to the WordPress classic editor's native "Insert
  Special Character" tool, but with search and categorization closer to
  how Emojipedia itself is browsed (this plugin never contacts Emojipedia
  or any other third party — see "Data source" above).
