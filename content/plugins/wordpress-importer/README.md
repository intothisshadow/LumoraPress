# WordPress Importer

Migrates an existing WordPress site into Lumora Press: users, categories,
tags, media, pages, posts, and comments — via a direct database
connection plus a local copy of the source site's `wp-content/uploads`
folder.

## What this plugin does (first-pass scope)

- **Maintenance → Import**: enter the source database's host/port/
  database name/username/password/table prefix and a local filesystem
  path to its `wp-content/uploads` folder, Test Connection, then Start
  Import. The database can be the WordPress site's own live server
  (point the host field at it directly) or a locally restored backup —
  either way works the same; the uploads folder must always be readable
  on this server's local filesystem, since nothing here fetches files
  remotely.
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
  to point at the new local copy — full-size images only; a resized
  variant filename WordPress commonly embeds inline isn't generated or
  rewritten.
- Every imported record is tagged (via the shared
  `ContentImportRegistry`/`*Importer` layer this plugin shares with the
  Dummy Content plugin) so **Remove All Imported Content** deletes
  exactly what one import created and nothing else.
- Import refuses to run again while a previous import's content still
  exists — remove it first, then import again.

## Deferred (see `TODO-PLUGINS.md`'s LPP-004 for the full checklist)

The WXR `.xml` export upload path is a separate, not-yet-built import
source. Site settings (title, tagline, timezone, permalink structure,
etc.) are read and shown as a preview during Test Connection, never
written back automatically. Menus and classic widgets aren't migrated.
There's no dry-run preview, no resuming an interrupted import, and no
skip-vs-overwrite-existing-content choice — idempotency is a hard
"one import at a time" guard instead, matching Dummy Content. General
internal post-to-post link rewriting, a redirect-mapping report, and
regenerating thumbnail size variants aren't built either.

## Notes

- Runs as one long synchronous admin request rather than a background
  job — this codebase has no queue/cron/worker infrastructure yet.
  Don't navigate away while an import is in progress.
- Database credentials are never persisted anywhere — re-entered on
  every Test Connection / Start Import submission.
