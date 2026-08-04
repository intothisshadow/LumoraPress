# History

Completed work is moved here from `TODO.md` at release time.

---

## 0.1.0 — Foundation (2026-07-21)

### PHP Test Suite

- [x] Plan and implement a PHP test suite for Lumora Press, using the FanUpdate Redux test suite as a reference for structure, configuration, and conventions: `/mnt/Winterfell/Coding/Github/Scripts/FanUpdateRedux/PHP Test Suite`. (`/mnt/Winterfell/Coding/Github/Scripts/Lumora Gallery/PHP Test Suite` was named as a reference too but was outside this session's accessible directories — FanUpdate Redux's suite, explicitly named as a reference in the line above, was used instead.)
- [x] Adapt and customize the test suite to fit Lumora Press's codebase, architecture, and functionality.
- [x] Document the plan in `PHP-TEST-SUITE.md`.
- [x] Store all test suite files and directories within: `/mnt/Winterfell/Coding/Github/Scripts/Lumora Press/PHP Test Suite`.

#### Requirements

- [x] The Lumora Press PHP test suite must be fully self-contained within the `PHP Test Suite` directory.
- [x] Use the Lumora Press test suite as a reference for overall structure, configuration, and testing conventions. (See note above — FanUpdate Redux's suite was used as the accessible reference.)
- [x] Ensure the implementation is tailored specifically to Lumora Press and does not retain FanUpdate Redux-specific references, naming, configuration, or assumptions.

### Phase 1 (Foundation)

#### Goal

Create the initial, production-quality foundation for Lumora Press.

This phase focuses on building the application's architecture, installer, administration area, and core infrastructure.

**Do NOT implement advanced blogging features yet.**

The objective is to establish a stable, maintainable codebase that future features can build upon.

---

#### Phase 1.1 — Project Structure

- [x] Create the initial directory structure. (`public/` from the suggested layout below was created initially but removed on 2026-07-21 once it turned out to hold nothing but a dead redirect stub — see `DECISIONS.md`'s "Removed the empty `public/` directory".)

Suggested layout:

```
LumoraPress/

admin/
app/
config/
content/
    plugins/
    themes/
    uploads/

docs/
include/
install/
public/

storage/
vendor/
```

Within `app/` create:

```
Core/
Controllers/
Models/
Services/
Views/
```

Keep the structure clean and future-proof without overengineering.

---

#### Phase 1.2 — Installer

Create a modern installer similar in quality to Lumora Gallery `/mnt/Winterfell/Coding/Github/Scripts/Lumora Gallery`.

Support:

- [x] Database configuration
- [x] Administrator account creation
- [x] Site name
- [x] Timezone
- [x] Language (single "en" locale offered for now; selector is in place for future languages)
- [x] Table prefix
- [x] Generate secure configuration
- [x] Installation verification (already-installed guard + live DB connection test)

Requirements:

- [x] PDO
- [x] Prepared statements
- [x] CSRF protection
- [x] Modern password hashing
- [x] Friendly installer UI

---

#### Phase 1.3 — Bootstrap

Create the application bootstrap.

Responsibilities:

- [x] Load configuration
- [x] Start session
- [x] Connect database
- [x] Load services
- [x] Load plugins
- [x] Load theme
- [x] Dispatch request

Everything should initialize from one central bootstrap.

---

#### Phase 1.4 — Routing

- [x] Implement lightweight routing.

Support routes such as:

- [x] /
- [x] /post/example-post
- [x] /category/news
- [x] /tag/php
- [x] /archive
- [x] /search
- [x] /page/about
- [x] /admin/

Do not use a third-party routing framework.

---

#### Phase 1.5 — Database Layer

- [x] Create a reusable PDO database service.

Requirements:

- [x] Prepared statements
- [x] Transactions
- [x] Error handling
- [x] Helper methods
- [x] Future migration support
- [x] No SQL should appear inside templates.

---

#### Phase 1.6 — Configuration Service

- [x] Create a configuration manager.

Support:

- [x] Read configuration
- [x] Write configuration
- [x] Cached configuration
- [x] Runtime configuration

Avoid scattered configuration variables.

---

#### Phase 1.7 — Authentication

- [x] Implement authentication.

Support:

- [x] Login
- [x] Logout
- [x] Sessions
- [x] Remember Me — `LumoraPress\Core\Security\RememberMeService`, a selector/validator persistent-login cookie backed by a new `{prefix}remember_tokens` table (migration `0003_create_remember_tokens_table.sql`). Tokens are single-use (rotated on every successful validation) and revoked entirely for a user if a validator mismatch suggests a stolen cookie. `Auth.php` itself was not touched — the login/logout/session-check flow calls the new service alongside it, exactly as originally planned.
- [x] Password hashing
- [x] Session regeneration

No registration system yet.

Administrator account only.

---

#### Phase 1.8 — User Roles

Design the architecture now.

- [x] Support:
- [x] Administrator
- [x] Editor
- [x] Author
- [x] Contributor
- [x] Subscriber

Only Administrator needs to be fully functional in Phase 1.

---

#### Phase 1.9 — Administration Framework

- [x] Create the admin shell.

- [x] Menu:
- [x] Dashboard
- [x] Posts
- [x] Media
- [x] Pages
- [x] Comments
- [x] Appearance
- [x] Plugins
- [x] Users
- [x] Tools
- [x] Settings

Most pages may initially contain placeholders.

Focus on creating the framework.

---

#### Phase 1.10 — Dashboard

- [x] Build the first real admin page.
  - [x] Include placeholders for:
  - [x] Recent Posts
  - [x] Recent Comments
  - [x] Quick Draft
  - [x] System Information
  - [x] Update Status
  - [x] Welcome panel

The dashboard should immediately feel useful.

---

#### Phase 1.11 — Theme System

- [x] Implement classic PHP themes.

Support:

- [x] header.php
- [x] footer.php
- [x] sidebar.php
- [x] index.php
- [x] single.php
- [x] page.php
- [x] archive.php
- [x] search.php
- [x] 404.php
- [x] functions.php

No block themes.

No theme.json.

---

#### Phase 1.12 — Default Theme

- [x] Create a lightweight default theme.

Goals:

- [x] Clean
- [x] Responsive
- [x] Accessible
- [x] Traditional blog layout
- [x] Sidebar
- [x] Header
- [x] Footer
- [x] Widget areas

The default theme should resemble classic blogging themes rather than modern landing-page builders.

---

#### Phase 1.13 — Plugin System

- [x] Create a lightweight plugin loader.

Design around:

- [x] add_action()
- [x] do_action()
- [x] add_filter()
- [x] apply_filters()

The hook system should become one of the project's most stable APIs.

---

#### Phase 1.14 — Widget System

- [x] Design the widget architecture.

Implement:

- [x] Sidebar registration
- [x] Widget registration
- [x] Widget rendering

Do not create many widgets yet.

Only prove the architecture. (One Text widget ships in the default theme.)

---

#### Phase 1.15 — Menu System

- [x] Create navigation menus.

Support:

- [x] Primary
- [x] Footer
- [x] Social (location registered; no items assigned by default)
- [x] Secondary (location registered; no items assigned by default)

Menus should be editable later. (Assignment is currently programmatic — an admin UI for building menus is a later phase.)

---

#### Phase 1.16 — Media Library

- [x] Create the initial media framework.

Support:

- [x] Upload
- [x] Browse
- [x] Delete
- [x] Search
- [x] Image metadata (width/height for images)

Do not build advanced image management.

Lumora Gallery remains the dedicated gallery application.

---

#### Phase 1.17 — Security

Implement from day one:

- [x] CSRF protection
- [x] Prepared statements
- [x] Output escaping
- [x] Secure cookies
- [x] Session regeneration
- [x] Rate limiting where appropriate (e.g. login attempt throttling) — `LumoraPress\Core\Security\LoginThrottle`, keyed by IP address, persisted in the database (`{prefix}login_attempts`, migration `0002_create_login_attempts_table.sql`) rather than the session
- [x] Content Security Policy support — `LumoraPress\Core\Security\ContentSecurityPolicy` sends a strict, same-origin-only `Content-Security-Policy` header on every response (front end, admin, and the installer). Themes/plugins can loosen individual directives via the `csp_directives` filter; site owners can disable it entirely via the `csp_enabled` config option (e.g. if a reverse proxy already sets its own policy).

Never postpone security work.

---

#### Phase 1.18 — Localization

**Deferred (2026-07-21)** — see `DECISIONS.md`'s "Localization support deferred, not committed to". Solo developer, Finnish/English only; full i18n coverage isn't a confirmed goal. Not blocking a live test install or Phase 1 completion.

Prepare the application for translation.

- [ ] Every user-facing string should be translatable. — deferred; the `__()`/`_e()` mechanism (filter-based, ready for a future .po/.mo loader) already exists and is used in the default theme and shared helpers as a low-cost side effect of normal development, but deliberately wrapping every remaining hardcoded string (mainly in admin views) is on hold until localization is a confirmed goal

---

#### Phase 1.19 — Error Handling

- [x] Create a reusable error handling system.

Support:

- [x] Logging
- [x] Friendly public messages
- [x] Developer debugging mode
- [x] Exception handling

---

#### Phase 1.20 — Documentation

Create:

- [x] README.md
- [x] CHANGELOG.md
- [x] HISTORY.md
- [x] TODO.md

Document architecture decisions as development progresses.

---

#### Not Yet (as of Phase 1)

The following were intentionally postponed until later phases:

- Posts (landed in 0.2.0 — see below)
- Pages
- Categories
- Tags
- Comments
- RSS
- Search
- Markdown editor
- WYSIWYG editor
- Revisions
- Scheduled posts (landed in 0.2.0 as part of Posts)
- Custom fields
- XML import
- REST API
- SEO tools
- Theme options
- Automatic updates

#### Success Criteria

Phase 1 was complete when:

- The installer successfully installs Lumora Press.
- An administrator can log in.
- The administration panel is fully functional.
- The dashboard loads.
- Themes load correctly.
- Plugins can register hooks.
- Widgets can be registered.
- The application architecture is clean, documented, and production-ready.
- Future blogging functionality can be added without major architectural changes.

**Remember the project philosophy:**

> Sit down. Write. Publish.

### LP-002. Unique Database Table Prefix Generation

- [x] Improve installer security by automatically generating a cryptographically random database table prefix during installation instead of using a predictable default.

Requirements:

- [x] Generate a secure random alphanumeric prefix (e.g. `lum_a8f3d1_`).
- [x] Allow the user to override it manually if desired.
- [x] Preserve compatibility with all database queries and future upgrades.

### LP-004. Automatically Remove the Installer

#### Goal

After a successful installation or upgrade, offer to automatically remove the `install/` directory.

(No upgrade flow exists yet — only fresh installation — so this covered post-install only. Extend to post-upgrade once an upgrade flow exists.)

#### Requirements

- [x] Attempt deletion automatically when filesystem permissions allow. — `LumoraPress\Core\InstallerCleanup::remove()`, called from `install/index.php` right after a successful install, recursively deletes `install/` including the currently-running script itself (safe on Unix-like filesystems: unlinking an open file only removes its directory entry).
- [x] If automatic deletion fails, display clear instructions reminding the administrator to remove the directory manually. — shown inline on the success page.
- [x] Never prevent the installation from completing if deletion is unsuccessful. — cleanup is attempted only after config generation, admin account creation, and options are already fully committed; a cleanup failure only changes the message shown, never the outcome.

---

## 0.3.0 — Admin (2026-07-25)

### LP-043. Reorganize Admin Navigation

#### Goal

Improve the overall organization of the administration panel by separating configuration from maintenance tasks and grouping related functionality together.

**2026-07-25 update:** Implemented. `admin/index.php` now supports two-level
menus (`children` + `default_child` per top-level entry) via a second
`/admin/{page}/{subpage}` route registered in `include/bootstrap.php`
alongside the existing single-segment one — `Router` itself needed no
changes, it already matches patterns generically. Old bookmarks to
`/admin/settings`, `/admin/updates`, and `/admin/tools` 302-redirect to their
new nested location. Categories/Tags/Users/API Tokens were **not** restructured
(mockup's "My Account"/"Roles" sub-nav under Users has no backing page —
`Roles` doesn't exist as its own screen and there's no separate "My Account"
profile screen — left as a follow-up, not required by the Tasks checklist
below). `admin/views/maintenance/{tools,import,export,logs}.php` don't exist
as files — they fall through to the existing `placeholder.php`, same
established pattern the old flat `tools` menu entry already used before this
change.

#### Proposed Navigation

```
Dashboard

Posts
Pages
Media
Comments

Appearance
Plugins

Settings
    General
    Media
    Cache
    Maintenance Mode
    Security

Maintenance
    Updates
    Import
    Export
    Tools
    System Information
    Logs

Users
    My Account
    Users
    Roles
```

#### Tasks

- [x] Add a **"View Site"** link to the Admin navigation beneath the version information
- [x] Create the new **Settings** parent menu
- [x] Move configuration pages under **Settings**
- [x] Keep maintenance utilities under **Maintenance**
- [x] Ensure menu highlights remain correct
- [x] Preserve backward compatibility for existing admin URLs where possible
- [x] Add breadcrumbs to all admin pages
- [x] Verify user permissions continue to work correctly — checked with a non-`manage_options` role (Author): Settings/Maintenance don't render in the sidebar, and direct URLs to their sub-pages 403 correctly
- [x] Update documentation and screenshots — README/CHANGELOG updated; this project has never included doc screenshots for any ticket, so none were added here either

#### Design Goals

- Configuration lives under **Settings**
- Administrative actions live under **Maintenance**
- Consistent naming throughout the admin
- Easy for new users to discover features
- Scalable navigation for future additions

This reduces the chance of leaving the installer accessible after deployment.

### LP-001. Thumbnail Generation

**Note:** this was fully implemented before 0.3.0 was cut (confirmed via
`git log` — `ThumbnailService.php` already existed at the 0.3.0 release
commit) and shipped as part of that release, but was never migrated out
of `TODO.md` at the time — a bookkeeping gap, not new 0.4.0 work. Filed
here retroactively; 0.3.0's own `CHANGELOG.md` entry is left as originally
published rather than rewritten after the fact.

#### Goal

Automatically generate and manage thumbnails for uploaded media.

#### Features

- [x] Generate thumbnails automatically on upload
- [x] Configurable thumbnail dimensions
- [x] Crop or resize modes
- [x] Preserve aspect ratio when resizing
- [x] Generate multiple thumbnail sizes (e.g. small, medium, large)
- [x] Support JPEG, PNG, GIF, WebP, and AVIF where available — GD only (no
      Imagick); WebP/AVIF are generated only when the running GD build
      supports them, skipped otherwise. AVIF is moot in practice today
      since `MediaService`'s upload allow-list doesn't accept `image/avif`
      yet — the code path exists for when/if that's added.
- [x] Respect EXIF orientation before generating thumbnails
- [x] Configurable image quality/compression — JPEG/WebP quality settings;
      no separate AVIF quality setting (same reason as above).
- [x] Strip unnecessary metadata from generated thumbnails — free: GD's
      encoders never carry EXIF/ICC through from the source image.
- [x] Prevent upscaling of images smaller than the target size
- [x] Regenerate thumbnails for individual media items
- [x] Bulk thumbnail regeneration tool
- [x] Regenerate only missing thumbnails
- [x] Delete orphaned thumbnails — DB-level reconciliation (thumbnail rows
      whose parent media no longer exists), not a filesystem tree walk;
      `deleteForMedia()` already keeps files/rows in lockstep on the
      normal delete path, so this is a safety net, not the primary path.
- [x] Background/batch processing for large libraries — synchronous
      batch-per-request loop with a redirect/offset (no queue/cron
      infrastructure exists anywhere in this codebase yet).
- [x] Progress indicator for bulk regeneration
- [x] Gracefully skip unsupported or corrupted images while logging errors
- [x] Optional sharpening after resizing — fixed unsharp-style kernel,
      on/off toggle only (no configurable strength).
- [x] Developer hooks for custom thumbnail sizes and generation logic —
      `thumbnail_sizes`/`thumbnail_max_pixels` filters,
      `thumbnail_generated`/`thumbnail_generation_failed` actions.

#### Settings

- [x] Thumbnail dimensions
- [x] Crop vs. fit mode
- [x] JPEG/WebP/AVIF quality — JPEG/WebP only, see note above
- [x] Enable/disable additional thumbnail sizes
- [x] Maximum processing memory/time safeguards — max source-pixel-count
      safeguard; no separate wall-clock time limit setting.

#### Deliverables

- Automatic thumbnail generation integrated into media uploads
- Thumbnail regeneration tools in the admin panel
- Efficient storage and cleanup of generated thumbnails
- Updated documentation and configuration options

### LP-021. REST API

**Note:** also shipped as part of 0.3.0, documented in `CHANGELOG.md`'s
0.3.0 entry ("REST API (LP-021): a new, versioned JSON REST API...") at
the time, but — like LP-001 above — the ticket itself was never migrated
out of `TODO.md`. Filed here retroactively.

#### Goal

Create a versioned REST API.

#### Initial Endpoints

All under `/api/v1/...` (`ApiController`, wired in `include/bootstrap.php`).
Reads are always public and only ever return published/approved content;
writes require an API token (see Authentication below) plus the same
capability/ownership rules the admin UI already enforces.

- [x] Posts — `GET /posts` (filter: `category`, `tag`, `author`, `page`,
      `per_page`), `GET /posts/{slug}`, `POST /posts`, `PATCH /posts/{id}`,
      `DELETE /posts/{id}`.
- [x] Pages — same shape as Posts.
- [x] Categories — `GET /categories`, `GET /categories/{slug}`, `POST`/
      `PATCH`/`DELETE` (create/update: `edit_posts`; delete: `delete_posts`
      — matches the existing admin convention that category/tag deletion
      is gated on the *post* capability, not a dedicated one).
- [x] Tags — same shape as Categories.
- [x] Comments — `GET /comments?post_id=` (approved tree), `POST /comments`
      (public, mirrors `SiteController::submitComment()`'s pending/
      auto-approve/flood-control rules), `PATCH`/`DELETE /comments/{id}`
      (moderation, `moderate_comments`).
- [x] Search — `GET /search?q=&page=`, thin wrapper over
      `SearchService::search()`.

#### Features

- [x] Authentication — new `ApiTokenService` (`app/Core/Security/`):
      named, revocable, non-rotating bearer tokens
      (`Authorization: Bearer {selector}:{validator}`), structurally
      mirroring `RememberMeService`'s selector/validator/SHA-256 shape but
      without rotation (an API token must stay stable across many
      requests, unlike a single-use remember-me cookie). Self-service
      management at `admin/views/api-tokens.php` (every authenticated
      role, including Subscriber, manages their own tokens — no
      cross-user token oversight view yet, deferred not dropped).
- [x] Permissions — every write endpoint repeats the exact capability +
      ownership checks (`canManagePost()`/`canDeletePost()` and Page
      equivalents) already encoded in `admin/views/posts.php`/`pages.php`,
      not a new permission model.
- [x] Pagination — `meta: {page, perPage, total, totalPages}` on every
      collection response, built from each service's existing
      `paginate*()` return shape.
- [x] Filtering — `category`/`tag`/`author` on Posts (`author` needed a
      small new `PostService::paginateByAuthor()`, mirroring
      `paginateByCategory()`).
- [x] JSON responses — new `ApiResponse` helper (`app/Core/Http/`):
      `{"data": ...}` / `{"data": [...], "meta": {...}}` /
      `{"error": {"message": ..., "code": ...}}`, consistent across every
      endpoint.

Explicitly out of scope, recorded rather than silently dropped:
authenticated reads of your own drafts/scheduled content (every GET is
published-only regardless of the bearer token — no second visibility
model on top of `isPubliclyVisible()`); rate limiting/abuse throttling on
the API itself (pushed to LP-039 below); Media endpoints (not in the
ticket's own "Initial Endpoints" list).

### LP-029. Show Version Number in Admin UI

**Implemented (2026-07-21).**

#### Goal

Make the running version visible in the admin area, both at a glance on every page and in more detail on the Dashboard.

#### Requirements

- [x] Show the version number (and codename) in the Dashboard's System Information widget (`admin/views/dashboard.php`) — was already showing the bare version number; added the codename alongside it (e.g. "0.2.0 (Posts)").
- [x] Show a short version label under the "Lumora Press" brand in the admin sidebar (`admin/views/layout-header.php`), visible on every admin page, not just the Dashboard.

### LP-030. Leftover Installer Directory Warning

**Implemented (2026-07-21).** Manually verified on a live install: the
`install/` directory is now actually removed after a successful manual
ZIP update (LP-026's `UpdateService::install()` fix), and the Dashboard
alert correctly reflects its absence.

#### Goal

Warn the administrator on the Dashboard if `install/` is still present on
disk, so a leftover installer doesn't go unnoticed. `InstallerCleanup`
already best-effort-deletes `install/` right after a successful install,
but a locked-down host may not allow PHP to delete its own files, and
`install/` is one of LP-026's `CORE_PATHS` — meaning a manual ZIP update
re-extracts and resurrects it even on a site where the administrator had
already deleted it by hand after installing. `install/index.php` itself
already refuses to run once `config/config.php` exists (shows
"already installed"), so this isn't an active reinstall risk, but leaving
unnecessary installer code reachable is still worth flagging and cleaning
up.

#### Requirements

- [x] Dashboard checks whether `install/` exists on every load and shows a
  prominent alert if it does, naming the directory and instructing the
  administrator to delete it.
- [x] The same check/alert fires after both a fresh install (cleanup
  failed) and after a manual ZIP update (LP-026) that reintroduced
  `install/`.
- [x] No change to `install/index.php`'s own already-installed guard —
  this is a hygiene reminder, not a new security boundary.

### LP-040. Featured Images

**Note:** also shipped as part of 0.3.0, documented in `CHANGELOG.md`'s
0.3.0 entry at the time, but the ticket itself was never migrated out of
`TODO.md`. Filed here retroactively.

#### Goal

Allow posts and pages to have a designated featured image for use in themes, listings, feeds, and social sharing.

#### Features

- [x] Set, change, or remove a featured image

- [x] Select from the Media Manager — a `<select>` of existing images
      (mirrors the folder-select pattern already used in the Media
      Manager), not a modal/JS picker — explicit scope narrowing, no
      existing precedent for a modal picker anywhere in this codebase.

- [x] Upload a new image while selecting

- [x] Display featured image in post/page editor

- [x] Optional featured images for posts and pages

- [x] Theme API for retrieving featured images — `has_post_thumbnail()`,
      `post_thumbnail_url()`, `the_post_thumbnail()`,
      `post_thumbnail_caption()` (`include/media-functions.php`).

- [x] Automatic thumbnail generation for featured images — re-uses
      LP-001's `ThumbnailService::generate()` on upload.

- [x] Responsive image support (`srcset`/`sizes`)

- [x] Configurable default featured image

- [x] Fallback when no featured image is assigned

- [x] Include featured images in RSS/Atom feeds (optional) — RSS2
      `<enclosure>` / Atom `<link rel="enclosure">`, gated by a
      `feed_featured_images` setting (default on).

- [x] Open Graph image support

- [x] X (Twitter) Card image support

- [x] Support WebP and AVIF where available — inherited for free from
      LP-001's `ThumbnailService`/`MediaService`, no new format-handling
      code needed here.

- [x] Alt text support for accessibility

- [x] Image captions and descriptions available to themes —
      `post_thumbnail_caption()`.

- [x] Replace or crop featured image after upload — "replace" via the
      Media Manager/re-upload; "crop" is now a real interactive
      rectangle-drag cropper (see "Manual cropping" below), superseding
      the earlier scope note about only picking a different crop-mode
      thumbnail size.

- [x] Prevent deletion of images currently used as featured images without confirmation —
      `MediaUsageChecker` now also checks Pages, not just Posts.

- [x] Manual cropping of featured image on Post and Page — a per-item
      crop rectangle (`featured_image_crop` column on `{prefix}posts`/
      `{prefix}pages`, JSON `{x,y,width,height}` in the original image's
      pixel coordinates), editable via a plain vanilla-JS drag-to-select/
      move/resize-by-corner rectangle overlaid on the current featured
      image (`admin/assets/js/featured-image-crop.js`) — deliberately no
      new cropping library/CDN dependency, since a single rectangle is
      implementable directly and this project only reaches for a CDN
      library when plain DOM code genuinely can't do the job (see
      `docs/THIRD-PARTY.md`). `ThumbnailService::generateFeaturedCrop()`
      renders one deterministically-named, content-addressed derivative
      image per crop rectangle (not tracked in `media_thumbnails`, which
      is keyed by named size, not by arbitrary rectangle) and
      `post_thumbnail_url()`/`the_post_thumbnail()` use it in place of
      the automatic centered thumbnail whenever a crop is set for that
      item's own featured image. Scope notes:
  - Cropping is only offered for an *already-saved* featured image, not
    a file just chosen in the same still-unsaved form — save first
    (matches classic WordPress's own two-step flow).
  - A manual crop produces one fixed-size image, not a responsive
    `srcset` — a deliberate simplification; `the_post_thumbnail()`
    documents this in its own docblock.
  - Changing or clearing a crop leaves its previous derivative file on
    disk (no reference-counted cleanup) — an accepted, documented gap.
  - The REST API (LP-021) has no endpoint to read or write a crop
    rectangle; it's admin-UI-only for now, the same scope line already
    drawn around LP-008's Trash/Restore/Delete-Permanently actions.

#### Theme API

Provide a standardized Featured Image API for themes and plugins.

#### Deliverables

- Featured image support integrated into posts and pages
- Theme API and template helpers
- Social sharing metadata integration
- Updated documentation

## 0.4.0 — Updates (2026-08-01)

### LP-017. Revisions

**Implemented (2026-08-01).** A revision snapshot is saved automatically to
a new `{prefix}revisions` table (migration `0017`) each time a Post or Page
is updated via the admin editor (`admin/views/posts.php`/`pages.php`) — no
separate "save revision" action, no cron job. A new `RevisionService`
manages storing/listing/pruning snapshots; it doesn't touch
`PostService`/`PageService` itself (their constructors were left
unchanged), so the calling admin view snapshots the pre-update state, then
calls the normal `update()` — the same place category/tag assignment
already happens after save. Restoring a revision itself first snapshots the
current state, so a restore is always undoable. Deleting a post/page also
deletes its revisions (`RevisionService::deleteAllFor()`, called from the
admin delete handlers since revisions live outside PostService/PageService).

#### Goal

Implement revision history.

#### Features

- [x] View revisions — a "Revision History" panel on the Post/Page edit
      screen (`admin/views/posts.php`/`pages.php`) lists every snapshot with
      its date and author.
- [x] Compare revisions — a line-based diff (`LumoraPress\Core\Content\TextDiff`,
      no external dependency) between a selected revision and the current
      content.
- [x] Restore revisions — reapplies a revision's title/content/excerpt/
      format via the normal `PostService::update()`/`PageService::update()`
      path (status, publish date, featured image, etc. are left as they are).
- [x] Configurable retention — Settings > General > Revisions
      (`revision_retention` option, default 25; 0 = unlimited).

---

### LP-027. Updates from GitHub Releases

**Phase 1 implemented (2026-08-01).** `LumoraPress\Services\GitHubReleaseProvider`
queries the GitHub Releases API (stable via `/releases/latest`, or
`/releases` listing for the `prerelease` channel), configurable via
`update_github_repo`/`update_github_token`/`update_channel` options on the
Maintenance &rsaquo; Updates page. It prefers the curated `LumoraPress-v{version}.zip`
release asset LP-052 produces — verifying its SHA-256 checksum when the
release publishes a matching `.sha256` asset — and falls back to GitHub's
`zipball` endpoint when a release wasn't cut with the curated asset
attached. Both download paths go through GitHub's API asset/zipball
endpoints with an `Accept: application/octet-stream` header rather than a
bare `browser_download_url` redirect, so an `Authorization` header is
never handed to curl to forward across a cross-host redirect to a
third-party download host.

Once downloaded, the package is fed straight into the existing LP-026
pipeline (`UpdateService::checkUpload()`/`install()`) unchanged — the same
validation, compatibility checks, backup, migration, and rollback logic
manual ZIP uploads already use, so the "Update Summary" confirm/install/
cancel UI is identical regardless of source. `update_log` gained a
`source` column (migration `0020_add_source_to_update_log.sql`,
default `'manual'`) so the Updates page's history table can show whether
each attempt came from GitHub or a manual upload.

The install/download step stays the fully manual, administrator-initiated
process the ticket's Phase 1 roadmap calls for — nothing downloads or
installs without an explicit click each step.

**Backups UI, Dashboard notifications, and scheduled checks added
(2026-08-01), as a scoped slice of Phase 2.** `UpdateBackupService::listBackups()`
pairs each `files-*.zip`/`db-*.sql` backup by their shared
`{version}-{Ymd-His}` filename key (matched by the fixed-length timestamp
suffix, so version strings containing dashes, e.g. `1.2.0-beta`, still
parse correctly); `restoreFilesByFilename()`/`restoreDatabaseByFilename()`
resolve a backup by basename only, rejecting anything that isn't a plain
`files-`/`db-`-prefixed name actually present in the backups directory
(never a path an admin form could tamper with). `UpdateService::restoreBackup()`
wraps both under the same update lock `install()` uses, so a restore can
never race a concurrent update. The Updates page's new **Backups** panel
lists every pair with a confirm-gated "Restore" button.

"Scheduled" update checks are cron-free — this codebase has no queue/cron
infrastructure (see LP-001's and LP-041's own notes on this) — so
`GitHubReleaseProvider::maybeCheckForUpdates()` is instead triggered
opportunistically from the Dashboard page load, throttled to at most once
per `update_check_interval` (hourly/daily/weekly, admin-configurable) via
the `update_last_checked_at` option, and skipped entirely when
`update_auto_check_enabled` is off. `cachedUpdateStatus()` reads the
cached result back without a network call, so every Dashboard load stays
cheap except the (throttled) one that actually checks. This only ever
*checks* — automatic downloads/installs remain unimplemented and
out of scope, matching the ticket's insistence that the install step stay
manual during active development. A manual "Check for Updates" click on
the Updates page also refreshes this same cache, so the Dashboard notice
reflects either check path.

Still unimplemented: displaying a minimum-PHP/compatibility summary
before download (real compatibility checking still happens after
download, via the existing validator — this would only be pre-download
informational text), caching GitHub API responses *between* the checks
described above (each check is still one live API call), and email
notifications. PHP 8.2/8.3 compatibility for this feature specifically was
not re-verified via the Docker matrix this session (host PHP 8.4 +
`composer test`/`composer test:integration` against a throwaway MySQL 8.0
container, plus `composer stan`/`composer cs-check`, all clean) — worth
doing before the next release.

**Updates page redesigned (2026-08-01)** to match Lumora Gallery's admin
Updates page layout (Ariane provided a screenshot for reference), while
keeping the manual ZIP upload path Gallery's page doesn't have. New: a
status-bar panel (installed version, database schema status, update
status/channel/last-checked, and the configured GitHub source with a
"View all releases" link); a persistent "Latest release" panel driven by
`GitHubReleaseProvider::cachedUpdateStatus()`'s now-expanded cache
(release date/name/notes/prerelease flag/download size, not just the
version and changelog URL); a Database Updates panel and a System status
panel (PHP version, ZIP/cURL availability, file permissions, disk space,
staging directory — `UpdateService::systemStatus()`, new); "Back up now"
and per-backup "Delete" (`UpdateService::createBackupNow()`/`deleteBackup()`,
`UpdateBackupService::deleteBackup()`, all new) alongside the existing
Restore; and `Migrator::status()` (new) backing the schema-status display.
`GitHubReleaseProvider::checkNow()` (new) is what "Check for Updates Now"
calls — same caching as the throttled auto-check, but always fetches
immediately regardless of the interval.

#### Goal

Implement a GitHub Releases integration that allows administrators to check for, download, and install official Lumora Press releases directly from GitHub.

**During active development, this feature should remain a manual, administrator-initiated process.** Automatic background updates can be added later once the update system has matured and proven reliable.

The system should work on shared hosting without requiring FTP, SSH, or Composer, while prioritizing reliability, transparency, and safe recovery from failures.

---

#### Development Roadmap

##### Phase 1 (Initial Release)

A fully manual update process.

- [x] Administrator manually checks for updates
- [x] Administrator reviews release notes
- [x] Administrator chooses whether to install
- [x] Administrator manually starts download
- [x] Administrator manually confirms installation

No automatic downloads or installations should occur.

##### Phase 2 (Future)

Optional automation.

- [x] Scheduled update checks (cron-free — Dashboard-load-triggered, throttled; see status note above)
- [x] Dashboard notifications
- [ ] Email notifications (Deferred)
- [ ] Optional automatic downloads (Deferred)
- [ ] Optional unattended updates (Deferred)

All automatic behavior should remain opt-in.

---

#### Features

##### Update Discovery

- [x] Check GitHub Releases API
- [x] Detect latest stable release
- [x] Ignore draft releases
- [x] Ignore pre-releases (configurable)
- [x] Compare installed version
- [x] Display update availability
- [x] Manual "Check for Updates" button

##### Release Information

Display:

- [x] Current version
- [x] Latest version
- [x] Release date
- [x] Release notes
- [x] Changelog
- [x] Download size
- [ ] Minimum PHP version
- [ ] Compatibility notes

##### Download

- [x] Download release ZIP directly from GitHub
- [x] Verify successful download
- [x] Validate package structure
- [x] Verify version information
- [x] Prepare installation

##### Compatibility Checks

Before installation:

- [x] PHP version
- [x] Required PHP extensions
- [x] File permissions
- [x] Disk space
- [x] Writable directories
- [ ] Configuration compatibility

##### Backup

Automatically create:

- [x] File backup
- [x] Database backup
- [x] Restore point
- [ ] Backup verification

##### Installation

- [ ] Enter maintenance mode (optional)
- [x] Extract release
- [x] Preserve configuration
- [x] Preserve uploads
- [x] Preserve themes
- [x] Preserve future plugins
- [x] Replace core files
- [x] Run database migrations
- [x] Clear caches
- [ ] Exit maintenance mode

##### Rollback

If installation fails:

- [x] Restore files
- [x] Restore database
- [x] Restore previous version
- [x] Display recovery report
- [x] Preserve error logs

##### Notifications

Phase 1:

- [x] Dashboard update notice
- [x] Updates page indicator

Future:

- [ ] Email notification (Deferred)
- [ ] Automatic update notifications (Deferred)

##### Logging

Record:

- [ ] Update checks
- [ ] Downloads
- [x] Installed versions
- [x] Rollbacks
- [ ] Migration history
- [x] Errors

---

#### Task List

##### GitHub Integration

- [x] Create GitHub Releases client
- [x] Parse Releases API
- [x] Detect latest stable release
- [x] Compare semantic versions
- [ ] Cache API responses
- [ ] Handle GitHub rate limiting gracefully

##### Backend

- [x] Build update service
- [x] Build download manager
- [x] Build installer
- [x] Build migration runner
- [x] Build rollback service
- [x] Build logging system

##### Admin Interface

- [x] Create Updates page
- [x] Display release notes
- [x] Display compatibility checks
- [x] Add "Check for Updates" button
- [x] Add "Install Update" button
- [x] Display update history
- [x] Display backups

##### Security

- [x] Validate downloaded archives
- [x] Prevent downgrade attacks
- [x] Verify package contents
- [ ] Validate migrations
- [x] Protect update endpoints
- [x] Ensure update actions require administrator privileges

##### Future Automation

- [ ] Background update checks (still request-triggered, not a real background/cron job — no queue/cron infrastructure exists in this codebase)
- [x] Scheduled checks (cron-free — see status note above)
- [ ] Email notifications (Deferred)
- [ ] Optional automatic downloads (Deferred)
- [ ] Optional unattended installation (Deferred)

##### Testing

- [x] Test update detection
- [x] Test downloads
- [x] Test installation
- [x] Test migrations
- [x] Test rollback
- [x] Test network failures
- [ ] Test interrupted installations
- [ ] PHP 8.2 compatibility
- [ ] PHP 8.3 compatibility
- [x] PHP 8.4 compatibility

##### Documentation

- [x] Update README.md
- [x] Document GitHub integration
- [x] Document manual update workflow
- [x] Document rollback procedure

---

### LP-045. Plugin Browser

#### Goal

Modernize the **Plugins** page by displaying installed plugins as visual cards with detailed information and management actions.

#### Features

##### Plugin Gallery

- [x] Display installed plugins as cards
- [x] Automatically detect preview images named:
  - [x] `preview.*`
  - [x] `thumbnail.*`
  - [x] `screenshot.*`
- [x] Show placeholder svg if no preview image exists
- [x] Support JPG, PNG, WebP, and AVIF preview images
- [x] Display a default plugin icon when no preview image exists
- [x] Clearly indicate whether a plugin is:
  - [x] Active
  - [x] Inactive
  - [x] Disabled (if applicable)
- [x] Search installed plugins
- [x] Sort plugins alphabetically
- [x] Filter by Active, Inactive, Update Available, and All

##### Plugin Details

- [x] Open a details panel/modal when a plugin is clicked
- [x] Display:
  - [x] Plugin name
  - [x] Version
  - [x] Author
  - [x] Description
  - [x] Homepage URL
  - [x] License
  - [x] Minimum Lumora Press version
  - [x] Required PHP version
  - [x] Dependencies
  - [x] Tags/categories
- [x] Show a larger preview image
- [x] Display additional screenshots if available
- [x] Display plugin status
- [ ] Indicate whether an update is available

##### Plugin Management

- [x] Upload plugin ZIP files from the browser
- [x] Validate plugin packages before installation
- [x] Display plugin information before confirming installation
- [x] Install plugins from uploaded ZIP archives
- [x] Detect existing plugins and offer Replace, Update, or Cancel
- [x] Activate immediately after installation (optional)
- [x] Preserve file permissions during installation
- [x] Roll back installation on failure
- [x] Display detailed installation progress and results

##### Plugin Actions

- [x] Activate plugin
- [x] Deactivate plugin
- [x] Delete plugin
- [ ] Check for updates
- [ ] Update plugin
- [x] View README if included
- [x] View CHANGELOG if included
- [x] Open plugin folder (optional)

##### Developer Support

- [x] Read metadata from the standard plugin header
- [x] Allow plugins to provide multiple screenshots
- [ ] Allow plugins to specify custom icons
- [x] Developer hooks for extending the details panel

#### Deliverables

- Modern visual plugin browser
- Plugin upload and installation system
- Plugin details modal/page
- Automatic preview image detection (`preview.*`, `thumbnail.*`, `screenshot.*`)
- Support for plugin icons and screenshots
- Backward compatibility with existing plugins

**Status (2026-07-26): Implemented**, except three items left unchecked above:
"Indicate whether an update is available" / "Check for updates" / "Update plugin"
— no per-plugin update-source concept exists yet (unlike core's GitHub-release
updater, LP-024/LP-027, a third-party plugin has nowhere to declare where its
updates come from); the "Update Available" filter option is present in the UI
for forward compatibility but currently never matches anything, same as any
other status with zero results. "Allow plugins to specify custom icons" is
also deferred — only preview/thumbnail/screenshot detection exists, no
separate icon concept.

Added `LumoraPress\Core\Plugin\{PluginInfo,PluginRegistry}` (mirrors
`ThemeInfo`/`ThemeRegistry` exactly, parsing the classic WordPress plugin
header — Plugin Name/Description/Version/Author/Author URI/Plugin URI/
License/License URI/Requires at least/Requires PHP/Requires Plugins/Tags —
from the plugin's main file rather than a fixed `style.css`, since a
plugin's main file is named identically to its own directory) and
`LumoraPress\Services\PluginInstaller`. Unlike `ThemeInstaller` (which
always installs immediately since only the currently-active theme is ever
"in the way"), a plugin install can collide with an already-installed
plugin of the same slug — `PluginInstaller` implements a two-request
stage/confirm flow (`stage()`/`inspectStaged()`/`finalize()`) so the admin
always reviews what's about to be installed before committing, with an
explicit Replace/Cancel choice on collision; a staged upload is extracted
into a temp directory first and the previous installation is only removed
once extraction succeeds, so a failed install never destroys a working
one. Rebuilt the Plugins admin view (`admin/views/plugins.php`) with the
same search box / native `<dialog>` details-panel pattern LP-044
established, extended with a status filter select and the
`lp_plugin_details_panel` action hook, wired through `Kernel`/
`bootstrap.php` (`pluginRegistry`/`pluginInstaller`) alongside the existing
`PluginManager` (which still only handles *loading* active plugins —
`PluginRegistry` only *describes* them). Covered by new unit tests
(`Unit/Core/Plugin/PluginRegistryTest.php`,
`Unit/Services/PluginInstallerTest.php`) and manually verified end-to-end
in a throwaway install (upload → review → install → activate → deactivate
→ delete, plus the Replace-on-collision path with a real version bump) —
see `docs/CHANGELOG.md`'s entry for the same day.

A real CSRF bug was caught and fixed during that manual verification,
the same mistake LP-012's docblock (`CommentService`) already warned
about: `Csrf::field()` overwrites the session token for a given action
name on every call, and each plugin's Activate/Deactivate/Delete forms
render twice per page (once on the card, once in the details panel) —
a bare `activate_plugin` action name left the card's button silently
submitting an already-invalidated token the moment the details panel's
identical form rendered after it. Fixed by scoping every action name by
slug and by which form rendered it (`activate_plugin_card_{slug}` vs.
`activate_plugin_details_{slug}`, mirroring `CommentService`'s
`comment_moderate_{id}_{status}` fix) — read this before adding any
future admin screen with the same control repeated in two places on one
page.

Also fixed in passing, unrelated to this ticket but discovered while
preparing to work on it: `admin/index.php`, `admin/views/layout-footer.php`,
`app/Core/Kernel.php`, `include/bootstrap.php`, `app/Controllers/
ApiController.php`, `app/Services/{FeedService,PageService,SearchService}.php`,
`admin/views/{pages,posts,media}.php`, `content/themes/default/
{header,single,page}.php`, `admin/assets/css/admin.css`, `README.md`, and
`docs/CHANGELOG.md` all had unresolved git merge conflict markers
committed straight to `main` — several of them (`Kernel.php`,
`bootstrap.php`, `ApiController.php`, the Posts/Pages admin views, and
the affected services) were fatal PHP parse errors, meaning the entire
application — front end and admin alike — was non-functional before this
session. See that commit's own message for the full resolution.

------

### LP-050. Theme File Editor

**Implemented (2026-08-01)** as a fourth Appearance sub-page (Appearance >
Theme Editor, alongside Themes/Widgets/Menus from LP-044/LP-048/LP-049).
`LumoraPress\Services\ThemeFileEditor` (pure filesystem, no DB) does all
the browsing/reading/writing; `admin/views/appearance/editor.php` is the
admin screen; `admin/assets/js/theme-file-editor.js` progressively
enhances the save textarea into a real CodeMirror 5 editor (loaded from
jsDelivr, same convention as EasyMDE/TinyMCE/PhotoSwipe — see
`docs/THIRD-PARTY.md`'s CodeMirror entry).

Every path `ThemeFileEditor` touches is re-resolved with `realpath()`
immediately before use and checked for containment inside the one theme
directory being edited — the same posture `MediaImportService::isPathAllowed()`
documents — so a tampered relative-path form field, or a symlink planted
inside a theme directory, can't be used to read/write outside that theme
(covered by a dedicated regression test using a real symlink on disk, not
just string assertions).

#### Goal

Provide a built-in editor for theme files, allowing administrators to safely view and edit theme source code directly from the admin interface.

#### Features

##### File Browser

- [x] Display installed themes
- [x] Select active or inactive theme
- [x] Browse theme directory tree
- [x] Search files by name — client-side filter (`data-lp-file-search`), matches by file/folder name only (not full-text content)
- [x] Display file sizes and last modified dates
- [x] Hide unsupported/binary files — only the allow-listed extensions below appear in the tree at all; a directory with no editable file anywhere beneath it is left out entirely too

##### Supported Files

- [x] PHP
- [x] CSS
- [x] JavaScript
- [x] HTML
- [x] Markdown
- [x] JSON
- [x] XML
- [x] TXT
- [x] SVG

##### Editor

- [x] Syntax highlighting — CodeMirror 5, mode chosen by file extension (PHP/CSS/JS/HTML/XML/Markdown; plain text for `.txt`)
- [x] Line numbers
- [x] Code folding
- [x] Find and replace
- [x] Go to line
- [x] Undo/redo — native CodeMirror/browser Ctrl+Z/Ctrl+Y
- [x] Automatic indentation
- [x] Bracket matching
- [x] Auto-closing brackets and quotes
- [x] Optional word wrap — checkbox toggle
- [x] Light and dark editor themes — checkbox toggle (default / material-darker)
- [x] Display file encoding — always UTF-8 (every editable file is treated as UTF-8 text; no charset-detection/conversion)
- [x] Warn about unsaved changes — `beforeunload` guard once CodeMirror reports a change since the last save

##### File Management

- [x] Save changes
- [x] Create new files
- [x] Create new folders
- [x] Rename files
- [x] Delete files with confirmation
- [x] Duplicate files
- [x] Download files
- [ ] Upload files to the selected theme (optional) — deferred; create/edit-in-browser covers the primary use case, and this ticket already added a lot of surface area. Uploading would reuse `MediaService`-style validation but is its own scoped addition.

##### Safety

- [x] Confirm before saving PHP files — JS `confirm()` on the save form when the open file ends in `.php`
- [x] Automatically create a backup before saving
- [x] Restore previous version — up to 10 backups kept per file, oldest pruned; restoring itself goes through save(), so a restore is itself backed up and can be undone
- [x] Warn when editing the active theme — banner shown whenever the selected theme is the site's active theme
- [x] Validate file permissions — `is_writable()` checked before allowing edits; non-writable files render read-only with a lock icon in the tree
- [x] Prevent editing outside the selected theme directory
- [x] Prevent directory traversal attacks
- [x] Restrict access to administrators — `manage_themes` capability (same as the other three Appearance sub-pages), which today only `Administrator` holds

##### Developer Features

- [x] Display theme metadata — name/version/author shown above the editor panel
- [x] Open `README.md` if present — shortcut link
- [x] Open `CHANGELOG.md` if present — shortcut link
- [x] Display PHP syntax errors before saving (where possible) — `token_get_all($contents, TOKEN_PARSE)` throws a real `ParseError` on malformed syntax with zero shell/subprocess dependency (this project deliberately avoids `shell_exec`/`exec`, see `UpdateBackupService`'s docblock, which rules out the usual `php -l` approach); catches real tokenizer-level syntax errors, not full semantic ones, honestly satisfying the "where possible" qualifier. A blocked save shows the error and offers a "Save Anyway" override.

#### Deliverables

- Secure built-in theme editor
- Modern code editing experience
- Automatic backups and restore
- Theme file browser

#### Note

While reviewing the neighboring Themes admin page for CSRF conventions, found a pre-existing latent bug in `admin/views/appearance/themes.php`: its "Activate"/"Delete" buttons render once per installed theme but all share one CSRF action name per action type, so only the last-rendered theme's buttons actually work (same class of bug LP-048/LP-049 already fixed for Widgets/Menus). Out of scope for this ticket — flagged separately rather than fixed inline.

------

### LP-052. Curated Release ZIP + Checksum (prep for LP-027)

**Implemented (2026-08-01).** `build-release.sh` (project root, alongside
`CLAUDE.md`/`TODO.md`) reads the version from `LumoraPress/version.php`,
`rsync`s the source tree into a temp build directory with the exclusions
below applied at copy time (so permission-restricted runtime files, e.g.
webserver-owned session files, are never even opened — avoided instead of
copied-then-pruned), zips it wrapped in a `LumoraPress-v{version}/` folder,
and writes the ZIP plus a single-line-hex `.sha256` checksum into
`Releases/`, refusing to overwrite an existing build for that version.
Verified against the real `UpdatePackageValidator::validateAndStage()`
(root prefix auto-detected, all `REQUIRED_ENTRIES` present, no blocking
issues) rather than only inspecting the ZIP by hand. Wired into `CLAUDE.md`'s
Release process (step 9). GitHub asset upload remains manual, since `LP-027`
itself is still unimplemented.

#### Goal

Same problem and approach as Lumora Gallery's `LG-36`: build a clean, curated release
package for Lumora Press instead of relying on GitHub's raw tag-archive snapshot, and
wire building it into the Release process. This ticket only covers *building and
storing* the package — it is prep work, not the GitHub Releases integration itself.
`LP-027` ("Updates from GitHub Releases") is still entirely unimplemented (no
`GitHubUpdateProvider`-equivalent class exists yet in this project), so there is no
provider/download-URL code to modify here; when `LP-027`'s "Download release ZIP
directly from GitHub" task is eventually built, it should consume the asset this
ticket produces.

The repo root is `LumoraPress/` itself (`.git` lives there) — `PHP Test Suite/`,
`custom themes/`, and the outer project docs (`CLAUDE.md`, `TODO.md`, `DECISIONS.md`,
`MEMORY.md`, `PROMPT.md`, `PLUGINS.md`, `SESSION.md`, `PHP-TEST-SUITE.md`,
`TODO-security.md`, `ideas for later.md`, `errors/`, `backup/`) were never pushed to
GitHub and don't need excluding.

#### Compatibility note (confirmed by reading `UpdatePackageValidator.php`)

`UpdatePackageValidator::detectRootPrefix()` (used today by `LP-026`'s manual ZIP
upload) already auto-detects and strips an optional single top-level folder inside an
uploaded ZIP (e.g. `lumorapress-v1.2.0/...`), the same way GitHub's own tag archives
are wrapped. So the curated ZIP should keep that same wrapped-folder structure — it
will already be compatible with the existing manual-upload validator without any
changes there, and by extension with `LP-027` once built.
`UpdatePackageValidator::REQUIRED_ENTRIES` (`version.php`, `app/Core/Kernel.php`,
`admin/index.php`, `include/bootstrap.php`) confirms none of those paths may be
excluded from the package.

#### Requirements

- [x] Release asset naming convention: **`LumoraPress-v{version}.zip`** (matching
  `LG-36`'s `LumoraGallery-v{version}.zip` convention), checksum as
  `LumoraPress-v{version}.zip.sha256`. Internal wrapped folder:
  `LumoraPress-v{version}/...`.
- [x] Decide and document the exclusion list for the curated ZIP, scoped to what
  actually exists inside `LumoraPress/`:
  - `.git`, `.gitignore`, `.gitattributes`, and any other `.git*` files
  - `config/config.php` if present (never ship a live/configured instance's config —
    only `config/config.example.php` should be in the ZIP)
  - Contents of `storage/sessions/`, `storage/logs/`, `storage/cache/` — keep the
    directories (with their tracked `.gitkeep` placeholders) since
    `RequirementsCheck` expects them to pre-exist and be writable, but exclude
    whatever runtime files are in them on the packaging machine
  - Contents of `storage/plugin-installs/` — ephemeral ZIP-upload staging area
    (`PluginInstaller::stage()`, `LP-045`), never meant to persist; exclude entirely
  - Contents of `content/uploads/` — user-uploaded media; keep the directory and its
    tracked `.htaccess`, exclude everything else
  - `vendor/` contents beyond the tracked `.htaccess` — currently empty (no
    `composer.json` exists for the app itself), but exclude defensively in case that
    changes and a packaging run happens on a machine with `composer install` already
    run locally
  - OS/editor cruft: `.DS_Store`, `Thumbs.db`, `.idea/`, `.vscode/`, `*.swp`
  - Confirm with Ariane before finalizing the list.
- [x] Build a release-packaging script (bash or PHP, run manually — not part of the
  live app) that copies `LumoraPress/` into a throwaway build directory, strips the
  excluded paths (recreating the runtime directories above as empty/placeholder-only
  rather than omitting them), and zips the result as `LumoraPress-v{version}.zip`
  wrapped in a single `LumoraPress-v{version}/` folder.
  - Same environment note as `LG-36`: this must run where `bash` reaches
    `/mnt/Winterfell` directly (Claude Code) or be run by Ariane locally — not via the
    Claude Desktop sandbox's `create_file`/`str_replace` tools, which don't reach
    `/mnt/Winterfell` and can silently report success while writing nothing.
- [x] Generate a SHA-256 checksum for the built ZIP as
  `LumoraPress-v{version}.zip.sha256` (single 64-char hex line format — match
  whatever format `LP-027`'s eventual checksum-verification step is built to expect;
  flag this for reconciliation once that code exists).
- [x] Write the built ZIP and checksum to a new
  `/mnt/Winterfell/Coding/Github/Scripts/Lumora Press/Releases/` directory (create
  it — doesn't exist yet), mirroring `LG-36`'s `Releases/` in the Lumora Gallery
  project. Doubles as the local backup archive of every shipped version. Never
  overwrite an already-built ZIP for a version that exists there.
- [x] Wire packaging into `CLAUDE.md`'s "Release" process (mirroring the step added
  there for Lumora Gallery): on version bump, build
  `LumoraPress-v{version}.zip`/`.sha256` into `Releases/` as part of that same pass.
  Uploading to the GitHub release remains a manual step for Ariane.

#### Open questions for Ariane

- Final exclusion list (see above) — confirm before implementing.
- Checksum file format `LP-027` should expect, once that ticket's download/verify
  step is designed — keep this ticket's output format in sync with that decision
  rather than the other way around.

#### Acceptance criteria

- A built `LumoraPress-v{version}.zip`, when extracted, contains no `.git*` files, no
  real `config/config.php`, no leftover session/log/cache/plugin-staging files, and no
  user-uploaded media — but does still contain the empty `storage/`, `content/uploads/`
  placeholder structure the app expects to exist on a fresh install.
- `UpdatePackageValidator::validateAndStage()` accepts the built ZIP as a valid update
  package (all `REQUIRED_ENTRIES` present, root prefix correctly detected) when tested
  via `LP-026`'s manual upload flow.
- The ZIP and checksum land in `Releases/` and are never silently overwritten for an
  already-built version.

------

### LP-053. Admin Sidebar Icons

**Implemented (2026-08-01).**

#### Goal

Add an icon next to every admin sidebar navigation label (top-level entries and their sub-page children), matching the visual style of Lumora Gallery's admin sidebar (Dashboard/Batch Add/Categories/Albums/Images/Configuration/Import/Updates/Tools/Installation/My Account/Users/Groups, each with a leading emoji icon) — reference screenshot provided by Ariane. Lumora Press's admin sidebar (`admin/views/layout-header.php`) currently renders plain text labels only.

#### Requirements

- [x] Add an `'icon'` key to every entry (and every child entry) in `admin/index.php`'s `$menu` array — 28 distinct emoji across every top-level entry and child (📊 Dashboard, 📝 Posts, 📁 Categories, 🏷️ Tags, 🖼️ Media, 📄 Pages, 💬 Comments, 🎨 Appearance [🖌️ Themes, 🧩 Widgets, 🧭 Menus, 💻 Theme Editor], 🔌 Plugins, ⚙️ Settings [🔧 General, 🗂️ Media, ⚡ Cache, 🚧 Maintenance Mode, 🔒 Security], 🧰 Maintenance [🔔 Updates, 📥 Import, 📤 Export, 🪛 Tools, 🖥️ System Information, 📋 Logs], 👥 Users, 🔑 API Tokens).
- [x] Render the icon in `admin/views/layout-header.php` before the label text, for both flat entries and two-level (parent + children) entries, including the parent row that's also a link.
- [x] Icons must not be announced twice to screen readers — each icon is wrapped in `<span class="lp-admin__nav-icon" aria-hidden="true">`, with the existing visible label text right after it in the same link.
- [x] Preserve the existing active/open/submenu-toggle behavior and CSS classes — purely additive: one new `.lp-admin__nav-icon` rule plus changing `.lp-admin__nav-item a` from `display: block` to `display: flex` (for icon/label alignment), no existing class removed or restructured.
- [x] Icons should render consistently in both light and dark admin themes — plain emoji glyphs, not an icon font/SVG sprite, so nothing to theme; verified no existing CSS clips or hides sidebar link content in either mode.

#### Deliverables

- Updated `admin/index.php` `$menu` array with icons.
- Updated `admin/views/layout-header.php` rendering.
- Any small `admin/assets/css/admin.css` spacing/alignment rules the icons need.

------

### LP-054. Posts Sub-Navigation (Categories/Tags as Posts children)

**Implemented (2026-08-01).**

#### Goal

Restructure the admin sidebar so Categories and Tags become sub-pages of Posts, matching classic WordPress's Posts submenu (All Posts / Add New / Categories / Tags), rather than three separate top-level entries. Add explicit "All Posts" and "New Post" sub-page links (today's flat `admin/views/posts.php` handles both list and create/edit via a `?action=` query param on one page with no distinct nav entry for either).

#### Requirements

- [x] Convert `admin/index.php`'s `$menu` entry for `posts` into a two-level entry (`default_child: 'all-posts'`) with children: All Posts, New Post, Categories, Tags.
- [x] Remove the standalone top-level `categories`/`tags` `$menu` entries.
- [x] Split `admin/views/posts.php` into `admin/views/posts/all-posts.php` (the list, plus its Trash/bulk-action/duplicate/quick-draft POST handlers) and `admin/views/posts/new.php` (the create/edit form, its JSON sub-actions for the content editor and inline category creation, and the Revision History panel) — presence of a `?id=` query param on `new.php` means "edit that post", its absence means "new post", replacing the old `?action=new|edit` convention.
- [x] Move `admin/views/categories.php` and `admin/views/tags.php` to `admin/views/posts/categories.php` and `admin/views/posts/tags.php`, updating their internal `admin_url()` calls accordingly. Their own list/create/edit behavior (still driven by their own `?action=` param) is unchanged.
- [x] Add legacy redirects for old bookmarked `/admin/categories` and `/admin/tags` URLs to their new locations, mirroring the existing `/admin/updates`/`/admin/tools` redirects from LP-043.
- [x] Update every other admin view (dashboard's Quick Draft form and recent-posts links) that linked to the old flat `/admin/posts` URL.

#### Deliverables

- Updated `admin/index.php` `$menu` array and legacy redirects.
- `admin/views/posts/all-posts.php` and `admin/views/posts/new.php` (new, replacing `admin/views/posts.php`).
- `admin/views/posts/categories.php` and `admin/views/posts/tags.php` (moved).
- Updated `admin/views/dashboard.php` links.
- Full `composer test`/`composer stan` pass with no regressions.


### LP-051. Third-Party Asset Registry

- [x] Create `/mnt/Winterfell/Coding/Github/Scripts/Lumora Press/LumoraPress/docs/THIRD-PARTY.md` to maintain an inventory of all bundled and externally referenced third-party assets.

#### For Each Asset, Record

- [x] Library/package name
- [x] Purpose
- [x] Current version
- [x] Date added
- [x] Date last updated
- [x] Whether it is:
  - [x] Bundled locally
  - [x] Loaded from a CDN
- [x] Source URL (official project/repository)
- [x] License
- [x] Local installation path (if bundled)
- [x] Homepage/Documentation URL
- [x] Notes (custom modifications, patches, configuration, etc.)

#### Initial Entries

- [x] TinyMCE
- [x] EasyMDE
- [x] PhotoSwipe
- [x] CodeMirror — initially recorded as not applicable (only bundled transitively inside EasyMDE); now loaded directly and independently too, as of LP-050's Theme File Editor (`admin/assets/js/theme-file-editor.js`, CodeMirror 5.65.16) — entry updated in `docs/THIRD-PARTY.md`
- [x] Any future JavaScript libraries — none currently beyond the three above; entry documents the process for adding one
- [x] Any bundled CSS frameworks — none (hand-written CSS throughout)
- [x] Any bundled PHP libraries — none (no runtime Composer dependency)
- [x] Any bundled icon libraries — none beyond CDN-loaded Font Awesome 4 (EasyMDE toolbar only)
- [x] Any bundled fonts — none (system font stack only)

Also recorded Font Awesome 4.7.0, an asset the ticket didn't name explicitly
but which the codebase already CDN-loads (for EasyMDE's toolbar icons) —
in scope under "Initial Entries" as an actual bundled/referenced asset.

#### Deliverables

- `/mnt/Winterfell/Coding/Github/Scripts/Lumora Press/LumoraPress/docs/THIRD-PARTY.md`
- Keep the document updated whenever a third-party asset is added, removed, or updated as part of a release.

---

## 0.5.0 (2026-08-04)

### LP-005. Redesign Media Library into a Media Manager

#### Goal

Replace the traditional "Media Library" concept with a full-featured **Media Manager** designed for long-term website maintenance.

Lumora Press is intended for bloggers, fansite owners, hobbyists, and webmasters who often store much more than just images. The Media Manager should help users organize and manage all site assets, not simply upload files.

The design should remain lightweight and intuitive while offering significantly better organization than classic WordPress.

First pass implemented 2026-08-01: broadened file-type support
(documents/archives + audio + video, on top of images/PDF), a real
unlimited-depth virtual folder tree with full ancestor-chain cycle
prevention (`FolderService`), rich per-file metadata, usage tracking
before delete (`MediaUsageChecker`, warns and requires explicit
confirmation), search/filtering, and bulk move/delete. See
`docs/CHANGELOG.md`'s entry for that date and `FolderService`/
`MediaService`/`MediaUsageChecker` in the source. Rename/replace/
change-metadata as *bulk* actions and Future-Proof Storage (S3/R2)
remain explicitly deferred, not merely unimplemented.

**Extended this session (2026-08-04), part 1:** dimensions (width/height
min/max) and file-size (min/max, KB) search filters — `MediaService::
query()` gained `widthMin`/`widthMax`/`heightMin`/`heightMax`/`sizeMin`/
`sizeMax` filter keys, surfaced as new fields on the Media Manager's
Search & Filter form. Also implemented the six Smart Collections, joining
LP-006's existing "Unused Media"/"Most Downloaded"/etc. views in the
sidebar's "Views" panel: Recently Uploaded, Unused Files (reuses LP-006's
existing view rather than duplicating it), Missing Alt Text, Large Files,
ZIP Downloads, and Featured Images. New `MediaService` methods:
`largestFiles()`, `missingAltText()`, `findMany()`.

**Part 2, same session:** the remaining checklist items except NAS/S3/R2.
Broadened file types (doc/docx/rtf/odt/rar/7z; SVG deliberately excluded
for security), folder search (`FolderService::search()`), and every
remaining Bulk Action (Rename, Download, Change metadata — Replace
implemented as a single-file action instead, not a true bulk one; see
that checklist item's own note for why). Also introduced Future-Proof
Storage's abstraction layer: `MediaStorageInterface` +
`LocalFilesystemStorage` (`app/Services/Storage/`), with `MediaService`
now delegating every filesystem operation through it — no S3/R2 driver
yet (a deliberately separate, larger feature; scope confirmed with Ariane
before starting), no new third-party dependency, no user-facing change.
New `MediaService` methods: `replace()`, `absolutePath()`,
`bulkRenameByReplacing()`, `bulkUpdateMetadata()`. See `docs/CHANGELOG.md`'s
entries for this date.

**Closed out, same session:** NAS needed no distinct driver (checked off
— a NAS mounted as a local path already works via `LocalFilesystemStorage`
as-is), and Amazon S3/Cloudflare R2/other S3-compatible providers were
moved to `ideas for later.md` rather than left as open checklist items —
real remote drivers are a substantially larger feature (third-party SDK
dependency, credential/endpoint config UI) than this ticket's storage
groundwork, but `MediaStorageInterface` exists specifically so building
one later is a drop-in, not a rework. Every remaining checkbox is now
satisfied, so this ticket is complete.

#### Rename

Rename the feature throughout the application:

- [x] **Media Library** → **Media Manager**

This better reflects its purpose as a file management tool rather than a flat list of uploads.

#### Support More Than Images

The Media Manager should safely support a variety of file types, including (where permitted by configuration):

- [x] Images
- [x] PDFs
- [x] ZIP archives
- [x] CSS files
- [x] Text files
- [x] XML
- [x] JSON
- [x] Audio
- [x] Video
- [x] Other downloadable resources — word-processing documents (doc/docx/rtf/odt) and rar/7z archives, on top of the existing set. SVG was deliberately left off the list — see `MediaService::ALLOWED_EXTENSIONS`'s docblock for the stored-XSS reasoning (a browser executes an SVG's embedded `<script>` when it's opened directly, not `<img>`-embedded — every media file here is reachable that way via its plain static URL)

Many fansites host wallpapers, icon packs, fanlisting resources, AO3 skins, downloadable themes, guides, and other assets that deserve proper organization.

#### Virtual Folder System

Implement a database-driven virtual folder system.

Support:

- [x] Create folders
- [x] Rename folders
- [x] Delete empty folders
- [x] Move files between folders
- [x] Bulk move files
- [x] Nested folders — unlimited depth, with full ancestor-chain cycle prevention (`FolderService::descendantIds()`), not the shallow "direct parent only" guard Categories/Pages use
- [x] Folder search — `FolderService::search()`, case-insensitive substring match on folder name, including every ancestor of a match so the sidebar tree still renders it in place rather than as a disconnected leaf
- [x] Folder tree navigation

The folder structure exists for organization only and should not require moving files on disk.

#### Automatic Organization

Avoid placing every upload into one enormous, flat media list.

Provide sensible defaults, for example:

```text
Media

├── Featured Images
├── Blog Images
├── Downloads
├── Icons
├── Wallpapers
├── Logos
├── Documents
├── Audio
├── Video
├── General Uploads
```

#### General Uploads

- [x] For files that do not belong in a permanent folder, automatically organize them chronologically. — an unassigned file's `folder_id` is `NULL`; "General Uploads" is the "no folder" view, ordered by upload date

#### Upload Destination

- [x] During upload, allow users to choose the destination folder.

#### Filesystem Design

- [x] Keep the physical filesystem simple, scalable, and shared-hosting friendly. — unchanged date-bucketed `{year}/{month}/` layout

#### URL Stability

- [x] Moving files between virtual folders must **never** change the public URL. — `folder_id` is metadata-only, never read by `url()`/`delete()`; verified manually (moved a file between folders, confirmed the download URL was identical before and after)

#### File Metadata

Store metadata including:

- [x] Original filename — `file_name`
- [x] Stored filename — `file_path`
- [x] MIME type
- [x] Dimensions
- [x] Filesize
- [x] Upload date
- [x] Folder
- [x] Alt text
- [x] Caption
- [x] Description
- [x] Notes
- [x] Hash/checksum — `file_hash`, SHA-256

#### Usage Tracking

Track where media is used throughout the site and warn before deleting referenced files.

Implemented: `MediaUsageChecker` checks a post's featured image and the
site logo/favicon options (every real reference that exists in the app
today), extensible via the `media_usage` filter for plugins or later
features (e.g. a page featured-image field) without editing the class.
Deleting an in-use file requires an explicit "Delete Anyway" confirmation
after the warning is shown; bulk delete never force-deletes — an in-use
file is silently skipped and reported rather than destroyed.

#### Smart Collections

Support dynamic collections such as:

- [x] Recently Uploaded — "Views" sidebar entry, capped at the most recent 40 uploads (`MediaService::query()` with no filters, its default `uploaded_at DESC` order)
- [x] Unused Files — pre-existing "Unused Media" view (LP-006), reused rather than duplicated
- [x] Missing Alt Text — images only (`MediaService::missingAltText()`); non-image files are excluded since alt text is meaningless for them
- [x] Large Files — `MediaService::largestFiles()`, ordered by `file_size` descending
- [x] ZIP Downloads — `MediaService::query(['type' => 'archive'])`; archive is currently ZIP-only in `TYPE_CATEGORY_MIME_TYPES`
- [x] Featured Images — union of `PostService::featuredImageIdsInUse()`/`PageService::featuredImageIdsInUse()` resolved via the new `MediaService::findMany()`; deliberately excludes the site logo/favicon/OG-image options even though `MediaUsageChecker::usedMediaIds()` also tracks those, since this collection is specifically "images set as a post/page's featured image"

Every collection above is an unpaginated, capped list rendered via the same "Views" sidebar and `$view` query-string pattern LP-006 established, not folded into the paginated filter query.

#### Search & Filtering

Support searching by:

- [x] Filename
- [x] Original filename — same column as Filename in this schema (`file_name`)
- [x] Folder
- [x] File type
- [x] MIME type — the "type" filter groups MIME types into friendly categories (image/document/archive/audio/video)
- [x] Upload date
- [x] Dimensions — width/height min/max, matches images only (`width`/`height` are `NULL` for every other file type)
- [x] File size — min/max in KB, converted to bytes for the `file_size` column

#### Bulk Actions

Support:

- [x] Move
- [x] Delete
- [x] Rename — `MediaService::bulkRenameByReplacing()`, a find/replace substring rename across every selected item's `file_name` (display name only, `file_path`/URL untouched)
- [x] Replace — not meaningful as a *bulk* action: there's no sensible UI for mapping several different replacement files onto several different selected items in one bulk submit. Implemented instead as a single-file action on the media edit page (`MediaService::replace()`) — uploads new content under the same id/`file_path`/URL/folder/metadata, so every place already linking to it keeps working. The replacement file's extension must match the original's exactly, the only way to guarantee the URL never changes.
- [x] Download — "Download selected (ZIP)" bulk action streams a `ZipArchive` of the selected files' real content directly from the POST handler (no redirect; CSRF-protected)
- [x] Change metadata — `MediaService::bulkUpdateMetadata()`; unlike the single-item edit form, a blank field means "leave this field's existing value alone" on every selected item, not "clear it" — a field is only overwritten when the admin actually filled it in

#### Future-Proof Storage

Design the storage layer for future support of:

- [x] Local filesystem — `LocalFilesystemStorage` (`app/Services/Storage/`), implementing the new `MediaStorageInterface` (`put()`/`delete()`/`exists()`/`url()`/`absolutePath()`). `MediaService::upload()`/`replace()`/`delete()`/`url()`/`absolutePath()` all go through this interface now instead of touching the filesystem directly, defaulting to `LocalFilesystemStorage` when no driver is passed in — matches the original behavior exactly, no user-facing change.
- [x] NAS — no distinct driver needed; a NAS mounted as a local path already works via `LocalFilesystemStorage` as-is

Amazon S3, Cloudflare R2, and other S3-compatible providers moved to `ideas for later.md` — real remote drivers need a third-party SDK dependency plus credential/endpoint config UI, a substantially larger feature than this ticket's storage-abstraction groundwork. `MediaStorageInterface` exists specifically so a driver like that is a future drop-in, not a `MediaService` rewrite.

`ThumbnailService`/`MediaImportService` still talk to the local filesystem directly and are **not** yet routed through `MediaStorageInterface` — out of scope for this ticket, noted so a future remote-driver session knows those two still need the same treatment.

#### Project Philosophy

The Media Manager should feel like a lightweight digital asset manager rather than WordPress's traditional Media Library.

#### Success Criteria

- True Media Manager
- Virtual folders
- Stable URLs
- Fast searching
- Scales to thousands of files
- Better organization than WordPress

---

### LP-022. SEO Tools

**Implemented (2026-08-01).** Several items were already done before this
session (Open Graph/Twitter Cards from LP-040, site-wide meta description
and robots.txt from LP-042/LP-046, clean slug-based permalinks from the
original routing design) — confirmed still correct and extended where
noted. New this session: `install/migrations/0022_add_seo_fields_to_posts_and_pages.sql`
(`meta_title`/`meta_description` columns on posts/pages) and
`0023_create_redirects_table.sql`; `Post`/`Page` gained matching optional
`metaTitle`/`metaDescription` properties; `PostService::updateSeo()`/
`PageService::updateSeo()` (a dedicated method rather than two more
params on already-long `create()`/`update()` signatures — the same split
`MediaService::updateMetadata()` uses apart from `upload()`); a
`canonical_url()` helper (`include/helpers.php`) and `<link
rel="canonical">` in `header.php`; a `<title>`/meta-description/
Open-Graph fallback chain that now checks the per-item override first;
JSON-LD structured data (`BlogPosting` on single posts, `WebSite` +
`SearchAction` on the actual homepage only); a new `/sitemap.xml` route
(`SiteController::sitemap()`); `robots.txt` now references it; and a new
`RedirectService` + Settings &rsaquo; Redirects admin page, checked by
`SiteController::notFound()` before it actually answers 404.

Known gaps, disclosed rather than silently dropped:
- **Structured data** is narrow — only `BlogPosting` and `WebSite`
  schemas exist. No `Person`/author schema (no author-display-name
  helper is bridged to themes yet, see the header.php comment), no
  `BreadcrumbList`, no `Organization` logo, nothing for pages/archives.
- **XML sitemap** is a single flat file capped at the sitemaps.org
  50,000-URL limit — a very large site beyond that needs a sitemap
  index splitting into multiple files, not built here. Not cached
  through `CacheManager` (LP-037) either; regenerated on every request.
- **Redirect management** is exact-path matching only — no wildcards/
  regex/pattern matching, no bulk import/export, and critically: renaming
  a post or page's slug does **not** automatically create a redirect
  from the old URL (WordPress does this by default) — an admin has to
  add that redirect by hand on the new Settings &rsaquo; Redirects page.
- **Meta titles** only exist as a per-post/page override; there's no
  equivalent override for a category/tag archive or the homepage itself
  (those already use the category/tag name or site name, which was
  judged sufficient for this pass).

Verified: `php -l` on every changed/new file, a clean `composer stan`,
and `composer test` (770/770, 10 new — `RedirectServiceTest` plus
`updateSeo()` coverage in `PostServiceTest`/`PageServiceTest`).

#### Features

- [x] Meta titles — per-post/page override, falls back to the post/page title
- [x] Meta descriptions — per-post/page override (new); site-wide default was already there (LP-042)
- [x] Canonical URLs — self-referencing `<link rel="canonical">`, preserving only `?paged=`/`?q=`
- [x] Open Graph — pre-existing (LP-040), now respects the meta title/description override
- [x] Twitter Cards — pre-existing (LP-040)
- [x] XML Sitemap — `/sitemap.xml`; see "Known gaps" above for its limits
- [x] robots.txt — pre-existing (LP-046), now references the sitemap
- [x] Structured data — `BlogPosting`/`WebSite` JSON-LD only; see "Known gaps" above
- [x] Clean permalinks — pre-existing, already slug-based (`/post/{slug}`, no query-string URLs)
- [x] Redirect management — `RedirectService` + Settings &rsaquo; Redirects; see "Known gaps" above for what it doesn't do

---

### LP-023. oEmbed / Auto-Embed

**Reassigned (2026-08-04)** after the original `LP-023` ("Theme Options")
was folded into `LP-034` as a total-overlap duplicate — see `DECISIONS.md`'s
"LP-023 merged into LP-034" entry. This is a fresh, unrelated ticket, not a
continuation of the old one.

**Implemented (2026-08-04):** `app/Services/EmbedService.php`, wired into
`Kernel`/`include/bootstrap.php` on the `content_html` and `csp_directives`
filters exactly per the Architecture note below. Five providers ship
(YouTube incl. `youtu.be`/Shorts, Vimeo, SoundCloud, Spotify, CodePen);
Twitter/X is deliberately deferred — unlike the other five, it has no
plain-`<iframe>` embed, only a script-based one (`platform.twitter.com/
widgets.js`), which would need its own CSP `script-src` allowance and a
"load the script once" concern the same shape as Font Awesome's editor
load (see `docs/THIRD-PARTY.md`) — worth its own follow-up rather than
folding into this first pass; pulled out to its own ticket, `LP-070`,
below. Settings live at Settings &rsaquo; Embeds
(site-wide toggle, per-provider toggles, max-width). Also deferred: live
in-editor preview of the embed (optional per the checklist). See
`app/Services/EmbedService.php`'s own docblock and `PHP Test Suite/Unit/
Services/EmbedServiceTest.php` for the detection rules, and
`content/themes/default/style.css`'s "Embeds (LP-023)" section for the
responsive wrapper CSS.

#### Goal

Let an author paste a bare link to a supported provider (YouTube, Vimeo,
etc.) on its own line in a post/page, and have it automatically expand into
an embedded player/card when rendered — the classic WordPress "auto-embed"
behavior (since 2.9, squarely inside this project's 2012–2015 reference
window) and a direct fit for the project's "sit down, write, publish"
philosophy: no manual `<iframe>` copy-pasting required.

**Architecture note:** implement as a fixed, developer-maintained allowlist
of providers with regex-based ID extraction from the URL (e.g. capture a
YouTube video ID from the URL pattern, drop it into a hardcoded iframe
template), **not** the full oEmbed HTTP discovery protocol (fetching a
provider's `oembed` endpoint at request/save time). A fetch-based approach
would mean this app making outbound HTTP requests to attacker-influenceable
hosts at content-save time — an SSRF surface with no precedent anywhere else
in this codebase — for a benefit (supporting arbitrary unlisted providers)
that doesn't matter for a short, curated provider list. Hook the expansion
into `ContentRenderer`'s existing `content_html` filter (`app/Services/
ContentRenderer.php`), which already runs *after* `HtmlSanitizer::clean()`
— the embed HTML is a fixed, hardcoded-per-provider template with an
interpolated ID, never sanitizer-passed user markup, so it does not require
adding `iframe` to `HtmlSanitizer::ALLOWED_TAGS` (which would open that tag
to every other HTML/WYSIWYG content path too).

#### Providers

- [x] YouTube (including `youtu.be` short links)
- [x] Vimeo
- [x] SoundCloud
- [x] Spotify
- [x] CodePen
- [x] Provider allowlist is developer-extensible (a plugin can register a new provider pattern + template without modifying core)

#### Detection & Rendering

- [x] Only a URL alone on its own line/paragraph triggers an embed (matching classic WordPress behavior) — a URL inline within a sentence stays a plain link
- [x] Regex-based ID/slug extraction per provider, no outbound HTTP request
- [x] Responsive embed wrapper (`iframe` scales with container width, fixed aspect ratio) — markup only; visual styling belongs in the theme stylesheet per this project's Public-Facing CSS Rule
- [x] Graceful fallback to a plain link if the URL matches a known provider's domain but not its expected ID pattern
- [x] Unrecognized URLs are left completely untouched

#### Security

- [x] Embed `iframe` src values are built only from the fixed per-provider template + extracted ID — never from raw user-supplied URL text
- [x] `iframe` attributes are restricted to a minimal safe set (`src`, `width`, `height`, `title`, `loading="lazy"`, `allowfullscreen` where the provider needs it) — no `allow`/`sandbox` values wider than each provider strictly requires
- [x] Provider domain allowlist is exact-match (or a tightly scoped subdomain suffix), not a substring/regex loose enough for a lookalike domain to pass
- [x] Auto-embed runs after `HtmlSanitizer::clean()`, never before — confirmed via a test that a hostile provider-looking string embedded inside otherwise-sanitized content can't reintroduce a stripped tag

#### Editor Integration

- [x] Works for Markdown-format content

- [x] Works for HTML-format content

- [x] Works for Plain-format content

- [x] Comment content (LP-012) is explicitly excluded from auto-embed — comments are guest-submitted and a much higher-trust-abuse surface than author-authored posts/pages; a plain link stays a plain link there

#### Configuration

- [x] Site-wide on/off toggle
- [x] Per-provider on/off toggles
- [x] Default embed width/max-width

#### Performance

- [x] Regex matching only runs on content containing `http(s)://`, not on every render unconditionally
- [x] No outbound network requests at render time or save time (see Architecture note above)

#### Developer API

- [x] Register a new provider (URL pattern + ID-extraction regex + embed HTML template)
- [x] `apply_filters('embed_html', ...)` — let a plugin adjust generated embed markup
- [x] `apply_filters('embed_providers', ...)` — let a plugin add/remove providers

#### Testing

- [x] Unit tests per provider (valid URL → correct embed; short-link variants; invalid/malformed ID → falls back to plain link)
- [x] Unit test confirming a URL embedded mid-sentence is left as a plain link
- [x] Unit test confirming auto-embed never introduces a tag absent from `HtmlSanitizer::ALLOWED_TAGS` inspection (i.e. the feature's own output is deliberately exempt, not a sanitizer bypass for anything else)
- [x] Unit test confirming comment content is never auto-embedded
- [x] PHP 8.2 / 8.3 / 8.4 compatibility

#### Documentation

- [x] Update README.md
- [x] Document the provider-registration Developer API
- [x] List supported providers and their URL formats for end users

#### Success Criteria

- [x] An author pasting a bare supported-provider URL on its own line sees a working embed on the public page, with no HTML editing required.
- [x] No outbound HTTP requests are made by this feature at any point.
- [x] Unsupported/malformed URLs never produce broken or unsafe markup.
- [x] Comments remain unaffected.

---

### LP-032. User Management

**Implemented (2026-07-23), first pass.** The `users` admin menu entry
already existed (gated on `manage_users`, Administrator-only) and
previously fell through to `placeholder.php`; this ticket builds the
real screen behind it, mirroring Categories/Tags' list/create/edit/delete
pattern. Bulk actions, avatars, per-user activity logs, and a
self-service "My Profile" screen (distinct from this admin CRUD) are
intentionally left for a future pass — see the second pass below for
those.

**Second pass (2026-08-04).** Closed out every remaining item: Trash/
Restore (new `trashed_at` column, migration `0029`, mirrors the Posts
admin's soft-delete pattern exactly, including the "delete means trash
first" guardrail), bulk actions (Trash/Restore/Delete Permanently/Change
role, gated by the same self/last-admin/authored-content guards as the
single-row actions), Search & Filter (username/email/display name term
plus a role dropdown, same collapsible panel pattern as the Posts admin),
and Avatars (Gravatar by default via a SHA256 email hash — MD5 avoided
per this project's own "never MD5" rule even though it's not a password
context — with an optional per-user uploaded override reusing
`MediaService::upload()`, stored as a new `avatar_media_id` column). A
trashed user can no longer authenticate (`UserService::verifyCredentials()`
excludes `trashed_at IS NOT NULL`) and an already-open session for an
account trashed mid-session is invalidated on its next request
(`Auth::user()`); trashing (like deleting) now also revokes "Remember Me"
tokens. Per-user activity logs and the self-service "My Profile" screen's
own avatar controls remain out of scope for this ticket (My Profile is a
separate screen — LP-066/LP-067 — and activity logs were never an actual
checklist item on this ticket, only mentioned in the first-pass note
above).
PHP 8.2/8.3 compatibility was verified via `php8.2 -l`/`php8.3 -l` syntax
checks on every changed file and a full `phpstan analyse` run (this
project's `composer.json` pins PHPStan's analysis platform to PHP 8.2),
all clean. After Ariane installed the missing extensions for both CLI
binaries mid-session (`pdo_sqlite` for both; `dom`/`mbstring`/`xmlwriter`
for PHP 8.2), the full PHPUnit unit suite ran cleanly on every supported
version: PHP 8.2 — 988 tests, 1896 assertions, all green; PHP 8.3 — 988
tests, only 4 failures, all in the pre-existing, unrelated
`RequirementsCheckTest` (directory-writability assertions that depend on
the filesystem/user the suite runs as, not on anything touched by this
ticket); PHP 8.4 — 988 tests, all green. `UserServiceTest` itself passed
27/27 on every version.
New/changed code was not manually verified end-to-end in a live browser
this session (no installed dev instance was available) — see the
"Manually verified end-to-end" item below, which still reflects only the
original first-pass scope.

#### Goal

Implement an admin User Management screen so Administrators can create, edit, and remove user accounts and assign roles without touching the database directly.

#### Features

##### User Management

- [x] Create users
- [x] Edit existing users (username, email, display name, role)
- [x] Change a user's password from the admin edit screen (optional — leave blank to keep the current password)
- [x] Delete users
- [x] Bulk management
- [x] Trash with restore functionality
- [x] Permanent delete

##### Safety Guards

- [x] An administrator cannot delete their own account
- [x] The last remaining Administrator cannot be deleted or demoted to another role
- [x] A user who has authored posts or pages cannot be deleted — there is no admin reassignment UI yet, so deleting them would orphan that content's `author_id`
- [x] Deleting a user also revokes their "Remember Me" tokens (`RememberMeService::forgetUserTokens()`)

##### Roles

- [x] Assign one of Administrator/Editor/Author/Contributor/Subscriber (Guest is not a stored account role and is excluded from the picker)

- [x] Role changes take effect immediately (no re-login required beyond the current session's already-loaded user)

##### Admin Interface

- [x] Build Users listing page
- [x] Build Create User page
- [x] Build Edit User page
- [x] Display each user's role
- [x] Add confirmation dialog on delete
- [x] Search/filter by username, email, or role
- [x] Avatars (Gravatar or uploaded)

##### Security

- [x] CSRF protection on save/delete/password-change actions
- [x] Permission checks — page-level `manage_users` gate (Administrator only)
- [x] Input validation — required fields, email format, 10-character minimum password, username/email uniqueness (excluding self on edit)
- [x] Output escaping
- [x] Passwords hashed via `password_hash()`/`password_verify()`, never logged or displayed

##### Testing

- [x] Unit tests — `Unit/Services/UserServiceTest.php` (`update()`, `changePassword()`, `delete()`, `listAll()`, `countByRole()`, `usernameOrEmailExistsForOther()`, plus the second pass's `trash()`/`restore()`/`countTrashed()`/`paginateForAdmin()`/`updateAvatar()`/`gravatarUrl()`/trashed-user login rejection); `Unit/Services/PostServiceTest.php`/`PageServiceTest.php` (`countByAuthor()`)
- [x] Integration tests — `Integration/UserServiceIntegrationTest.php` (real MySQL only, confirms `usernameOrEmailExistsForOther()`'s three distinct placeholders, plus the second pass's `paginateForAdmin()` term-filter and trash/restore round-trip tests)
- [x] Manually verified end-to-end in a real browser against a live MySQL instance: create, edit, role change, password change (re-login with the new password), and all three delete guards (self, last-admin, and — implicitly, via the last-admin case — role protection) — this covers only the first-pass scope; the second pass's Trash/Restore/bulk actions/search/avatars were not manually browser-verified
- [x] PHP 8.2 compatibility — `php8.2 -l` clean, a full `phpstan analyse` run (platform pinned to 8.2 in `composer.json`), and a full PHPUnit unit-suite run (988 tests, 1896 assertions, all green)
- [x] PHP 8.3 compatibility — `php8.3 -l` clean, plus a full PHPUnit unit-suite run: 988 tests, `UserServiceTest` 27/27, only 4 pre-existing/unrelated `RequirementsCheckTest` failures
- [x] PHP 8.4 compatibility — full 988-test unit suite green

##### Documentation

- [x] Update README.md
- [x] Update CHANGELOG.md

##### Success Criteria

- [x] Administrators can create, edit, and remove user accounts without direct database access.
- [x] It is not possible to lock yourself out of the admin by deleting the last Administrator or your own account.
- [x] Deleting a user who has authored content is blocked rather than silently orphaning that content.
- [x] The implementation remains lightweight and consistent with the existing Categories/Tags admin patterns.

---

### LP-044. Theme Browser

#### Goal

Modernize the **Appearance → Themes** page by displaying visual previews of installed themes and detailed information before activation.

#### Features

##### Theme Gallery

- [x] Display installed themes as thumbnail cards
- [x] Automatically detect preview images named:
  - [x] `preview.*`
  - [x] `thumbnail.*`
  - [x] `screenshot.*`
- [x] Support JPG, PNG, WebP, and AVIF preview images
- [x] Display a default placeholder when no preview image exists
- [x] Highlight the currently active theme
- [x] Search installed themes
- [x] Sort themes alphabetically

##### Theme Details

- [x] Open a details panel/modal when a theme is clicked

- [x] Display:
  - [x] Theme name
  - [x] Version
  - [x] Author
  - [x] Description
  - [x] Homepage URL
  - [x] License
  - [x] Minimum Lumora Press version
  - [x] Required PHP version
  - [x] Tags/categories

- [x] Show a larger preview image

- [x] Display additional screenshots if available

- [x] Display whether the theme is currently active

- [x] Display theme preview thumbnails at a maximum width of **250px**, preserving the original aspect ratio (do not crop or stretch images).

##### Theme Actions

- [x] Activate theme
- [x] Preview theme
- [x] Delete inactive themes
- [x] Open theme folder
- [x] View README if included
- [x] View CHANGELOG if included

##### Developer Support

- [x] Read metadata from the standard theme header
- [x] Allow themes to provide multiple screenshots
- [x] Developer hooks for extending the details panel

#### Deliverables

- Modern visual theme browser
- Theme details modal/page
- Automatic preview image detection (`preview.*`, `thumbnail.*`, `screenshot.*`)
- Backward compatibility with existing themes

**Status (2026-07-25): Implemented**, except one item left unchecked above —
"List bundled theme options (if any)" (no existing concept of theme-provided
config sub-options to surface; would need its own spec). "Open theme folder"
is implemented as a read-only path display (`content/themes/{slug}`) in the
details panel, since this is a self-hosted app with no built-in file
manager — an admin uses that path via FTP/SSH, not a clickable "open"
action.
Extended `ThemeInfo`/`ThemeRegistry` (Theme URI, Author URI, License,
License URI, Requires at least, Requires PHP, Tags, multi-screenshot
gallery, README/CHANGELOG detection), added `ThemeInstaller::delete()`,
and rebuilt the Appearance → Themes admin view with a search box, a
native `<dialog>` details panel (per-theme `<template>`, one shared
dialog, admin/assets/js/theme-browser.js), and a `lp_theme_details_panel`
action hook. Verified end-to-end in a throwaway install (multi-screenshot
theme, tags, README/CHANGELOG rendering, search filtering, delete).

Card/details-panel thumbnails are capped at `max-width: 250px` with
`height: auto` (no `object-fit`/forced aspect-ratio), so a preview image's
original proportions are always preserved rather than being cropped or
stretched to fill a fixed box (`admin/assets/css/admin.css`'s
`.lp-theme-card__screenshot`).

"Preview theme" (front-end live preview, LP-044's previously-optional
action) is implemented: the Appearance page's "Preview" link (card and
details panel, inactive themes only) opens the real front end with
`?lp_preview_theme={slug}` in a new tab. The public front controller
(`index.php`) checks the requester is logged in with `manage_themes`
before honouring it — a bare query param is never enough — then swaps
`ThemeRenderer`'s active theme for that request only (`config`'s
`active_theme` option is never touched, so no other visitor is affected).
A new static bridge, `LumoraPress\Core\Theme\ThemePreview` (mirrors
`SiteBranding`/`FeaturedImages`), marks the request as a preview; `index.php`
buffers the rendered output and calls `ThemePreview::injectBanner()` to
insert a "Previewing theme: {name} · Exit Preview" bar right after the
page's `<body>` tag, styled by its own small stylesheet
(`admin/assets/css/theme-preview-bar.css`) rather than the previewed
theme's own — deliberately unmodified, theme-agnostic, and works with
every theme including custom ones with no knowledge of preview mode at
all. "Exit Preview" strips only the `lp_preview_theme` param, preserving
any other query string.

---

### LP-046. Reading Settings

**Implemented (2026-08-01).** New Settings &rsaquo; Reading admin page
(`admin/views/settings/reading.php`, registered in `admin/index.php`'s
`$menu['settings']['children']` right after General). Three independent
forms, same CSRF/option pattern as `settings/general.php`:

- **Homepage** — `homepage_display` (`posts`|`page`), `homepage_page_id`,
  `homepage_posts_page_id`. `SiteController::home()`
  (`app/Controllers/SiteController.php`) now branches on these: a
  configured, publicly-visible page renders via `page.php` at `/`
  instead of the latest-posts listing. The paired "Posts page" is a
  second page whose *own* `/page/{slug}` URL is intercepted
  (`isConfiguredPostsPage()`) to render the posts listing instead of that
  page's stored content — the same static-homepage-plus-posts-page split
  WordPress's Reading settings offer. `reading.php` rejects picking the
  same page for both.
- **Posts** — new `posts_per_page` option (default 10, clamped 1-200),
  read via a new `SiteController::postsPerPage()` helper and threaded
  into every listing query that used to hardcode `PostService`'s
  `DEFAULT_PER_PAGE` (10): `home()`, `category()`, `tag()`, `archive()`,
  `archiveByMonth()`. `PostService`'s pagination methods already accepted
  an optional `$perPage` param (unused by any caller before this), so no
  service-layer changes were needed.
- **Search Engine Visibility** — new `discourage_search_engines` option.
  `SiteController::robotsTxt()` now answers a virtual `/robots.txt`
  (registered in `include/bootstrap.php`; no physical file existed, and
  `.htaccess` already serves real files directly first) with a blanket
  `Disallow: /` when discouraged, otherwise just disallowing the admin
  area. `SiteBranding` gained a `discourageSearchEngines` flag (set from
  `include/bootstrap.php`, same request-scoped bridge pattern as
  `metaDescription`/`customCss`) backing a new `search_engines_discouraged()`
  theme helper (`include/helpers.php`), which the default theme's
  `header.php` uses to print `<meta name="robots" content="noindex,nofollow">`
  site-wide when enabled.

#### Features

##### Homepage

- [x] Latest posts as homepage
- [x] Static homepage
- [x] Separate posts page

##### Posts

- [x] Posts per page
- [x] Posts per RSS/Atom feed — already implemented under Settings &rsaquo; General's Feeds section (`feed_item_limit`, LP-013/LP-042), not duplicated here
- [x] Feed content:
  - [x] Full content — already implemented (`feed_full_content`, LP-013/LP-042)
  - [x] Excerpt only — same option, unchecked state

##### Search Engines

- [x] Discourage search engines from indexing the site
- [x] Automatically update `robots.txt` where applicable — served virtually, not a physical file that needs updating
- [x] Output appropriate `<meta name="robots">` directives

##### Deliverables

- [x] Reading Settings page
- [x] Homepage selection
- [x] Feed configuration — pre-existing, see note above
- [x] Search engine visibility controls
- [x] `README.md` updated ("Current Status")
- [x] PHP Test Suite coverage — added `Integration/SiteControllerReadingSettingsIntegrationTest.php`. `SiteController` still has no full HTTP-level test harness (its public methods render real theme templates and call `header()`/`exit`, and building that harness from scratch remains out of proportion to this ticket), but every *other* constructor dependency is cheap to construct for real (Database/tablePrefix-backed services, a `NullCacheDriver`, a `ThemeRenderer` pointed at a directory never actually rendered from), so the new test builds a real `SiteController` against a real, migrated, database-backed `PressConfig` and reaches the two private methods LP-046 actually added (`postsPerPage()`, `isConfiguredPostsPage()`) via reflection — the smallest surface that exercises the real new behavior. 6 new tests, all passing against a throwaway MariaDB (Docker), alongside a clean `composer test` (981 unit tests) and `composer stan` run.

---

### LP-055. Admin Content Page Visual Polish

**Implemented (2026-08-01).**

##### Goal

Give the admin panel's content pages (Dashboard, Updates, Settings &rsaquo; General, and every other screen built from the shared `.lp-admin__panel`/`.lp-table`/`.lp-button`/`.lp-field`/`.lp-alert` classes) a more polished, purposeful look. The existing admin UI was functional but flat: borderless white boxes with no elevation, plain unstyled headings, invisible-chrome buttons, and a breadcrumb trail that read as loose gray text rather than a wayfinding element — flagged by Ariane against the Updates and Settings &rsaquo; General screens specifically, but addressed at the shared-class level so it applies everywhere those classes are used.

##### Requirements

- [x] Panels/widgets (`.lp-admin__panel`, `.lp-admin__widget`) get a soft box-shadow, a larger border radius (new `--lp-admin-panel-radius` token), and consistent `margin-bottom` spacing between stacked sections — previously relied on incidental spacing with no explicit rule.
- [x] Panel/widget headers (`> h2:first-child`) get a bottom divider and a small accent-colored bullet, instead of a bare heading.
- [x] Tables (`.lp-table`) get a tinted header row, a rounded bordered container, and a hover highlight on body rows.
- [x] Buttons (`.lp-button`) get a visible default "chrome" (border + background — previously transparent/borderless for the base class), hover/active feedback, and primary buttons get a colored shadow with a hover lift.
- [x] Form fields (`.lp-field input`/`textarea`/`select`) get a focus ring in the accent color (new `--lp-admin-focus-ring` token) plus a transition.
- [x] Alerts (`.lp-alert--error`/`--success`/`--warning`) get a left accent stripe and consistent bottom margin.
- [x] Theme/plugin cards (`.lp-theme-card`, `.lp-plugin-card`) get the same shadow treatment plus a hover lift, matching the new panel style.
- [x] Status bar / meta-list typography (Updates page status groups, dashboard meta lists) gets clearer label/value hierarchy — uppercase muted labels, bolder values, left-border grouping.
- [x] Breadcrumb trail (`.lp-admin__breadcrumbs`) restyled as a bordered/shadowed pill chip with tighter chevron-spaced typography, semibold links, and a bold current-page label — previously plain unstyled text with wide, meaningless gaps.
- [x] Purely CSS — no PHP template changes, since every admin page already used the shared class names; every new/changed rule lives entirely in `admin/assets/css/admin.css`.
- [x] Dark mode variants added for the new shadow/focus-ring tokens alongside the existing light-mode ones.

##### Deliverables

- Updated `admin/assets/css/admin.css` (panels, tables, buttons, form fields, alerts, theme/plugin cards, status bar, breadcrumbs).

---

### LP-056. Admin Sidebar Top-Level Nav Refinement

**Implemented (2026-08-01).**

##### Goal

Refine the admin sidebar nav added in LP-053: top-level items (Dashboard, Posts, Media, Pages, Comments, Appearance, Plugins, Settings, Maintenance, Users, API Tokens) are visually noisy with an icon next to every label, while the "New Post" sub-nav icon (`➕`) renders invisibly against the sidebar background. Sub-level items (All Posts, New Post, Categories, Tags, Themes, Widgets, etc.) should keep their icons — only the top-level row icons go.

##### Requirements

- [x] Remove the icon from every top-level nav item in `admin/views/layout-header.php` — both childless items (e.g. Dashboard, Media) and parent items with a submenu (e.g. Posts, Settings) — while leaving the icon markup on sub-nav (`children`) items untouched.
- [x] Uppercase top-level nav labels via CSS (`text-transform`, scoped to top-level links only — not submenu links) in `admin/assets/css/admin.css`, rather than mutating the label strings in `admin/index.php`.
- [x] Fix the invisible "New Post" icon — two attempts:
      1. First pass appended a `\u{FE0F}` variation selector to `➕` (U+2795
         HEAVY PLUS SIGN), on the theory that it needed forcing into
         emoji presentation, same as the other multi-codepoint icons
         (`🏷️`, `🖼️`, `⚙️`, etc.). Confirmed still invisible afterward
         (screenshot from Ariane, 2026-08-01) — those other icons are
         *pre-composed* sequences that already include their own selector
         as part of the copy-pasted glyph; adding one to a bare U+2795
         didn't fix a browser/font combination that appears to have no
         glyph for that codepoint at all, VS16 or not.
      2. Replaced the icon entirely with `🆕` (U+1F195 SQUARED NEW,
         single codepoint, default emoji presentation) in `admin/index.php`
         — sidesteps the variation-selector question altogether rather
         than continuing to chase it, and reads well semantically for
         "New Post" too.

##### Deliverables

- Updated `admin/views/layout-header.php` (icon markup removed from top-level rows only).
- Updated `admin/assets/css/admin.css` (top-level label uppercase rule).
- Updated `admin/index.php` (`➕` → `➕\u{FE0F}` → `🆕` for the "New Post" icon; see the two-attempt note above).

---

### LP-057. Updates Page: GitHub/Manual Tabs

**Implemented (2026-08-01).**

##### Goal

The Maintenance &rsaquo; Updates page (`admin/views/maintenance/updates.php`) has grown busy — GitHub release info, a "Check for Updates" button, database status, backups, system status, update settings, and a manual ZIP-upload form are all stacked in one long scroll, and the manual upload form in particular gets buried near the bottom where it's easy to miss. Split the page into two tabs: **GitHub** (the primary/default tab, containing the GitHub release check flow) and **Manual Update** (containing only the ZIP upload form), so `admin/maintenance/updates` opens on GitHub by default with Manual Update one click away.

##### Requirements

- [x] Add an accessible tab component (ARIA `tablist`/`tab`/`tabpanel` roles, arrow-key navigation, works without JS via the `hidden` attribute defaulting to the GitHub panel) to `admin/views/maintenance/updates.php`.
- [x] GitHub tab (default/active) contains the existing "Latest release" section and "Check for Updates" section.
- [x] Manual Update tab contains only the existing "Manual Update (ZIP Upload)" section.
- [x] Status bar, Update Summary (post-check/upload result), Database Updates, Backups, System status, Update settings, Update History, and About Updates sections stay outside the tabs (shared, always visible) since they apply regardless of which update method was used.
- [x] Server-side default tab selection: if the current request is the result of a manual-upload error or a manual `checkResult` (`source === 'manual'`), default to the Manual Update tab instead of GitHub, so a validation error on upload doesn't get hidden behind the GitHub tab.
- [x] New `admin/assets/js/admin-tabs.js` progressively enhances any `.lp-tabs` component generically (not hardcoded to this page), following the existing `nav-toggle.js` pattern; enqueued in `admin/views/layout-footer.php` alongside the other admin scripts.
- [x] New `.lp-tabs`/`.lp-tabs__list`/`.lp-tabs__tab`/`.lp-tabs__panel` styles added to `admin/assets/css/admin.css`, built from existing CSS variables so dark mode is automatic.

##### Deliverables

- Updated `admin/views/maintenance/updates.php` (tab markup, reordered sections, server-side default-tab logic).
- New `admin/assets/js/admin-tabs.js`.
- Updated `admin/views/layout-footer.php` (script enqueue).
- Updated `admin/assets/css/admin.css` (tab styles).

---

### LP-058. Password Reset

**Implemented (2026-08-01).**

##### Goal

Lumora Press has no self-service "forgot password" flow — flagged during
LP-025's user-enumeration audit as "not applicable" only because the
feature didn't exist yet at that point. Add a minimal, dependency-free
reset flow: request a link by email, click it, set a new password.

No mail library exists anywhere in this codebase (no PHPMailer, no
Symfony Mailer, no root `composer.json` for the main app at all) —
matching the project's zero-dependency, shared-hosting-friendly
philosophy, this uses PHP's native `mail()` behind a small `Mailer`
interface rather than adding a first Composer dependency to the app itself.

##### Requirements

- [x] `app/Core/Security/PasswordResetService.php` — selector/validator
      single-use tokens modeled on `RememberMeService` (`issueToken()`,
      `findValidToken()` non-mutating peek, `consume()` validates+deletes,
      `invalidateForUser()` cleanup). One active token per user (a new
      request supersedes an old unused link); a 60-second per-account
      cooldown guards against mail-bombing an inbox via repeated
      submissions.
- [x] `app/Core/Security/PasswordResetThrottle.php` +
      `install/migrations/0025_create_password_reset_requests_table.sql` —
      IP-based rate limiting on the forgot-password form itself (same
      shape as `LoginThrottle`, 5 requests / 15 min window / 15 min
      lockout), added 2026-08-01 to blunt the timing side-channel below at
      scale: every verified submission counts toward the quota regardless
      of whether the email exists, so an attacker can't dodge it by only
      probing addresses expected not to exist.
- [x] `install/migrations/0024_create_password_resets_table.sql` — same
      shape as `0003_create_remember_tokens_table.sql`.
- [x] `app/Core/Mail/Mailer.php` (interface) + `app/Core/Mail/NativeMailer.php`
      (PHP `mail()`-backed implementation, From address from the existing
      `admin_email` option, falling back to `noreply@{host}`).
- [x] `UserService::findByEmail()` — mirrors `findByUsername()`, case-insensitive.
- [x] Two new unauthenticated admin pages (`admin/index.php`, same shape as
      the existing `login`/`logout` blocks): `forgot-password` (request
      form) and `reset-password` (set new password).
- [x] `forgot-password` never reveals whether an email is registered —
      identical response whether the account exists or not, and whether a
      honeypot/timing check silently failed or not. Reuses the LP-025
      `FormTiming` class and a honeypot field, same as the public comment
      form.
- [x] `reset-password` re-validates the token server-side on POST (never
      trusts a hidden-field token alone), requires the new password twice
      (min 8 characters), and on success also invalidates every other
      outstanding reset token and revokes "Remember Me" sessions for that
      user (`RememberMeService::forgetUserTokens()`) — a password change
      should end other persistent sessions too.
- [x] `admin/views/login.php` gains a "Forgot password?" link and a
      `?reset=success` alert.
- [x] Documented, not fixed: sending an email only when the account exists
      is a timing side-channel (DB insert + `mail()` vs. nothing). No
      async/queue infrastructure exists to eliminate it cheaply this pass —
      accepted trade-off, called out in a code comment (same treatment
      `LoginThrottle`'s own documented trade-offs get).
- [x] Manual end-to-end browser verification (clicking a real emailed
      link) — not done this session; no throwaway install with a working
      `mail()` transport was set up. Everything else is unit/PHPStan
      verified; the integration test also wasn't run against a live
      MySQL/MariaDB server this session (no container was running).

##### Deliverables

- New: `app/Core/Security/PasswordResetService.php`, `app/Core/Mail/Mailer.php`,
  `app/Core/Mail/NativeMailer.php`, `install/migrations/0024_create_password_resets_table.sql`,
  `admin/views/forgot-password.php`, `admin/views/reset-password.php`.
- New tests: `PHP Test Suite/Unit/Core/Security/PasswordResetServiceTest.php`,
  `PHP Test Suite/Integration/PasswordResetIntegrationTest.php`, plus
  `SqliteDatabaseFactory` fixtures for the new table.
- Edited: `app/Services/UserService.php`, `admin/index.php`,
  `admin/views/login.php`, `app/Core/Kernel.php`, `include/bootstrap.php`.
- README.md updated.

---

### LP-059. Render Markdown in GitHub Release Notes on the Updates Page

**Implemented (2026-08-04).**

##### Goal

The Maintenance &rsaquo; Updates page's "Latest release" panel
(`admin/views/maintenance/updates.php`) shows a GitHub release's body
text (`$updateStatus['release_notes']`, sourced from the GitHub Releases
API's `body` field) inside a `<pre><?= esc_html(...) ?></pre>` block —
literal Markdown source, so an admin sees raw `##`/`*`/`**` syntax instead
of a formatted release notes. Render it as actual Markdown instead,
reusing the same Markdown pipeline already built for post/page content
(LP-015/LP-016) rather than adding a second one.

##### Plan

- [x] Use `$kernel->content->render($updateStatus['release_notes'],
      ContentFormat::Markdown)` (`app/Services/ContentRenderer.php`) in
      place of the `<pre>esc_html()</pre>` block — this already chains
      `MarkdownParser::toHtml()` into `HtmlSanitizer::clean()`, so GitHub's
      release body (external, technically untrusted input, even though it
      comes from this project's own repo) gets the same XSS boundary
      every other Markdown source in this app goes through, not a
      separate one-off render path.
- [x] Add `use LumoraPress\Models\ContentFormat;` to
      `admin/views/maintenance/updates.php`.
- [x] Replace the `<pre>` wrapper with a `<div class="lp-update__release-notes-body">`
      around the rendered HTML.
- [x] Add typography rules for `.lp-update__release-notes-body` to
      `admin/assets/css/admin.css` (headings, paragraphs, lists, inline
      code/`<pre>` blocks, links, blockquotes — the subset of
      `HtmlSanitizer::ALLOWED_TAGS` a release body realistically uses),
      scoped inside the existing `.lp-update__release-notes` scroll
      container so long release notes still scroll rather than growing
      the panel unbounded.
- [x] Verify `php -l` and a clean `composer stan`/`composer test` run —
      `php -l` clean on the changed view, `composer stan` clean (0
      errors), `composer test` 813/813 passing, no regressions. No new
      automated test added — no service-layer behavior changed,
      `ContentRenderer::render()` is already covered by its own unit
      tests, and this view has no existing test harness to extend (same
      "thin routing/rendering glue class" gap LP-046 already documented
      for `SiteController`).

##### Deliverables

- Updated `admin/views/maintenance/updates.php`.
- Updated `admin/assets/css/admin.css`.

---

### LP-060. Media Admin Restructure into Media Manager Sub-Pages

**Implemented (2026-08-04).**

##### Goal

`admin/views/media.php` is a single 922-line file that crams four
distinct workflows into one URL (`/admin/media`), switched on an
`?action=` query param plus in-page panels: browsing/searching the
library and editing an individual item (`action=list`/`edit`), the
Upload form (a panel embedded in the list view), the "Import from
Server" flow (`action=import`, LP-041), and bulk thumbnail
regeneration/orphan cleanup (another panel embedded in the list view).
Restructure Media into a proper two-level nav entry, matching the
existing Posts/Appearance/Settings/Maintenance pattern
(`$menu['media']['children']` in `admin/index.php`):

```
Media Manager
 - Media          (admin/media/media — the library grid + per-item edit)
 - Upload         (admin/media/upload — dedicated add-new-file page)
 - Import from Server   (admin/media/import — existing LP-041 flow)
 - Thumbnails     (admin/media/thumbnails — bulk regenerate/cleanup)
```

The top-level label changes from "Media" to "Media Manager" (matching
the `<h1>` the page already renders); the top-level nav icon and
`upload_files` capability requirement stay as they are today.

##### Plan

- [x] `admin/index.php`: change `$menu['media']` from a flat entry into a
      parent with `default_child: 'media'` and four `children` (`media`,
      `upload`, `import`, `thumbnails`), following the exact shape
      `$menu['posts']`/`$menu['appearance']` already use. No
      `$legacyRedirects` entry needed for the bare `/admin?page=media`
      bookmark — the existing children-redirect logic in `admin/index.php`
      already sends a subpage-less request to `default_child`
      automatically (same as Posts/Appearance never needed one for their
      own base slug).
- [x] Split `admin/views/media.php` into four files under a new
      `admin/views/media/` directory, each keeping only the POST-form
      handlers and markup it actually needs (duplicating the small
      `$buildFolderOptions` closure and kernel service lookups each file
      needs, rather than introducing a new shared-partial mechanism this
      codebase doesn't otherwise use — matches how `posts/categories.php`/
      `posts/tags.php` each keep their own tree-building closure today):
      - `media/media.php` — `action=list`/`edit` only: browsing/search/
        folders/Views sidebar, the item grid + bulk move/delete, and the
        per-item edit/metadata/delete panel (including its existing
        single-item "Regenerate thumbnails" button, which stays
        contextual to the item rather than moving to the new Thumbnails
        page). Keeps the `create_folder`/`rename_folder`/`delete_folder`/
        `update_metadata`/`delete_file`/`bulk_action`/
        `regenerate_thumbnails` form handlers.
      - `media/upload.php` — the Upload form only (`upload` handler),
        redirecting to `media/media?action=edit&id={id}&saved=1` on
        success, same as today.
      - `media/import.php` — the entire existing "Import from Server"
        flow moved verbatim (`scan_import`/`start_import`/
        `continue_import` handlers + scan/progress views), just with
        every `admin_url('media')` reference repointed at
        `admin_url('media/import')`.
      - `media/thumbnails.php` — the bulk regenerate/orphan-cleanup panel
        and its `bulk_regenerate_thumbnails`/`cleanup_orphaned_thumbnails`
        handlers, plus the `thumb_progress`/`orphans_removed` alert
        blocks (both currently rendered at the top of the shared file
        regardless of `$action`).
      - Remove the old `admin/views/media.php` once its content is fully
        accounted for across the four new files.
- [x] Update every `admin_url('media')` reference across the split files
      to the correct new sub-route (`media/media` for
      browse/edit/folder-management links, `media/import` inside the
      import flow, `media/thumbnails` inside the thumbnails panel) —
      including the "Import from Server…" and thumbnails links that used
      to live inline in the list view's Upload/Thumbnails panels, which
      no longer need to exist now that those are real nav items.
- [x] Update the two external references to the old single-page URL:
      `admin/views/dashboard.php`'s "Popular Downloads" widget item link
      (`admin_url('media') . '?action=edit&id=...'` →
      `admin_url('media/media') . '?action=edit&id=...'`) and
      `admin/views/settings/media.php`'s "Media Manager" link in the
      download-tracking hint text.
- [x] Verify `php -l` on every new/changed file, a clean
      `composer stan`/`composer test` run — `php -l` clean on all 6
      changed/new files, `composer stan` clean (0 errors, though it only
      scans `app/` — admin views have no static-analysis coverage in
      this project, same as every other admin view), `composer test`
      813/813 passing, no regressions. No new automated test added — no
      service-layer behavior changed, this view has no existing test
      harness to extend, same documented gap as `SiteController`/the
      Updates page.

##### Deliverables

- New: `admin/views/media/media.php`, `admin/views/media/upload.php`,
  `admin/views/media/import.php`, `admin/views/media/thumbnails.php`.
- Removed: `admin/views/media.php`.
- Updated: `admin/index.php`, `admin/views/dashboard.php`,
  `admin/views/settings/media.php`.

---

### LP-061. Move Thumbnail & Media Import Settings into Media Manager

**Implemented (2026-08-04).**

##### Goal

`admin/views/settings/media.php` (Settings &rsaquo; Media) currently holds
three sections: Thumbnails (per-size dimensions/mode, JPEG/WebP quality,
sharpening, max source pixels, default featured image), Media Import
(the allowed-server-directories list LP-060's new Import from Server page
already reads), and Statistics (download tracking toggle). Move the first
two into their corresponding LP-060 Media Manager sub-pages instead —
Thumbnails' settings belong with the Thumbnails page's bulk-regenerate/
cleanup tools, and Media Import's allowed-directories list belongs with
the Import from Server page's scan/import flow — rather than living on a
separate Settings page an admin has to leave Media Manager to reach.
Statistics stays on Settings &rsaquo; Media untouched (not part of this
move).

##### Security note (read before implementing)

Media Manager's pages are gated by the `upload_files` capability, which
Author and Editor roles both hold (`UserRole::capabilities()`). Settings
&rsaquo; Media is gated by `manage_options`, which **only Administrator**
holds. These two settings sections change site-wide configuration
(thumbnail generation parameters; which server directories can be
scanned for import) — moving them onto an `upload_files`-gated page
without an additional check would let Authors/Editors reconfigure
settings they can't touch today, a real privilege escalation. Each
moved section's form (both the rendered `<section>` and its POST
handler) must stay gated behind an explicit `$currentUser->can('manage_options')`
check on the destination page, even though the rest of that page only
requires `upload_files`. The pre-existing bulk-regenerate/cleanup
(Thumbnails) and scan/import (Import from Server) functionality is
unaffected and stays available to any `upload_files` user, as it is
today.

##### Plan

- [x] `admin/views/media/thumbnails.php`: add `$mediaService = $kernel->media;`
      (needed for the "Default featured image" picker, not currently used
      on this page) and move the `thumbnail_settings` POST handler +
      "Thumbnails" `<section>` from `settings/media.php` in verbatim,
      renaming the section heading to "Thumbnail Settings" to
      distinguish it from the page's existing "Thumbnails" `<h1>`/nav
      context. Wrap both the handler and the rendered section in
      `$currentUser->can('manage_options')` per the security note above.
      Redirect target becomes `admin_url('media/thumbnails') . '?saved=1'`.
- [x] `admin/views/media/import.php`: move the `media_import_settings`
      POST handler + "Media Import" `<section>` from `settings/media.php`
      in verbatim, renaming the heading to "Import Settings". Wrap both
      in `$currentUser->can('manage_options')`, same as above. Redirect
      target becomes `admin_url('media/import') . '?saved=1'`. Update the
      "No import directories are configured" placeholder — it currently
      links to `Settings &rsaquo; Media`, which no longer holds this
      setting; point it at the new Import Settings section on this same
      page instead (or, for a non-`manage_options` viewer who can't act
      on it anyway, just note that an administrator needs to configure
      one).
- [x] `admin/views/settings/media.php`: remove the `thumbnail_settings`/
      `media_import_settings` POST handlers and their two `<section>`
      blocks, leaving only the pre-existing Statistics section. Update
      the file's top-of-file docblock (currently describes Thumbnails/
      Media Import as living here "now that Settings has sub-pages") to
      reflect that both moved again, to Media Manager.
- [x] Verify `php -l` on every changed file, a clean
      `composer stan`/`composer test` run, and manually trace both new
      `manage_options` guards against `UserRole::capabilities()` to
      confirm Author/Editor really can't reach either form — `php -l`
      clean on all 3 changed files, `composer stan` clean, `composer
      test` 813/813 passing. Traced: `UserRole::Author`/`::Editor` both
      list `upload_files` but not `manage_options` in their
      `capabilities()` array, so `$currentUser->can('manage_options')`
      returns `false` for both — the settings `<section>` doesn't render
      and the POST handler's `elseif` condition short-circuits before
      `Csrf::verify()` even runs, blocking a direct form-post bypass too.
      Only `UserRole::Administrator` includes `manage_options`. No
      automated test exists for admin-view capability gating in this
      codebase to extend — same documented gap LP-059/LP-060 already
      noted.

##### Deliverables

- Updated: `admin/views/media/thumbnails.php`, `admin/views/media/import.php`,
  `admin/views/settings/media.php`.

---

### LP-062. Custom CSS as a Dedicated Appearance Sub-Page

**Implemented (2026-08-04).**

##### Goal

`admin/views/appearance/themes.php` currently holds three independent
sections (Themes, Branding, Custom CSS) behind one URL
(`admin/appearance/themes`), each dispatched on its own `form` value —
the same shape LP-034 originally built it in. Pull Custom CSS out into
its own routed sub-page, matching how Widgets/Menus/Theme Editor already
sit alongside Themes as siblings under Appearance
(`$menu['appearance']['children']` in `admin/index.php`) rather than
being crammed onto the Themes page itself.

##### Plan

- [x] `admin/index.php`: add a `custom-css` entry to
      `$menu['appearance']['children']` (label "Custom CSS", same
      `manage_themes` capability every other Appearance child already
      uses), positioned after `themes` so it reads as "Themes, Widgets,
      Menus, Custom CSS, Theme Editor" in the sidebar.
- [x] New `admin/views/appearance/custom-css.php`: the `custom_css` POST
      handler (verbatim — same option key `custom_css`, same CSRF action
      name `custom_css`) and the "Custom CSS" `<section>`/textarea moved
      out of `themes.php`, redirecting to
      `admin_url('appearance/custom-css') . '?saved=1'` on save instead
      of back to the Themes page.
- [x] `admin/views/appearance/themes.php`: remove the `custom_css`
      handler branch and its `<section>`, and drop the now-stale
      docblock reference to "Custom CSS" in the file's top-of-file
      comment (still accurately describes Themes/Branding as the two
      remaining sections).
- [x] Verify `php -l` on both changed files and a clean
      `composer stan`/`composer test` run — `php -l` clean on all 3
      files, `composer stan` clean, `composer test` 813/813 passing, no
      regressions. No new automated test added (same documented gap
      every other admin-view-only ticket this session has noted).

##### Deliverables

- New: `admin/views/appearance/custom-css.php`.
- Updated: `admin/index.php`, `admin/views/appearance/themes.php`.

---

### LP-063. Admin Visual Polish Pass 2: Dropdowns, Plain Links, Grid Cards

**Implemented (2026-08-04).**

**Follow-up (2026-08-04, same day):** the Comments page's moderation row
actions (Approve/Unapprove/Spam/Trash, `admin/views/comments.php`) still
looked unstyled after this ticket shipped — they used a separate,
never-updated `.lp-button--link-muted` class instead of the
`.lp-button--link`/`--link--danger` pill treatment the rest of the
admin's row actions got in the earlier button-modernization pass this
session. Switched all four to `.lp-button--link` (Approve/Unapprove
neutral, Spam/Trash danger) and deleted the now-unused
`.lp-button--link-muted` rule. Also caught, while investigating the same
screenshot, that `.lp-table td a` (a post/page title link, a comment's
excerpt link, the post a comment belongs to — every plain link inside
any admin table) had no color rule at all, same "falls back to browser
default blue/purple/underlined" bug this ticket's plain-list-link fix
addressed elsewhere; added a matching `.lp-table td a` rule (accent
color by default, since a table row's title link is its primary click
target — not defaulting to body text like the quieter list links this
ticket already fixed).

##### Goal

LP-055 (2026-08-01) polished panels, tables, buttons, form inputs,
alerts, and cards — but audited against real screenshots (Theme Editor,
Media Manager grid/sidebar, Dashboard), three categories of element were
missed and still look like unstyled browser defaults:

1. **`<select>` dropdowns outside a `.lp-field` wrapper.** `.lp-field
   select` (admin.css) only styles a `<select>` when its containing
   element carries the `.lp-field` class — several standalone/inline
   selects don't: the Theme Editor's theme picker
   (`admin/views/appearance/editor.php`, `#tfe-theme-select`, wrapped
   only by `.lp-theme-editor__theme-select` on the `<form>`), the Menus
   page's menu picker (`admin/views/appearance/menus.php`,
   `#menu-select`, wrapped only by `.lp-menus-select-form`), the Posts
   list's bulk-action select (`admin/views/posts/all-posts.php`,
   `#posts-bulk-action`, wrapped only by `.lp-admin__bulk-actions`), and
   the Media Manager grid's bulk-action/target-folder selects
   (`admin/views/media/media.php`, `.lp-media-manager__bulk-bar`). Each
   renders as a bare native dropdown with no border/radius/focus-ring.
2. **Plain list-item links with no explicit color/underline treatment**,
   so they fall back to the browser default (blue, underlined, purple
   once visited) instead of this admin theme's accent color — visibly
   inconsistent against everything else in the same panel. Confirmed via
   CSS audit (no `a` rule exists under the relevant class) in three
   components: `.lp-admin__meta-list` (Dashboard's Recent Posts/Recent
   Comments/Popular Downloads — the very first page every admin sees),
   `.lp-folder-tree__item` (Media Manager's Views/Folders sidebar),
   `.lp-thumbnails__list` (the small/medium/large generated-thumbnail
   links on a media item's edit page).
3. **`.lp-media-grid__item` never got LP-055's card treatment.** Unlike
   `.lp-theme-card`/`.lp-plugin-card` (bordered, panel-radius, shadow,
   hover-lift), a media grid item is only a plain 1px-bordered box with
   no shadow/hover feedback — and `.lp-media-grid__name` sets a custom
   text color but never strips the anchor's default underline, so a
   filename shows mismatched "custom color + browser-default underline"
   styling (visible in the Media Manager screenshot: "extant_Wallpap...").

##### Plan

- [x] Add a universal `select` base rule to `admin/assets/css/admin.css`
      (border/radius/background/color/font-size matching `.lp-field
      input`/`textarea`, plus its own `:focus` ring) so every dropdown
      gets consistent chrome regardless of whether it's wrapped in
      `.lp-field`. Default to `width: auto` (most unwrapped selects sit
      inline in a flex row next to a button/label, not a vertical form
      field) and keep `.lp-field select { width: 100%; }` as a
      higher-specificity override for the selects that should still fill
      their field's width, so nothing already correct regresses.
- [x] Add shared plain-list-link styling for `.lp-admin__meta-list a`,
      `.lp-folder-tree__item a`, and `.lp-thumbnails__list a`: default to
      the theme's body text color with no underline, switch to the
      accent color with an underline on hover/focus — matching the
      no-underline-until-hover convention `.lp-theme-editor__node-label--file`
      already established. `.lp-folder-tree__item.is-active > a`'s
      existing bold/accent-color rule is unaffected (higher specificity,
      already correct).
- [x] Give `.lp-media-grid__item` the same card treatment as
      `.lp-theme-card`/`.lp-plugin-card` (`box-shadow: var(--lp-admin-shadow)`,
      hover swaps to `var(--lp-admin-shadow-hover)` with a
      `translateY(-2px)` lift, same transition timing). Add
      `text-decoration: none` to `.lp-media-grid__name` and a hover rule
      that switches it to the accent color with an underline, so hovering
      the card gives clear feedback instead of a half-styled default
      link.
- [x] Verify in a browser (or via careful visual review of the changed
      CSS against the screenshots that prompted this ticket) that: every
      previously-bare select now has visible chrome without breaking its
      surrounding flex-row layout; folder-tree/meta-list/thumbnail links
      no longer render in default blue/purple; media grid cards get a
      visible shadow and lift on hover — no browser/dev-server was
      available this session to click through live, so this was verified
      by re-reading the changed CSS rules and their computed specificity
      against every affected selector rather than a real render. `php -l`
      is N/A (CSS-only); `composer stan`/`composer test` run anyway as a
      regression check since `admin.css` is shared across every admin
      page.

##### Deliverables

- Updated: `admin/assets/css/admin.css`.

---

### LP-064. Automatic Directory Discovery for Import from Server

**Implemented (2026-08-04).**

##### Goal

Media Manager &rsaquo; Import from Server's "Allowed import directories"
field (`admin/views/media/import.php`'s Import Settings panel) requires
an administrator to already know exact absolute server paths and type
them in blind — there's no way to see what's actually on the server
without a separate FTP client or hosting file manager. Add a "Discover
Directories" action that scans a small, bounded, safe location for real
subdirectories and lets the administrator pick which ones to add,
instead of typing paths from memory.

##### Design

- **Scope of the scan, deliberately narrow:** only the immediate
  (non-recursive) subdirectories of `dirname(LUMORA_ROOT)` — the parent
  of the Lumora Press install itself. On typical shared hosting this is
  the account's home/document-root parent, where an FTP-dropped
  "incoming"/"uploads" folder would actually land as a sibling of the
  site. Not a recursive/deep scan (bounded cost, doesn't turn into a
  general file browser), not configurable to an arbitrary starting path
  (would reopen the exact "type a path you have to already know" problem
  this ticket exists to solve, plus a much larger disclosure surface).
- **Excluded from results:** dot-directories, anything that isn't a real,
  readable directory after `realpath()` resolution (mirroring
  `MediaImportService::isPathAllowed()`'s existing symlink-safety
  posture), and `LUMORA_ROOT` itself (it's necessarily one of the
  scanned siblings' siblings — er, one level up's child — but importing
  from the app's own install directory doesn't make sense as a source).
  Capped at 200 entries so a huge home directory can't turn this into an
  expensive or unwieldy request.
- **Read-only scan, explicit opt-in add:** listing candidates never
  writes anything — an administrator must tick specific directories and
  submit "Add Selected" before any of them are merged into the saved
  `media_import_allowed_directories` option (deduplicated against what's
  already there). Directories already on the allowed list are shown but
  not re-selectable (no-op if picked again). Same `manage_options` gate
  (both the discovery view and the add-selected handler) as the rest of
  this panel per LP-061's security note — Author/Editor still can't
  reach any of it.

##### Plan

- [x] `MediaImportService::discoverCandidateDirectories(): array` — new
      method, `array<int, string>` return, doing the bounded
      one-level-deep `dirname(LUMORA_ROOT)` scan described above. Returns
      `[]` (not an error) if the parent directory isn't readable at all
      (e.g. `open_basedir` restrictions on some hosts) — the view treats
      an empty result as "nothing found," not a hard failure.
- [x] `admin/views/media/import.php`: add a "Discover Directories" button
      to the Import Settings panel (only rendered for
      `$currentUser->can('manage_options')`, same as the rest of that
      panel) that reveals a checklist of `discoverCandidateDirectories()`
      results via a `?discover=1` query flag — a pure display toggle,
      no CSRF needed since nothing is written by viewing it. Each row
      shows the path, with already-allowed directories marked "Already
      allowed" instead of a checkbox (nothing to add). A new
      `add_discovered_directories` POST handler (CSRF-protected,
      `manage_options`-gated) merges the checked paths into
      `media_import_allowed_directories`, deduplicated, and redirects
      back to `media/import?saved=1`.
- [x] Verify `php -l`, a clean `composer stan`/`composer test` run, and
      add `MediaImportServiceTest` coverage for
      `discoverCandidateDirectories()` — `php -l` clean on all 3 changed/
      new files, `composer stan` clean (0 errors), `composer test`
      820/820 passing (7 new tests: immediate-subdirectory discovery,
      nested subdirectories ignored, dot-directories ignored, files never
      returned, excluded paths honored, already-allowed flagging,
      unreadable-parent returns `[]` rather than erroring).

##### Deliverables

- New: `MediaImportService::discoverCandidateDirectories()`.
- Updated: `admin/views/media/import.php`.
- New/updated tests: `PHP Test Suite/Unit/Services/MediaImportServiceTest.php`.

---

### LP-066. Site Default Editor

**2026-08-04: `LP-065` ("User Default Editor Preference") merged in here
and into `LP-067`** — see `DECISIONS.md`'s "LP-065 merged into LP-066/
LP-067" entry. `LP-065` described the same single feature (site default +
per-user override + admin lock toggle) LP-066/LP-067 already describe in
more detail, split across two tickets instead of one. `LP-065` was deleted
from `TODO.md` outright; its few non-duplicate items are folded into
LP-066/LP-067 below, tagged "(from LP-065)".

**Implemented (2026-08-04), together with LP-067:** a new
`app/Services/EditorPreferenceService.php`, wired into `Kernel` as
`$kernel->editorPreferences`. Settings &rsaquo; General gained an
"Editor" panel (default editor select, built from
`registeredEditors()` so a plugin-added editor — via
`apply_filters('registered_editors', ...)` — appears automatically; a
"lock every user to the default" checkbox). `get_default_editor()` and
`get_active_editor($userId)` are exposed as global procedural helpers
(`include/helpers.php`, backed by a new `ActiveEditorPreference` static
bridge matching `ActiveConfig`'s shape), plus `registered_editors()`.
`admin/views/posts/new.php` and `admin/views/pages.php` now pre-select
`get_active_editor($currentUser->id)` for a brand-new post/page instead of
a hardcoded `ContentFormat::Markdown`; an existing post/page's own stored
format still always wins. See LP-067 for the per-user/lock-toggle half.

#### Settings → General

Add a **Default Editor** setting.

- [x] Plain text Editor
- [x] Markdown Editor
- [x] WYSIWYG (TinyMCE)

#### Behavior

- [x] Selected editor becomes the default for all users.
- [x] Users with **Use site default** selected inherit this setting automatically.
- [x] If user editor overrides are disabled, all users are forced to use the site default.
- [x] Changing the site default affects new editing sessions without modifying existing user preferences.

#### Permissions

- [x] Only administrators can change the site default editor.
- [x] Respect existing capability checks for General Settings.

#### Technical Notes

- Store the setting as a global configuration option.
- Expose a helper (e.g. `get_default_editor()`) so the active editor is determined from a single location.
- Support editors registered by plugins so the dropdown is populated dynamically rather than hard-coded.
- Gracefully fall back to the Plain Text Editor if the configured editor is unavailable or disabled.

---

### LP-067. Allow Users to Choose Editor

#### Goal

Give administrators control over whether users can choose their own default editor.

**Implemented (2026-08-04), together with LP-066** — see that ticket's
implementation note for the shared service/helpers. A new "My Profile"
admin page (`admin/views/profile.php`, `capability: null` — every
authenticated role reaches it for their own account only, the same shape
`api-tokens` already uses) lets any user set their own editor preference,
disabled/hidden with an explanatory note when the site-wide lock is on.
`admin/views/users.php`'s existing edit-any-user form also gained the same
field, so an administrator can set it on another user's behalf. Deferred:
"remember the last selected editor across sessions" (see its own item
below for why).

#### User Profile Page (from LP-065)

Add a **Default Editor** setting to each user's own profile page:

- [x] Plain Text Editor
- [x] Markdown Editor
- [x] WYSIWYG (TinyMCE)
- [x] Use site default

#### Settings → General

Add a setting:

**Allow users to select their own editor**

- [x] Enabled (default)
- [x] Disabled

#### Behavior

- [x] When enabled, users can change their **Default Editor** preference from their User Profile.

- [x] When disabled, all users use the site-wide **Default Editor**.

- [x] The **Default Editor** field on the User Profile page is hidden or disabled when user selection is not allowed.

- [x] Existing user preferences are preserved but ignored while the setting is disabled.

- [x] Re-enabling the setting restores each user's previously selected editor.

- [x] New posts and pages automatically open in the user's active editor (from LP-065)

- [x] "Use site default" inherits the global editor setting (from LP-065)

#### Permissions

- [x] Only administrators can change the site-wide allow/disallow setting.
- [x] Users may change only their own editor preference (from LP-065)
- [x] Administrators can edit the preference for any user (from LP-065), same as they already edit any other user field
- [x] Administrators may still view and edit user editor preferences if desired.

#### Technical Notes

- Store this as a global configuration option.
- Store each user's own preference in their profile/settings, not as a global configuration (from LP-065) — a nullable `preferred_editor` column, `NULL` meaning "use site default."
- Editor selection should follow this order:
  1. If user editor selection is disabled → use the site default.
  2. If enabled and the user has selected an editor → use the user's preference.
  3. Otherwise → use the site default.
- Expose a single helper (e.g. `get_active_editor($userId)`) to centralize the editor selection logic.

---

### LP-068. Bugs

**Media Manager**

- [x] box run off over edge (screenshots) — folder rename input in the Virtual Folder System's "Manage" panel overflowed the sidebar's right edge at deeper nesting levels; fixed by capping the input width and letting the rename/delete form wrap (`admin/assets/css/admin.css`)
- [x] Add select all — checkbox above the media grid toggling every `ids[]` checkbox in the bulk-action form, matching the existing `posts-select-all` inline-onclick pattern already used on the All Posts screen (`admin/views/media/media.php`)

Virtual Folder System

- [x] prettify the virtual folders and make action buttons clear (New folder, Manage) — the bare `<details><summary>` toggles now render as styled buttons (new `.lp-folder-actions`/`.lp-folder-actions--primary` classes in `admin/assets/css/admin.css`), with "New Folder" visually distinguished as the primary/accent action
- [x] make the folders draggable — native HTML5 drag-and-drop lets a folder be dragged onto another folder (reparents it there) or onto the "Folders" heading (moves it to top level); reuses each folder's existing rename form/`rename_folder` POST handler rather than a new endpoint, so `FolderService::update()`'s existing cycle-prevention still applies (`admin/assets/js/folder-drag-drop.js`, new; wired up in `admin/views/layout-footer.php`)

- [x] Make Search & Filter Collapsible/expendable (default: collapsed) everywwhere — the two existing "Search & Filter" panels (All Posts, Media Manager) now open collapsed by default as a native `<details>`/`<summary>` disclosure restyled to match the panel header it replaces, with a chevron toggle indicator (new `.lp-admin__collapsible` class in `admin/assets/css/admin.css`; `admin/views/posts/all-posts.php`, `admin/views/media/media.php`)
- [x] Add New Post - markdown editor automatically has the last saved post's content in the post body — EasyMDE's autosave `uniqueId` was derived from `textarea.id`, which is the static string `post-content`/`page-content` shared by every post/page, so its localStorage-based autosave restored whatever was last typed into *any* post's (or any brand-new, never-saved post's) editor. Now keyed by a real per-post/per-page `data-autosave-id` (`post-{id}`/`page-{id}`) and disabled outright when there's no id yet (a not-yet-saved new post); the WYSIWYG (TinyMCE) editor's own `autosave` plugin had the same latent bug for brand-new posts specifically (its default key already includes the URL, which differs per existing post/page, but "Add New Post" is the same URL every time) and was fixed the same way (`admin/assets/js/content-editor.js`, `admin/views/posts/new.php`, `admin/views/pages.php`)
- [x] All Posts - all Bulk actions such as change to Draft to Publish, duplicates copy instead "Post duplicated as a new draft." — root cause: a `<form>` nested inside another `<form>` is invalid HTML; the bulk-action form wrapped the posts `<table>`, which had its own per-row Duplicate/Trash/Restore/Delete-Permanently `<form>`s nested inside it. Browsers silently drop the inner `<form>` start tag but still close the *outer* form on the first inner `</form>`, merging every row's hidden `name="form"`/`name="id"` fields into the bulk-action form's own POST body — since same-named fields keep only their last value, every "Apply" click was actually processed server-side as whichever row-action form happened to close the outer form first (in practice, the first listed post's Duplicate action), regardless of which bulk action or posts were actually selected. Fixed by moving each row's hidden inputs/button out of a nested `<form>` and associating them with a standalone, non-nested `<form>` via the HTML `form=""` attribute instead (`admin/views/posts/all-posts.php`)

---

### LP-069. Audit remaining inline event-handler attributes blocked by CSP

While fixing LP-068's "Add select all" (Media Manager), the new
`onclick="..."` handler silently did nothing beyond toggling its own
checkbox — traced to `ContentSecurityPolicy::defaultDirectives()`'s
`script-src 'self'` (no `'unsafe-inline'`), sent on every request by
default (`csp_enabled` defaults to `true` in `include/bootstrap.php`).
Unlike a nonce-able `<script>` tag, CSP has no equivalent allowance for
inline `on*="..."` attributes short of `'unsafe-hashes'` (which this
project's CSP doesn't set) — so every inline event-handler attribute in
the admin is silently broken on a default install, with no console error
or other visible failure. This is the same failure class already
documented for `style-src` (see the LP-051-era "Real bug found and fixed,
same day: the CSP header was silently blocking every inline `<style>`
tag" note and `DECISIONS.md`), just never previously checked for
`script-src`.

Already fixed as part of LP-068 (both moved to a new external,
CSP-compliant `admin/assets/js/select-all.js`):

- [x] `admin/views/media/media.php`'s "Select all" checkbox
- [x] `admin/views/posts/all-posts.php`'s pre-existing identical
      `posts-select-all` bug (same root cause, not part of LP-068's
      original scope, fixed alongside since it's the exact same pattern)

All audited and fixed. Centralized on the "generic `data-lp-confirm`
handler" option this ticket's fix-pattern note anticipated, plus two
sibling generic scripts for the two other inline-handler shapes found
along the way (an `onchange="this.form.submit()"` auto-submit pattern,
and one `oninput` pair unchecking a paired checkbox) — all three
registered in `admin/views/layout-footer.php`:

- `admin/assets/js/confirm-submit.js` — `data-lp-confirm="message"` on
  a `<form>` (confirms on every submit) or a `<button type="submit">`
  (confirms only for that specific button, for forms with more than
  one submit button with different consequences, e.g.
  theme-options.php's per-section Save vs. Reset to Defaults).
- `admin/assets/js/auto-submit.js` — `data-lp-auto-submit` on a
  `<select>`, submits its form on change.
- `admin/assets/js/color-field-reset.js` — `data-lp-color-reset-target="checkbox-id"`
  on a color `<input>`, unchecks the named checkbox on input
  (theme-options.php's per-field "use theme default" checkbox).

- [x] `admin/views/appearance/theme-options.php` (also had one
      `oninput` — color-reset checkbox — beyond the onclick/onsubmit
      this ticket's own audit had already found)
- [x] `admin/views/settings/cache.php`
- [x] `admin/views/users.php`
- [x] `admin/views/appearance/menus.php`
- [x] `admin/views/api-tokens.php`
- [x] `admin/views/posts/new.php`
- [x] `admin/views/appearance/widgets.php`
- [x] `admin/views/plugins.php`
- [x] `admin/views/comments.php`
- [x] `admin/views/settings/redirects.php`
- [x] `admin/views/pages.php`
- [x] `admin/views/appearance/themes.php`
- [x] `admin/views/media/media.php` (its own folder-delete/file-delete
      `onsubmit="return confirm(...)"` calls — separate from the
      select-all checkbox already fixed above)
- [x] `admin/views/posts/tags.php`
- [x] `admin/views/posts/categories.php`
- [x] `admin/views/appearance/editor.php` (also had a conditional
      onsubmit — only confirms when the open file is `.php` — now a
      conditionally-emitted `data-lp-confirm` attribute instead)
- [x] `admin/views/maintenance/updates.php`
- [x] `admin/views/posts/all-posts.php` — two more instances
      (`delete_permanently`, `trash`) found during a final repo-wide
      `on[a-z]+="` sweep after finishing the list above; not part of
      this ticket's original 16-file enumeration but the identical bug,
      so fixed alongside rather than filed separately.

A final sweep of `admin/`, `content/`, and `install/` for any
`on[a-z]+="` attribute confirmed zero remaining matches outside the
three new script files' own docblocks/source. Verified with `php -l`
on every changed PHP file and `node -c` on the three new JS files, plus
a full `composer test` (981 tests) and `composer stan` run (both clean)
from `PHP Test Suite/`.

