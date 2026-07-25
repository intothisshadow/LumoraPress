# Lumora Press

Lumora Press is a lightweight, self-hosted PHP blogging platform inspired by the elegance and simplicity of classic WordPress. It focuses on writing, publishing, and customization through traditional themes, widgets, and plugins—without block editors, page builders, AI features, or unnecessary complexity. Designed for personal websites, fansites, and hobbyists, Lumora Press aims to be fast, stable, familiar, and enjoyable to use on modern PHP while remaining friendly to shared hosting environments.

> Sit down. Write. Publish.

## Requirements

- PHP 8.2, 8.3, or 8.4
- MySQL 5.6.4+ or MariaDB 10.0.5+ (InnoDB `FULLTEXT` index support, used by search)
- The `pdo`, `pdo_mysql`, `session`, `json`, and `zip` PHP extensions (`zip`
  is required only for the manual core-update and theme-install features)
- Apache with `mod_rewrite` (the shipped `.htaccess` files assume Apache)
- `config/`, `storage/logs/`, `storage/sessions/`, `storage/cache/`, and
  `content/uploads/` writable by the web server user

The installer checks all of the above before doing anything else and shows
a clear, specific message if something is missing (`LumoraPress\Core\RequirementsCheck`).

## Installation

1. Point your web server's document root at this directory (`LumoraPress/`),
   or at a subdirectory of it if you're hosting Lumora Press alongside other
   sites (e.g. `https://example.com/blog/`) — both are supported, and the
   installer detects which one it's running under automatically.
2. Visit the site in a browser. If no configuration exists yet, you will be
   redirected to the installer automatically.
3. Follow the two-step installer: database connection details, then site
   name, timezone, language, and the administrator account.
4. The `install/` directory is removed automatically once installation
   succeeds (permissions allowing — if it can't be removed, the success
   page tells you to delete it by hand, and the admin Dashboard keeps
   showing a reminder until it's gone — including if a manual update ever
   restores it).
5. Log in at `/admin/` (or `/blog/admin/` etc. for a subdirectory install).

## Directory Structure

```
LumoraPress/
├── admin/          Admin area (dashboard, settings, etc.) — reached via /admin
├── app/
│   ├── Core/        Framework internals (database, hooks, security, theming, routing)
│   ├── Controllers/ Front-end request handlers
│   ├── Models/       Plain data/domain objects (User, UserRole, ...)
│   ├── Services/     Business logic (UserService, MediaService, ...)
│   └── Views/         Reserved for future server-rendered app views
├── config/          Generated config.php (never committed; contains credentials)
├── content/
│   ├── plugins/      Installed plugins
│   ├── themes/        Installed themes (default theme ships here)
│   └── uploads/       Media library uploads
├── docs/            Project documentation (this folder)
├── include/         Bootstrap and procedural helper APIs (hooks, theme, widgets, menus)
├── install/         The installer, reached via /install
├── storage/         Logs, cache, sessions, update backups/staging (never web-accessible)
├── vendor/          Reserved for future Composer dependencies
├── index.php        Front controller / application entry point
└── version.php      Current application version
```

## Architecture

Lumora Press follows a small service-oriented architecture rather than a full framework:

- **`LumoraPress\Core\Kernel`** — the composition root. One `Kernel` instance
  is built in `include/bootstrap.php` and passed explicitly to entry scripts
  instead of relying on global state.
- **`LumoraPress\Core\Database\Database`** — a thin PDO wrapper: prepared
  statements only, transactions, and helper methods (`fetchAll`, `fetchOne`,
  `execute`, `insertGetId`).
- **`LumoraPress\Core\Database\Migrator`** — applies versioned `.sql` files
  from a migrations directory, tracking what has run.
- **`LumoraPress\Core\PressConfig`** — configuration in two layers: file-based
  bootstrap config (`config/config.php`) for credentials/secrets, and a
  database-backed, cached "options" layer for editable site settings.
- **`LumoraPress\Core\Hooks\HookManager`** — the engine behind the classic
  `add_action()` / `do_action()` / `add_filter()` / `apply_filters()` plugin
  API, exposed procedurally via `include/hooks.php`.
- **`LumoraPress\Core\Theme\ThemeRenderer`** — locates and renders classic
  PHP theme templates (`header.php`, `single.php`, etc.), with `get_header()`
  / `get_footer()` / `get_sidebar()` helpers available inside templates.
- **`LumoraPress\Core\Widgets\WidgetManager`** and
  **`LumoraPress\Core\Menus\MenuManager`** — sidebar/widget and nav-menu
  registration and rendering, exposed procedurally for theme authors.
- **`LumoraPress\Core\Security\Auth`**, **`SessionManager`**, **`Csrf`**,
  **`LoginThrottle`**, **`RememberMeService`**, **`ContentSecurityPolicy`** —
  session-based authentication with session-fixation protection, secure
  cookie defaults, per-action CSRF tokens, database-backed login attempt
  throttling by IP address, an optional "Remember Me" persistent login via
  a rotating, single-use selector/validator cookie, and a strict,
  same-origin-only Content-Security-Policy header sent on every response.
- **`LumoraPress\Core\Http\Router`** — a small, dependency-free router with
  `{param}` placeholders; no third-party routing library.
- **`LumoraPress\Core\Http\BasePath`** — holds the install's base path
  (empty for a domain-root install, e.g. `/blog` for a subdirectory
  install), captured once by the installer and persisted to
  `config/config.php`. The `site_url()`/`admin_url()`/`admin_asset_url()`
  helpers read it so every internal link and static asset reference stays
  correct regardless of where the app is installed.
- **`LumoraPress\Core\Http\SiteUrl`** — holds the site's absolute URL
  (scheme + host + base path). The installer auto-detects it from the
  install request and stores it as an editable `site_url` option, falling
  back to live detection for installs made before this option existed.
  The `home_url()` helper reads it for contexts that need an absolute URL
  rather than `site_url()`'s root-relative one (RSS feeds, outbound
  emails, canonical tags — none of which exist yet, but the foundation is
  in place for when they do).
- **`LumoraPress\Core\InstallerCleanup`** — best-effort recursive directory
  removal, used by the installer to remove itself after a successful
  install.
- **`LumoraPress\Core\RequirementsCheck`** — checks the PHP version,
  required extensions, and writable directories before the installer
  attempts anything else.

The document root is the project root itself (`LumoraPress/`), matching
classic WordPress's flat layout: `admin/` and `install/` are real,
independently reachable directories, while `app/`, `include/`, `config/`,
and `vendor/` are denied direct web access via `.htaccess` and are only ever
loaded through PHP `require`.

## Updating

Administrators can update Lumora Press from the admin panel under
**Maintenance &rsaquo; Updates** (`/admin/maintenance/updates`), without FTP or SSH access:

1. Upload an official Lumora Press release ZIP. The package is validated
   (integrity, structure, version number) and checked for compatibility
   (PHP version, required extensions, disk space, writable directories)
   before anything is touched.
2. Review the summary screen — it shows the version change and any
   warnings — then confirm.
3. Lumora Press automatically backs up the core application files and the
   database to `storage/backups/` before applying the update, runs any new
   database migrations, and verifies the new version took effect. If
   anything goes wrong after the backup, it automatically restores the
   files and database from that backup.

Every attempt (success, failure, or rollback) is recorded in the
`{prefix}update_log` table and listed on the Updates page. Only `app/`,
`admin/`, `include/`, `install/`, `docs/`, the default theme, and the root
PHP files are ever replaced — `config/`, `content/uploads/`,
`content/plugins/`, any theme other than the default, and `storage/` are
never touched.
`install/` is deleted again automatically once the update succeeds (the
same best-effort cleanup the installer itself performs), so a package that
ships it doesn't leave it lying around on disk.

If the admin panel itself becomes unreachable after a failed update,
restore manually: unzip the most recent `storage/backups/files-*.zip` over
the installation directory, and re-import the most recent
`storage/backups/db-*.sql` into the database (both files are plain,
human-readable formats — `storage/` is never web-accessible, so retrieve
them via FTP/SFTP or your hosting file manager).

## Current Status

Phase 1 (Foundation) is implemented: project structure, installer, bootstrap,
routing, the database layer, configuration service, authentication and user
roles, the admin framework and dashboard, the classic theme system with a
default theme, the plugin hook system, and the widget and menu systems.

Phase 2 has begun with Posts: `LumoraPress\Services\PostService` provides
creation, editing, deletion, automatic slug generation (with duplicate
resolution), and draft/published/scheduled status handling — a scheduled
post becomes visible automatically once its publish date passes, with no
background job required. The admin Posts screen (`admin/views/posts.php`)
supports the full CRUD flow, respecting each role's capabilities (a
Contributor can only save drafts of their own posts; an Author can publish
their own; an Editor/Administrator can edit and publish anyone's). The
homepage and `/archive` route list published posts with pagination, and
`/post/{slug}` renders the full post.

Pages are implemented as a first pass: `LumoraPress\Services\PageService`
mirrors `PostService` (creation, editing, deletion, slug generation,
draft/published/scheduled status) and adds a basic, flat "Parent Page"
selector for simple one-level parent/child relationships — no page tree
view, drag-and-drop ordering, or hierarchical URLs yet. The admin Pages
screen (`admin/views/pages.php`) reuses the Posts capabilities, and
`/page/{slug}` renders the full page.

The Markdown Editor (LP-015) and WYSIWYG Editor (LP-016) replace the
original plain `<textarea>` content field with a real authoring
experience. Each post/page has a `content_format` column — `markdown`
(the default for new content), `html`, or `plain` (the default for rows
that predate this column, matching their original nl2br(escaped-text)
rendering exactly) — chosen from an "Editor" dropdown on the post/page
screen. Markdown format loads
[EasyMDE](https://github.com/Ionaru/easy-markdown-editor); HTML/Visual
format loads [TinyMCE](https://www.tiny.cloud/) (self-hosted, GPL,
no cloud account needed); both load from jsDelivr at a pinned version
rather than being bundled, the same approach already used for the
PhotoSwipe lightbox. Switching formats asks for confirmation, then
round-trips the current content through a best-effort converter rather
than silently rewriting it.

Behind both editors is a single, dependency-free rendering pipeline —
Lumora Press avoids Composer/npm (see below), so this is plain PHP with
no vendored Markdown library:

- `LumoraPress\Core\Content\MarkdownParser` — headings, bold/italic/
  strikethrough, inline code, fenced code blocks (tagged
  `language-xxx` for a future syntax highlighter to consume), GFM
  tables, blockquotes, horizontal rules, ordered/unordered/task lists,
  links, images, footnotes, and a `[[toc]]` table-of-contents marker.
  Deliberately does not pass raw HTML typed in Markdown source through
  to the output (it's escaped as literal text instead) — a narrower
  behaviour than CommonMark, chosen so the parser's own output can
  never itself be a script-injection vector.
- `LumoraPress\Core\Content\HtmlSanitizer` — a DOMDocument-based
  allowlist sanitizer (tags, attributes, and URL schemes), the single
  XSS boundary every format's output passes through: Markdown's
  generated HTML, hand-typed HTML-mode content, and TinyMCE's
  submitted HTML are all equally untrusted by the time they reach it.
- `LumoraPress\Core\Content\HtmlToMarkdownConverter` — the reverse
  direction, used only when an author switches a post from Visual/HTML
  to Markdown; round-trips exactly the tag set `HtmlSanitizer` allows,
  since that's the only HTML this application ever produces.
- `LumoraPress\Services\ContentRenderer` (a Kernel service, plus a
  `render_content()`/`content_plain_text()` theme-template bridge
  mirroring `SiteBranding`/`ThemePreview`) — the one place a format +
  raw content becomes final, safe HTML. Every place content is shown
  (post/page templates, RSS/Atom feeds, search excerpts, the REST
  API's `content_html` field) goes through this, not the raw column.

Both editors share one JS orchestrator, `admin/assets/js/
content-editor.js`: it keeps the real `<textarea>` in sync (content
still submits even if a CDN library fails to load), and a lightweight
media picker/upload button, built from the same data already fetched
for the post/page's Featured Image field, backs "insert image" in both.
Word count, character count, and an estimated reading time are shown
live under the editor. Loading EasyMDE/TinyMCE from jsDelivr — and
EasyMDE's Font Awesome 4 toolbar icons, loaded the same way rather than
via EasyMDE's own less predictable auto-download — required one addition
to the site's Content-Security-Policy: `include/bootstrap.php` registers
a `csp_directives` filter allowing `cdn.jsdelivr.net` for `script-src`/
`style-src`/`font-src` — the class's own same-origin-by-default policy
explicitly documents this filter as the correct way to loosen it, rather
than editing the policy class itself.

Deferred from both tickets: inserting galleries or non-image files from
the Media Manager, real syntax-highlighted code block *preview* (the
parser tags the language but nothing renders it yet outside TinyMCE's
own `codesample` plugin), a filterable/admin-configurable toolbar,
Lumora "shortcodes" (no shortcode engine exists in the app yet), and
server-side draft autosave (both editors' built-in client-side
autosave already covers browser-crash recovery). See `TODO.md`'s
LP-015/LP-016 sections for the complete, itemized list.

Categories are implemented as a first pass:
`LumoraPress\Services\CategoryService` provides a hierarchical taxonomy
for Posts with a real many-to-many relationship (a post can have any
number of categories, assigned via a checklist in the Post editor), the
same basic flat parent selector as Pages, and orphan-on-delete for child
categories rather than cascading deletes. The admin Categories screen
(`admin/views/categories.php`) shows each category's post count, and
`/category/{slug}` renders a real archive of posts directly assigned to
that category (not its children) with the category's description shown
above the list — no category tree view, merge, bulk actions, images, or
RSS integration yet.

Tags are implemented as a first pass: `LumoraPress\Services\TagService`
provides a flat (non-hierarchical) taxonomy for Posts. Unlike Categories,
tags are typed by name in a comma-separated field rather than picked from
a checklist — `findOrCreateByName()` resolves each one case-insensitively
and creates it automatically if it's new. That field is enhanced by the
project's first admin JavaScript file (`admin/assets/js/tag-input.js`) into
a chip + live-suggestions widget with full keyboard support, but degrades
to a plain text input if JavaScript is disabled. The admin Tags screen
(`admin/views/tags.php`) mirrors Categories minus the parent selector, and
`/tag/{slug}` renders a real archive with the tag's description shown
above the list.

Comments are implemented as a first pass, scoped to Posts (Pages, media,
and albums are out of scope — media/albums belong to Lumora Gallery, a
separate application). `LumoraPress\Services\CommentService` provides
threaded, unlimited-depth replies via a self-referencing `parent_id`.
Guests can comment with a name/email/optional website; any signed-in
dashboard user can comment as themselves without retyping their details.
New comments default to Pending unless the commenter is trusted (holds
`moderate_comments`, or a guest email has a prior Approved comment).
Basic spam protection: a honeypot field, a 30-second minimum between
comments from the same IP, and CSRF-protected forms. Each post has an
"Allow Comments" toggle, and there is a site-wide "Allow comments
site-wide" switch on the admin Comments screen
(`admin/views/comments.php`), where moderators can approve/unapprove/
mark spam/trash/permanently delete and edit comment text, filtered by
status. URLs in comment content are auto-linked. Reactions,
notifications, mentions, avatars, third-party spam services (Akismet,
Turnstile, hCaptcha, reCAPTCHA), and GDPR export/deletion tooling are
not yet implemented.

RSS & Atom feeds are implemented as a first pass, scoped to a single
site-wide feed of published posts (category/tag/author/comment feeds are
not yet implemented). `LumoraPress\Services\FeedService` builds channel
metadata and item data, filterable by plugins via `apply_filters
('feed_channel', ...)` and `apply_filters('feed_item', ...)`; `/feed` and
`/feed/rss` serve RSS 2.0, `/feed/atom` serves Atom 1.0. Feed items respect
the same published/scheduled-and-due visibility rule as the homepage and
archives. An admin Settings &rsaquo; General screen (`admin/views/settings/general.php`)
controls whether feeds are enabled, full content vs. excerpts, item limit, cache
lifetime, and a feed description. Responses include `ETag`/`Last-Modified`/
`Cache-Control` headers with conditional GET (304) support. The default
theme auto-discovers both feeds via `<link rel="alternate">` in `<head>`
and links to the RSS feed from the site footer.

Search is implemented as a first pass, scoped to Posts and Pages
(Categories/Tags/Authors/Comments/Media are not yet searchable).
`LumoraPress\Services\SearchService` ranks results by relevance using real
MySQL/MariaDB FULLTEXT indexes and `MATCH(...) AGAINST(...)` — a title
match is weighted higher than a body-only match — rather than a custom
indexing pipeline; the index is maintained automatically by MySQL on every
post/page save, so there is no rebuild step. Results from Posts and Pages
are merged into one relevance-ordered, paginated list. Matching terms are
highlighted in results (`highlight_terms()`); the Settings &rsaquo; General screen
controls the minimum query length and the maximum combined result
count. The default theme's header includes a real search form (plain GET,
no JavaScript). Exact-phrase, prefix, partial-substring, and fuzzy
matching are not supported — MySQL's natural-language full-text mode
matches whole words only.

User Management is implemented as a first pass: the admin Users screen
(`admin/views/users.php`) lets Administrators create, edit, and delete
user accounts and assign a role (Administrator/Editor/Author/
Contributor/Subscriber). Three guards protect against locking yourself
out or orphaning content: you cannot delete your own account, the last
remaining Administrator cannot be deleted or demoted, and a user who has
authored posts or pages cannot be deleted until that content is
reassigned or removed (there is no admin reassignment UI yet). A
password can be changed from the edit screen without re-entering it on
every save. Bulk actions, avatars, and a self-service "My Profile"
screen are not yet implemented.

Manual updates via ZIP upload are implemented (see "Updating" above);
GitHub-based update checking/downloading is not yet built.

Maintenance Mode is implemented as a first pass:
`LumoraPress\Core\Http\MaintenanceGate` gates every public front-end
request (checked in `index.php`, before routing) — `/admin/*` is always
exempt so an Administrator can log in and turn it off. It can be switched
on manually (Settings &rsaquo; Maintenance Mode, or a one-click dashboard button)
or scheduled (optional start/end times, checked per request — no cron
job, the same "no background process" approach already used for
scheduled posts/pages). Administrators always bypass; an "Allow Editors
to bypass" option can extend that via the existing `moderate_comments`
capability. Blocked requests get a real `503 Service Unavailable`,
`X-Robots-Tag: noindex`, and (when a schedule or a configured seconds
value applies) a `Retry-After` header, plus a theme-rendered maintenance
page (title, message, and — when a scheduled end time is set — a static
estimated-return line; no JS countdown). Plugins can register their own
bypass rules via the `maintenance_mode_bypass` filter and react to state
changes via the `maintenance_mode_toggled` action. IP whitelisting, a
secret bypass URL/cookie, and email notifications are not yet
implemented.

Appearance is implemented as a first pass, with a modern Theme Browser
(LP-044) on top. Theme Management: `LumoraPress\Core\Theme\ThemeRegistry`
discovers every theme under `content/themes/`, parsing each one's
`style.css` comment header — the classic `Theme Name:`/`Description:`/
`Version:`/`Author:`/`Theme URI:`/`Author URI:`/`License:`/`License URI:`/
`Requires at least:`/`Requires PHP:`/`Tags:` convention — plus every
`preview.*`/`thumbnail.*`/`screenshot.*` image present (JPG, PNG, WebP, or
AVIF; numbered variants like `screenshot-2.png` build a multi-screenshot
gallery) and a `README`/`CHANGELOG` file if included. The admin Appearance
screen (`admin/views/appearance.php`) lists every discovered theme as a
card — with a live search box, the active theme highlighted, a
placeholder shown when no preview image exists, and preview thumbnails
capped at `max-width: 250px` with `height: auto` (no `object-fit`) so an
image is scaled down proportionally, never cropped or stretched — and a
"Details" button opens a details panel (a native `<dialog>`,
progressively enhanced by `admin/assets/js/theme-browser.js`) showing the
full parsed metadata, the screenshot gallery, README/CHANGELOG contents,
and Activate/Delete actions; a `lp_theme_details_panel` action hook lets
plugins extend that panel. A "Preview" link (card and details panel, on
any inactive theme) opens the real front end with
`?lp_preview_theme={slug}`: `index.php` checks the requester is logged in
with `manage_themes` before honouring it, then swaps `ThemeRenderer`'s
active theme for that request only — the site-wide `active_theme` option
is never touched, so no other visitor is affected — while
`LumoraPress\Core\Theme\ThemePreview` (a static bridge, mirroring
`SiteBranding`/`FeaturedImages`) marks the request so a "Previewing
theme… Exit Preview" bar gets injected right after the rendered page's
`<body>` tag, styled by its own stylesheet
(`admin/assets/css/theme-preview-bar.css`) so it works unmodified with
every theme, including custom ones with no knowledge of preview mode at
all. New themes install from a ZIP upload
(`LumoraPress\Services\ThemeInstaller` — reuses the path-traversal/size-cap
safety checks LP-026's update-package validator established, but is
otherwise much simpler: a theme install only ever adds one new,
independent directory, never overlays live core files, so there's no
staging/backup/rollback machinery); the same class's `delete()` removes an
inactive theme's directory entirely. Branding: a site
logo and favicon (both reuse `MediaService::upload()`; `.ico` was added
to its allow-list alongside the already-supported PNG), and the
`site_name` option (saved by the installer since LP-028, but until now
never actually read anywhere) is finally wired into the theme. Since
theme templates have no direct route to `PressConfig`/`MediaService`,
these render through a new static bridge,
`LumoraPress\Core\Theme\SiteBranding` (mirroring `SiteUrl`/`BasePath`),
exposed to themes via `site_name()`/`site_logo_url()`/`favicon_url()`
helpers. Custom Code: a single Custom CSS field, rendered inside a
`<style>` tag on every public page. The Theme Options API (for
themes/plugins to register their own controls), Layout/Typography/Color
customization, Navigation/Footer content options, and Accessibility
settings are not yet implemented.

The Plugins screen has a modern Plugin Browser (LP-045) on top, mirroring
the Theme Browser above. `LumoraPress\Core\Plugin\PluginRegistry`
discovers every plugin under `content/plugins/`, parsing the classic
WordPress plugin header — `Plugin Name:`/`Description:`/`Version:`/
`Author:`/`Author URI:`/`Plugin URI:`/`License:`/`License URI:`/
`Requires at least:`/`Requires PHP:`/`Requires Plugins:`/`Tags:` — out of
the plugin's own main file (`content/plugins/{slug}/{slug}.php`, since a
plugin's main file — unlike a theme's fixed `style.css` — is named
identically to its own directory) plus the same
`preview.*`/`thumbnail.*`/`screenshot.*` and `README`/`CHANGELOG`
detection the Theme Browser already established. A plugin whose declared
PHP requirement exceeds the server's is shown as Disabled and cannot be
activated. New plugins install from an uploaded ZIP through
`LumoraPress\Services\PluginInstaller`, via a two-step stage/confirm flow:
the archive is validated and staged first, and the admin always sees what
is about to be installed — with an explicit Replace/Cancel choice
whenever the archive's slug collides with an already-installed plugin —
before anything is committed; a collision's replacement extracts into a
temporary directory and only removes the previous installation once that
succeeds, so a bad upload never destroys a working plugin. A
`lp_plugin_details_panel` action hook mirrors the Theme Browser's
`lp_theme_details_panel`. Per-plugin update checking is not yet
implemented — unlike core's own GitHub-release updater (LP-024/LP-027),
a plugin has nowhere to declare where its updates come from.

The Media Manager (renamed from "media library") is implemented as a
first pass: uploads now support documents/archives (PDF, ZIP, CSS, TXT,
XML, JSON) and audio/video, not just images, via an expanded
`LumoraPress\Services\MediaService` allow-list. A real, unlimited-depth
virtual folder system (`LumoraPress\Services\FolderService`) organizes
files without ever touching where they physically live — a folder is a
plain database row, and reassigning a file's folder never changes its
stable public URL. Every file also gets alt text, caption, description,
notes, and a SHA-256 hash, alongside the metadata already captured at
upload (original/stored filename, MIME type, dimensions, size, upload
date). Before deleting a file, `LumoraPress\Services\MediaUsageChecker`
checks whether anything references it (a post's featured image, the site
logo/favicon) and requires an explicit "Delete Anyway" confirmation if
so; bulk delete never force-deletes a referenced file, it's skipped and
reported instead. The admin Media Manager screen supports search/filter
(filename, folder, type, upload date) and bulk move/delete. Bulk rename/
replace/change-metadata, dimension/file-size search filters, Smart
Collections, and a future S3/R2-compatible storage backend are not yet
implemented.

Thumbnail Generation is implemented: `LumoraPress\Services\ThumbnailService`
generates resized copies of image uploads with GD (no Imagick dependency)
in three configurable sizes (small/medium/large by default, each with its
own dimensions and crop-vs-fit mode, adjustable from Settings &rsaquo; Media), corrects
EXIF orientation before resizing, never upscales, and strips metadata for
free since GD's encoders don't carry it through. WebP/AVIF output is only
attempted when the running GD build supports it. The Media Manager
generates thumbnails automatically on upload, offers per-item and bulk
regeneration (with a "missing only" option and a batch-per-request
progress bar, progressively enhanced by JavaScript to auto-continue), an
orphaned-thumbnail cleanup tool, and cleans up thumbnails when a file is
deleted. Extensible via `thumbnail_sizes`/`thumbnail_max_pixels` filters
and `thumbnail_generated`/`thumbnail_generation_failed` actions.

Featured Images are implemented for both Posts and Pages: a "Featured
Image" meta box in each editor lets you pick an existing image or upload
a new one (no modal picker — a plain existing-image `<select>`, matching
this project's low-tech admin UI elsewhere), with automatic thumbnail
generation and `MediaUsageChecker` protection against deleting an in-use
image. Themes get a small, WordPress-inspired API — `has_post_thumbnail()`,
`post_thumbnail_url()`, `the_post_thumbnail()` (real `srcset`/`sizes`,
falling back through original → configurable default image → nothing),
`post_thumbnail_caption()` — and the default theme uses it on single
posts/pages and in the homepage/archive post list. Single post/page pages
now render Open Graph and Twitter Card meta tags, and feed items can
optionally include the featured image as an RSS/Atom enclosure. Bulk
assign/remove is not yet implemented (listed as optional in the ticket).

FTP Media Import is implemented: `LumoraPress\Services\MediaImportService`
registers media files already sitting on the server's filesystem (dropped
there via FTP/SFTP/a hosting file manager) into the Media Manager without
a browser upload round-trip. Administrators configure one or more allowed
server directories on the Settings &rsaquo; Media page; every scan and import is
restricted to a `realpath()`-resolved descendant of one of those
directories, re-checked immediately before each filesystem operation.
The "Import from Server" screen (inside Media Manager) scans a chosen
directory (recursively or not), previews what it found — already-imported
files are detected via the same SHA-256 hash `MediaService` already
stores and shown as duplicates, unchecked by default — lets you pick a
destination folder or mirror the scanned directory structure into new
folders automatically, and optionally uses each file's modification time
as its stored upload date. Imported images get thumbnails generated the
same as an upload. Large imports process in batches of 10 with a
progress bar (the same pattern as bulk thumbnail regeneration). Bulk
category/tag assignment during import and CLI/API support are not
implemented (media has no category/tag fields in this app, and there is
no CLI entrypoint in this codebase).

The Media Viewer & Lightbox is implemented: a PhotoSwipe 5 lightbox
(loaded from the jsDelivr CDN at a pinned version, not vendored locally)
for images, with prev/next navigation across whichever list-view page
you're on — homepage, archive, category, tag, or search results (search
results gained their own featured-image thumbnail for this). A single
post/page's featured image is a lightbox of one. The theme API is
`the_post_thumbnail_lightbox()` (`include/media-functions.php`), which
wraps the existing `the_post_thumbnail()` output in the anchor PhotoSwipe
needs and shows a caption from the media's caption/alt text. A new
`MediaViewer` flag ensures the PhotoSwipe assets only load on pages that
actually used the lightbox. The admin Media Manager's edit page also
lightboxes its image preview and gained native inline video/audio/PDF
viewers. Album/Favorites/Most-viewed/Custom-collections navigation are
not applicable — none of those concepts exist in Lumora Press (that's
Lumora Gallery's domain).

A versioned REST API is implemented under `/api/v1/...`
(`LumoraPress\Controllers\ApiController`), covering Posts, Pages,
Categories, Tags, Comments, and Search. Reads are always public
(published/approved content only); writes require a named, revocable API
token (`Authorization: Bearer {selector}:{validator}`, generated and
managed by every user on a new "API Tokens" admin page) and repeat the
same capability/ownership rules the admin UI already enforces. Every
response uses a consistent JSON envelope with real pagination and
filtering. A "REST API" section on Settings &rsaquo; General lets an administrator
disable the whole API, individual resources, or just anonymous comment
submission, each rejected with a clean JSON error rather than an HTML
page — with `rest_api_enabled`/`rest_api_resource_enabled` filters and a
`rest_api_request` action for plugins. Authenticated reads of your own
drafts and API rate limiting are not implemented (recorded as
out-of-scope in `TODO.md`, not silently missing).

Search beyond Posts/Pages (categories/tags/authors/comments/media), and
RSS feeds beyond the site-wide posts feed described above (category/tag/
author/comment feeds, JSON Feed), are not yet implemented —
see `TODO.md` for the full phase breakdown and remaining known gaps.
