# WordPress Importer

Migrates an existing WordPress site into Lumora Press: users, categories,
tags, media, pages, posts, comments, menus, and classic widgets — via a
direct database connection or a WordPress WXR (`.xml`) export file, plus
a local copy of the source site's `wp-content/uploads` folder.

## What this plugin does (first-pass scope)

- **Maintenance → Import**: pick a "Source type" — a direct database
  connection (enter the source database's host/port/database name/
  username/password/table prefix) or a WordPress WXR (`.xml`) export
  file (enter a local filesystem path to it) — plus either way, a local
  filesystem path to the source site's `wp-content/uploads` folder,
  Test Connection, then Import. For a database connection, the database
  can be the WordPress site's own live server (point the host field at
  it directly) or a locally restored backup; either way, and for a WXR
  file too, the uploads folder must always be readable on this server's
  local filesystem, since nothing here fetches files remotely — a WXR
  export's own `<wp:attachment_url>` only records where a file used to
  live, not the file itself.
- **WXR export limitations**: WordPress's WXR format is a *content*
  export — it structurally cannot carry site options, widget
  configuration, or a plugin's own custom database tables. From a
  WXR-sourced import: Site Settings and Widgets both have nothing to
  import (cleanly — the same "nothing found" outcome a widget-free or
  plugin-free real site already produces from a database connection,
  not an error); every imported user lands as Subscriber, since WXR
  carries no role data at all (reassign roles manually afterward);
  a Simple Download Monitor download's count is its `sdm_count_offset`
  postmeta alone, since the per-visit download log itself is never
  exported; and NextGEN Gallery galleries have nothing to import at all,
  since `ngg_gallery`/`ngg_pictures` are plugin-specific database tables
  a WXR export never carries. Users, categories/tags (including custom taxonomies like
  Simple Download Monitor's `sdm_categories` or the Media Library
  Folders plugin's `media_folder`), media, pages, posts, comments, and menus all import
  from a WXR file exactly as fully as from a database connection.
- **Auto-detect from wp-config.php**: only relevant for a database
  connection — point the "Path to wp-config.php"
  field at a locally readable copy of the source install's own
  `wp-config.php` and click Detect to pre-fill the database host/port/
  name/username/password/table prefix fields, plus the uploads folder
  path (resolved relative to `wp-config.php`'s own directory, honoring
  a customized `WP_CONTENT_DIR`/`UPLOADS` constant when present). Every
  pre-filled field stays fully editable — this is a shortcut, not a
  requirement, and every field can still be typed in by hand instead or
  afterward. `wp-config.php` is only ever pattern-matched as plain
  text, never executed, since it's untrusted input from an arbitrary
  external site's filesystem.
- Imports, each independently toggleable: users (WordPress role mapped
  to the closest Lumora Press role, reusing an existing account by
  username/email rather than duplicating it), categories (preserving
  parent/child hierarchy) and tags, media attachments (any type this
  app already allows — images, documents, audio, video — with alt
  text/caption/description and original upload date preserved), pages
  (preserving parent/child hierarchy and featured image), posts (with
  their categories/tags/featured image), and comments on posts (guest
  and registered authors, status, and threading preserved — not
  comments on pages, a current limitation of the shared import layer).
  Slugs and publish dates are preserved throughout.
- Imported attachments' own URLs are rewritten inside post/page content
  to point at the new local copy — a real HTML parse (`ContentImageRewriter`),
  not a plain string replace, so it resolves both an attachment's
  full-size path and any WordPress-generated derivative filename
  (`cover-300x200.jpg`) back to the same imported attachment, strips
  `srcset`/`sizes` and WordPress-only classes (`wp-image-123`,
  `size-large`) while keeping alignment classes as-is, leaves `alt`/
  `width`/`height`/`title` untouched, and correctly handles `<figure>`/
  `<figcaption>` wrappers and an image wrapped in `<a href="...">`. A
  WordPress `[gallery]` shortcode, gallery block, Jetpack Tiled Gallery
  block, or Jetpack `[slideshow]` shortcode has no single `<img src>`
  to resolve and is flagged in the warnings instead.
- Every WordPress menu (each `nav_menu` taxonomy term) becomes a named,
  reusable Lumora Press menu — custom links, and links to an already-
  imported page/post/category/tag, resolved to their real permalink and
  preserving parent/child nesting. A menu item pointing at content that
  wasn't imported (its own toggle was off, or it's genuinely missing) is
  skipped, listed in the warnings. **Menus are never auto-assigned to a
  theme location** — WordPress doesn't store a location's human-readable
  name in its database, only an opaque per-theme slug, so there's no
  reliable way to guess which Lumora Press location it meant. Assign
  each imported menu from Appearance › Menus › Manage Locations once the
  import finishes.
- Classic widgets import too, but only the types with a direct Lumora
  Press equivalent (Text, Custom HTML, Search, Pages, Categories, Recent
  Posts, Recent Comments, Archives, Tag Cloud, Meta) — every other
  widget type (WordPress's own Navigation Menu widget, and any
  plugin-provided widget type) is skipped, aggregated into one warning
  per type rather than one line per instance, since a real multi-plugin
  site can easily register 50+ distinct widget types with no Lumora
  Press equivalent at all. A widget's own sidebar is matched to a Lumora
  Press widget area by a small id-based heuristic (e.g. WordPress's
  `sidebar-1`/`footer-2` → this theme's `primary`/`footer`); anything
  that doesn't confidently match — including WordPress's own inactive-
  widgets bucket — lands in Lumora Press's existing Inactive Widgets
  list instead of being dropped, so nothing imported is ever silently
  lost.
- **NextGEN Gallery** galleries import too, if the source site ran that
  plugin: each gallery (`ngg_gallery`) becomes a Media Manager Folder
  named after the gallery, and its non-excluded pictures (`ngg_pictures`)
  become real Media items filed into that folder — needs a separate
  local filesystem copy of the source site's `wp-content/gallery` folder
  (a sibling of `wp-content/uploads`, entered on its own "Gallery folder
  path" field; left blank, this step is skipped entirely). A page/post
  still containing a `[ngg_...]` shortcode is flagged in the warnings
  rather than rendered — the gallery's images are still imported either
  way, just not the shortcode display itself. NextGEN's separate album
  grouping (a set of galleries) isn't imported.
- A Simple Download Monitor download's own featured image (set the same
  way a Post/Page's featured image is, via `_thumbnail_id`) is imported
  as that download's thumbnail — shown on the Downloads plugin's Add/
  Edit screen (when active) as a representative preview image, separate
  from the download's own file. Only resolved when the featured image's
  own attachment was itself imported (Media selected, and that
  particular attachment imported without error).
- **Site settings** (title, tagline, timezone, date/time format,
  permalink structure) can optionally be imported too — its own
  checkbox on the Start Import form, unchecked by default since it's
  the one step that overwrites this site's own existing settings
  rather than adding new content alongside it. Test Connection shows a
  read-only preview of the source's values first. A source permalink
  structure using a tag with no Lumora Press equivalent (e.g.
  WordPress's own `%post_id%`) is skipped and flagged in the warnings
  rather than applied. Homepage, Reading, Discussion, Media, and
  Privacy settings have no Lumora Press equivalent to write into yet
  and are never imported.
- Every imported record is tagged (via the shared
  `ContentImportRegistry`/`*Importer` layer this plugin shares with the
  Dummy Content plugin) so **Remove All Imported Content** deletes
  exactly what one import created and nothing else.
- Import refuses to start a *second* one while a previous import's
  content still exists — remove it first, or resume it (see below) if
  it's still incomplete — *unless* "When content already exists" below
  is set to Skip or Overwrite, in which case a new import is allowed to
  run right alongside the existing one.
- **Skip/Overwrite existing content**: re-importing from the same source
  after it was already imported once before no longer has to mean
  remove-and-start-over. A "When content already exists" dropdown on the
  Import form offers Skip (leave an already-imported Post, Page,
  Comment, or Media attachment completely unchanged, only import what's
  genuinely new since last time) or Overwrite (update each one in place
  with the source's current values — a full field update for Posts/
  Pages including replacing their categories/tags/custom fields, content
  and moderation status only for Comments, and alt text/caption/
  description/folder only for Media — Overwrite never replaces a Media
  item's underlying file). Every match is by the source's own id, not
  name/slug, via the same provenance tracking that backs "Remove All
  Imported Content" — a reused or updated row is never re-recorded under
  the new batch, so removing the new batch alone can never delete
  content the earlier one still owns. Users are unaffected: they already
  always reuse a matching existing account by username/email regardless
  of this setting. **Not yet covered by Skip/Overwrite**: Categories,
  Tags, Folders (Media Library Folders and Simple Download Monitor's own
  category tree), Downloads, and NextGEN Gallery images all still create
  or reapply fresh content on every import.
- **Dry run**: a single Import form handles both a preview and a real
  import — check "Dry run (preview only — makes no changes)" above the
  Import button to read the source database and report approximate
  counts per content type without importing anything ("approximate"
  because a missing uploads file, malformed row, or unsupported widget
  type is only ever caught during a real import, so the real count can
  land lower), or leave it unchecked to actually import.
- **Resumable, with a live progress bar**: a real import runs stage by
  stage (Site Settings, Users, Categories & Tags, Media, NextGEN Gallery,
  Downloads, Pages, Posts, Comments, Menus, Widgets), persisting its progress to
  the database after every single stage — not just at the end. If the
  process running it is ever interrupted (a host's execution time
  limit, a lost connection), revisiting Maintenance → Import offers to
  **Resume Import** from exactly where it stopped (re-enter the same
  connection details; the content-type selection from the original run
  is reused automatically and can't be changed mid-resume) or
  **Discard This Import** instead. A live progress checklist (reusing
  the same polling mechanism Maintenance → Updates already has) shows
  which stage is currently running while a Start/Resume Import request
  is in flight.
- **Delay between stages**: an optional `stage_delay_ms` field on the
  Start/Resume Import form — meant only for a live production source,
  never a local/staging copy, to avoid hammering a shared-hosting
  site's database and web server back-to-back for the whole import's
  duration.
- **Internal links between imported posts/pages** are also rewritten —
  a plain in-content `<a href="...">` carries no structured reference
  the way a menu item does, so this tries three independent,
  permalink-structure-agnostic ways to identify what it really pointed
  at: a `?p=123`/`?page_id=123` query parameter, an exact match against
  the post/page's own `guid`, or the link's last path segment matched
  against a slug — always scoped to the source site's own domain, so an
  unrelated external link is never touched. Category/tag/author archive
  links are a deliberate scope boundary and are left as-is.
- **`_wp_old_slug` redirects**: WordPress's own automatic record of every
  slug a published post/page ever had before its current one (it can
  carry more than one, if it was renamed more than once) each become a
  real redirect to the post/page's new URL, so an old bookmark or
  search-engine link doesn't just 404 after migration — runs in the same
  stage as the internal-link rewrite above. Skipped, with a warning,
  when the computed old-slug path already has a redirect, or matches the
  post/page's own current path.
- **Redirect mapping report**: every redirect an import created (an
  off-site Simple Download Monitor download, or a `_wp_old_slug` entry
  above) is shown as a table on Maintenance → Import once the import
  finishes, alongside a "Download as CSV" link.
- **Post-import finalization**: two more stages always run last,
  regardless of which content types were selected. Thumbnail size
  variants are regenerated for every imported image — the import path
  only ever inserts a media row's own metadata, unlike a normal admin
  upload, so nothing generates thumbnails for it otherwise. A
  verification pass then confirms every id this batch created still
  resolves to a real row, and that a post/page's own featured image
  still resolves to a real Media item; it also reports every active
  plugin or custom post type this import has no equivalent for at all
  (aggregated one warning per type/plugin, not one per row) — any
  problem found is added to the same warnings list every other stage's
  own problems already appear in.

## Deferred (see `TODO-PLUGINS.md`'s LPP-004 for the full checklist)

Of the WordPress site settings shown as a preview during Test
Connection, only title/tagline/timezone/date & time format/permalink
structure can be applied (opt-in, see above) — Homepage, Reading,
Discussion, Media, and Privacy settings have no Lumora Press config
key to write into yet. Skip/Overwrite existing content (see above)
doesn't yet cover Categories, Tags, Folders, Downloads, or NextGEN
Gallery images — all five still always create/reapply fresh content on
every import.

## Notes

- Runs as one long synchronous admin request per Start/Resume Import
  click rather than a background job — this codebase has no queue/cron/
  worker infrastructure yet. Don't navigate away while an import is in
  progress; if it's interrupted anyway, resuming picks up where it left
  off rather than starting over.
- Database credentials are never persisted anywhere — re-entered on
  every Test Connection / Preview / Start / Resume Import submission.
