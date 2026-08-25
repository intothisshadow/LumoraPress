# Downloads

A dedicated admin screen to manage downloadable files and links — the
kind of "download manager" plugin classic WordPress sites commonly used
(Simple Download Monitor being one such example), rebuilt for Lumora
Press's own content model.

## What this plugin does

- **Downloads → All Downloads**: a real list table — ID, Title,
  Category, Type, Status, and Date columns — with a category filter,
  sortable columns, Live/Trash status tabs, bulk actions, and
  pagination, the same conventions the Posts/Pages list screens use.
- **Downloads → Add New**: add a new download — a title, description,
  and category, plus either an uploaded file or an external URL. The
  Description field uses the same shared WYSIWYG/Markdown/HTML editor
  Posts and Pages use, with the same format-switching UI. A category
  can also be created inline from the same screen. The same screen
  doubles as the editor for an existing download (via `?id=`), where a
  download shows a preview image — an image thumbnail, or a file-type
  badge for non-image files — visible immediately after a successful
  upload on Add too. A separate, explicitly-set thumbnail (currently
  only ever set by the WordPress Importer plugin, from a Simple
  Download Monitor download's own featured image) takes priority when
  present, since it's meaningful for either download type — a
  File-typed download's own file may not be an image at all, and a
  Url-typed download has no local file to preview from in the first
  place. Falls back to a File-typed download's own uploaded file
  otherwise. There's currently no way to set or change this thumbnail
  from this screen directly.
- **Downloads → Shortcodes**: reference docs for `[lumora_downloads]`,
  with real examples built from the site's own categories.
- **Trash**: moving a download to Trash hides it from the public
  `[lumora_downloads]` output and the admin list's default view, but
  leaves its underlying file/redirect untouched — restore it from the
  Trash tab, or permanently delete it from there once you're sure.
  Permanently deleting a download removes its underlying file or
  redirect too, unless a duplicate of it still exists (see Duplicate,
  below) — nothing orphaned is left behind once nothing references it.
- **Duplicate**: clones a download's title/description/category as a
  new entry, sharing the original's underlying file/redirect rather
  than copying it (cheap, and consistent with how duplicating a Post
  shares its featured image rather than copying that too).
- `[lumora_downloads category="Folder Name"]` (or
  `category_id="13"`, which wins if both are given) shows a category's
  downloads anywhere in post/page content — a title, a link, an
  optional file size (`show_size="1"`) and description. Trashed
  downloads never appear here.

## How it's built

A download doesn't reimplement file storage or URL redirection: a
file-type download's bytes are a normal Media Manager item, and a
url-type download's link is a normal Redirect (so it keeps real
hit-counting). The `downloads` table this plugin adds is a thin
identity/metadata layer on top of both — a title and description
regardless of type, and one place to list and edit everything grouped
by category, which neither Media Manager nor Settings &rsaquo;
Redirects had on their own.

If the WordPress Importer plugin is also active, a download added here
automatically appears in its `[sdm_show_dl_from_category]` shortcode
output too — the category a download is filed under (its Folder) is
passed straight through to the underlying Media/Redirect row, the same
way an imported download already worked. That direction needs no
wiring at all; the reverse direction does: when this plugin is active,
`WordPressImportService::importDownloads()` also attaches a real
`downloads` table row to each migrated download (via
`DownloadService::recordExisting()`, not `create()` — the Media item/
Redirect already exists by that point) so it shows up on this plugin's
own admin screen too, not just Media Manager/Settings &rsaquo;
Redirects.

`[lumora_downloads]` is a fresh shortcode owned by this plugin, distinct
from `[sdm_show_dl_from_category]` — it reads from the `downloads`
table directly rather than Media/Redirects by folder_id, so it only
ever shows downloads that have a real row here (added through this
plugin's own UI, or imported while this plugin was active).

A download's description is stored with its own format (a
`description_format` column, mirroring `posts`/`pages`' own
`content_format`), so it can be Plain, Markdown, or HTML — the
shortcode renders it accordingly rather than always as escaped plain
text.

## Deferred

- Changing the underlying *file* of a file-type download isn't
  supported (matching Media Manager's own edit panel, which doesn't
  support replacing a file either) — delete and re-add covers that
  case.
- No shortcode-insert picker in the post/page editor — none exists
  anywhere in Lumora Press today (Font Awesome's own `[icon]` shortcode
  has none either); `[lumora_downloads ...]` is typed by hand.
