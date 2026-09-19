# Link Directory

An admin-organized directory of links to other sites — the kind of "link directory"/webring listing page classic fan and hobby sites commonly ran, built as its own plugin.

## What this plugin does

- **Link Directory → All Links**: a real list table — ID, Title, URL, Category, Status, and Date columns — with a category filter, a title/URL search, sortable columns, Live/Trash status tabs, bulk actions, and pagination, the same conventions the Posts/Pages/Downloads list screens use.
- **Link Directory → Add New**: add a new entry — a title, external URL, category, optional thumbnail image, and description. The Description field uses the same Plain/Markdown/HTML format choice posts and pages use. A category (with an optional parent, for sub-categories) can also be created inline from the same screen. The same screen doubles as the editor for an existing entry.
- **Link Directory → Categories**: manage top-level categories and sub-categories — rename, re-parent, merge one category into another, and Trash/Restore/permanently delete, the same lifecycle Downloads' own Categories screen uses. Each row shows a ready-to-copy `[lumora_link_directory category_id="…"]` shortcode.
- **Link Directory → Shortcodes**: reference docs for `[lumora_link_directory]`, with real examples built from the site's own categories.
- **Link Directory → Export / Import**: download every category and link as one JSON file (a backup, or to copy the whole directory to a different Lumora Press install), or upload one to add its categories/links here. Importing never changes or removes anything already on the site — every category/link in the file is added as new, so importing the same file twice creates duplicates. Thumbnails aren't included in an export, since a thumbnail is a Media Library reference specific to the install it was uploaded on.
- **Trash**: moving an entry to Trash hides it from public shortcode output and the admin list's default view; restore it from the Trash tab, or permanently delete it from there once you're sure.
- **Duplicate**: clones an entry's title/URL/description/category/thumbnail as a new entry.
- `[lumora_link_directory]` with no attributes lists every category (and sub-category) with its own link count — click a category's name to browse into its own links (and any sub-categories) right there on the same page, with a link back to the full list; no separate page per category needed for this common case. `[lumora_link_directory category="Category Name"]` (or `category_id="13"`, which wins if both are given) pins the shortcode to one category's own links instead — title, thumbnail, description, and a clickable URL for each — the same way it always has, for a site that wants a category on its own dedicated page. `[lumora_link_directory link_id="7"]` shows a single entry by its exact ID. Trashed entries never appear in any of these.

## Notes

- A link's URL is a plain external address — unlike Downloads' URL-type entries, it doesn't route through the Redirects system, so there's no click-hit counting.
- A thumbnail is an ordinary Media Library image; removing it from an entry, or deleting the entry itself, never deletes the underlying Media item, the same convention Downloads' own thumbnail field uses.
- Every link — the title and the URL shown beneath it — opens in a new tab.
