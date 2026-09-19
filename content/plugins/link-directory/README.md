# Link Directory

An admin-organized directory of links to other sites — the kind of "link directory"/webring listing page classic fan and hobby sites commonly ran, built as its own plugin.

## What this plugin does

- **Link Directory → All Links**: a real list table — ID, Title, URL, Category, Status, and Date columns — with a category filter, a title/URL search, sortable columns, Live/Trash status tabs, bulk actions, and pagination, the same conventions the Posts/Pages/Downloads list screens use.
- **Link Directory → Add New**: add a new entry — a title, external URL, category, optional thumbnail image, and description. The Description field uses the same Plain/Markdown/HTML format choice posts and pages use. A category (with an optional parent, for sub-categories) can also be created inline from the same screen. The same screen doubles as the editor for an existing entry.
- **Link Directory → Categories**: manage top-level categories and sub-categories — rename, re-parent, merge one category into another, and Trash/Restore/permanently delete, the same lifecycle Downloads' own Categories screen uses. Each row shows a ready-to-copy `[lumora_link_directory category_id="…"]` shortcode.
- **Link Directory → Shortcodes**: reference docs for `[lumora_link_directory]`, with real examples built from the site's own categories.
- **Trash**: moving an entry to Trash hides it from public shortcode output and the admin list's default view; restore it from the Trash tab, or permanently delete it from there once you're sure.
- **Duplicate**: clones an entry's title/URL/description/category/thumbnail as a new entry.
- `[lumora_link_directory]` with no attributes lists every category (and sub-category) with its own link count — the directory's "browse by category" view. `[lumora_link_directory category="Category Name"]` (or `category_id="13"`, which wins if both are given) shows that category's own links — title, thumbnail, description, and URL for each. `[lumora_link_directory link_id="7"]` shows a single entry by its exact ID. Trashed entries never appear in any of these. Since Lumora Press has no per-category archive routing, place a category's shortcode on its own page (or a Custom HTML widget) and link to it from your site's navigation — the same manual, page-per-shortcode approach Downloads' own category shortcodes already use.

## Notes

- A link's URL is a plain external address — unlike Downloads' URL-type entries, it doesn't route through the Redirects system, so there's no click-hit counting.
- A thumbnail is an ordinary Media Library image; removing it from an entry, or deleting the entry itself, never deletes the underlying Media item, the same convention Downloads' own thumbnail field uses.
