# Downloads

A dedicated admin screen to manage downloadable files and links — the kind of "download manager" plugin classic WordPress sites commonly used, rebuilt for Lumora Press's own content model.

## What this plugin does

- **Downloads → All Downloads**: a real list table — ID, Title, Category, Type, Status, and Date columns — with a category filter, sortable columns, Live/Trash status tabs, bulk actions, and pagination, the same conventions the Posts/Pages list screens use.
- **Downloads → Add New**: add a new download — a title, description, and category, plus either an uploaded file or an external URL. The Description field uses the same shared WYSIWYG/Markdown/HTML editor Posts and Pages use, with the same format-switching UI. A category can also be created inline from the same screen. The same screen doubles as the editor for an existing download, where a download shows a preview image — an image thumbnail, or a file-type badge for non-image files — visible immediately after a successful upload on Add too.
- **Downloads → Shortcodes**: reference docs for `[lumora_downloads]`, with real examples built from the site's own categories.
- **Trash**: moving a download to Trash hides it from the public `[lumora_downloads]` output and the admin list's default view, but leaves its underlying file/redirect untouched — restore it from the Trash tab, or permanently delete it from there once you're sure. Permanently deleting a download removes its underlying file or redirect too, unless a duplicate of it still exists (see Duplicate, below) — nothing orphaned is left behind once nothing references it.
- **Duplicate**: clones a download's title/description/category as a new entry, sharing the original's underlying file/redirect rather than copying it.
- `[lumora_downloads category="Folder Name"]` (or `category_id="13"`, which wins if both are given) shows a category's downloads anywhere in post/page content — a title, a link, an optional file size (`show_size="1"`) and description. Trashed downloads never appear here.

## Notes

- A file-type download reuses the existing Media library, and a URL-type download reuses the existing Redirects system (so it keeps real hit-counting) — Downloads is a thin, unified admin layer over both, not a separate storage mechanism.
- If the WordPress Importer plugin is also active, a download added here automatically appears in that plugin's own imported-download shortcode output too, filed by the same category.
