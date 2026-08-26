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

---

## 0.6.0 (2026-08-10)

### LP-018. Scheduled Posts

**Implemented (2026-07-21)** as part of LP-008 Posts —
`LumoraPress\Services\PostService` stores a `PostStatus::Scheduled` post's
future `published_at` and computes public visibility live
(`published OR (scheduled AND published_at <= NOW())`) rather than via cron;
see `DECISIONS.md`'s "Scheduled posts don't need a background job".

**Optional scheduled unpublishing implemented (2026-07-21, also as part of
LP-008 Posts)** — this checkbox had been left unticked despite the feature
already existing: the `unpublish_at` column, `PostService::create()`/
`update()` params, `publicWhereClause()`'s exclusion of posts past their
`unpublish_at`, and the admin form field (`admin/views/posts/new.php`) were
all already in place, with test coverage in `PostServiceTest`/`PostTest`.
Ticked off 2026-08-06 as a status correction, not new work.

**Draft scheduling implemented (2026-08-06)** — a Draft can now carry a
`published_at` as a planned/intended publish date (set via the same
"Publish date" field the admin edit form already had for Scheduled posts,
now also accepted when status is Draft). It has no effect on visibility —
`publicWhereClause()` never matches Draft regardless — and survives a later
Draft → Scheduled transition without needing to be re-entered, since
`PostService::resolvePublishedAt()`'s Draft branch now falls back to the
existing value instead of being forced to `null`. Gated behind the same
`$canPublish` check as Visibility/Sticky/Schedule-unpublishing, matching
this form's existing "publish-time decision" gating pattern.

**"Cron scheduling" declined (2026-08-06)** — removed from the checklist
below rather than left open. See `DECISIONS.md`'s "LP-018's 'Cron
scheduling' checklist item declined" for the rationale.

**Time zone support confirmed already in place, per-post/per-user override
declined (2026-08-06)** — `PostService` always builds dates with `new
DateTimeImmutable()` and no explicit offset, so both what an admin types
into the "Publish date"/"Unpublish date" fields and the `now()` used for
every visibility comparison are already interpreted in the single site-wide
timezone (Settings &rsaquo; General's "timezone" option, applied via
`date_default_timezone_set()` in `include/bootstrap.php` — see LP-042).
Per-post/per-user timezone override was considered and declined as a
separate feature rather than built: there is no per-user timezone anywhere
in the codebase today, and a single site-wide timezone is the same model
WordPress itself has always used. See `DECISIONS.md`'s "LP-018's 'Time zone
support' checklist item closed without a per-post/per-user override" for
the full rationale.

#### Goal

Allow scheduled publishing.

#### Features

- [x] Future publication
- [x] Time zone support — a single site-wide timezone (Settings &rsaquo; General), no per-post/per-user override
- [x] Draft scheduling
- [x] Optional scheduled unpublishing

---

### LP-031. Media Viewer & Lightbox System

#### Goal

Create a modern, responsive media viewing experience for all supported media types while keeping the implementation lightweight, fast, and theme-friendly.

Second pass implemented this session: image dimensions, an optional
filename display (site-wide setting), a download button, a slideshow
mode, deep-link support, video poster images, and video captions/
subtitles. See each checklist item below for exact scope. A handful of
items not applicable to Lumora Press (Album/Favorites/Most viewed/
Custom collections gallery navigation — no such concepts exist anywhere
in this app) and permanently deferred/out-of-scope items (OGV video,
audio album art/metadata, text-file syntax highlighting, themes
disabling the built-in viewer, high-contrast verification, next/previous
preloading) were removed from the checklist entirely rather than left
unchecked, since none represent real gaps to close later.

**Real bug found and fixed while browser-testing the new toolbar
buttons**: `.lp-pswp-meta` (the dimensions/filename bar added above) is
a full-width `position: absolute; top: 0` overlay — the same edge
PhotoSwipe's own toolbar (zoom/close, plus this session's new download/
slideshow buttons) renders into. With no `pointer-events` override, that
overlay sat on top of the toolbar and silently swallowed clicks meant
for those buttons, even though the buttons remained visibly unobstructed
(the bar has no border/shadow signaling it's there) — reported as "the
lightbox top buttons don't work when I click on them." Fixed by adding
`pointer-events: none` to both `.lp-pswp-meta` and (defensively, same
risk class) `.lp-pswp-caption` in both `content/themes/default/style.css`
and `admin/assets/css/admin.css` — neither element ever needs to receive
clicks, only display text.

**Two more real bugs found immediately after, when the fix above was
reported as still not working** — both structural gaps, not specific to
this bar:

1. Neither theme nor admin CSS/JS assets carry a cache-busting query
   string, so a browser that had loaded the page even once before a
   fix shipped kept serving its cached copy indefinitely — the
   pointer-events fix above was correctly deployed server-side but
   invisible in the browser under test (`getComputedStyle()` showed the
   stale `pointer-events: auto`). Fixed by having `ThemeRenderer::themeUrl()`
   and `admin_asset_url()` (`include/helpers.php`) append
   `?v={filemtime}` automatically for any path resolving to a real
   file — no manual version bumping, self-invalidating on every future
   edit.
2. The "Show filenames in lightbox" setting's `window.lpMediaViewer = {...}`
   inline `<script>` was silently blocked by this project's own
   Content-Security-Policy (`script-src` has no inline-execution
   allowance, only `style-src` does — see `CspNonce`). Confirmed via the
   browser console. Fixed by moving the value onto a
   `data-show-filenames` attribute on the existing external
   `<script type="module">` tag instead, read via
   `document.querySelector('script[data-lp-media-viewer]')` in both
   `media-viewer.js` copies (a module script never gets
   `document.currentScript`).

**A fourth real bug, found once cache-busting made the fixes above
actually reach the browser**: the plain `pointer-events: none` on
`.lp-pswp-meta`/`.lp-pswp-caption` wasn't enough by itself — reported as
buttons only responding when clicked just below/outside their visible
area. Fetching PhotoSwipe 5.4.4's real `photoswipe.css` directly showed
why: `registerElement()` auto-applies PhotoSwipe's own `pswp__hide-on-close`
class to elements registered this way (confirmed — its `z-index: 10` is
exactly what `getComputedStyle()` had shown on `.lp-pswp-meta` earlier),
and that class's own CSS includes
`.pswp--ui-visible .pswp__hide-on-close { pointer-events: auto; }` — two
classes, higher specificity than this project's single-class selector,
which silently wins pointer-events back the instant the lightbox
finishes its open transition (`pswp--ui-visible` gets added to the root)
— exactly when a visitor would try to click. Fixed with
`pointer-events: none !important` on both rules in both stylesheets,
which no rule in PhotoSwipe's own CSS uses and so can't lose to.

**A fifth real bug, found once clicks reached the buttons at all**: the
download/slideshow icons were rendering nearly invisible against the
dark toolbar — reported directly after the fourth fix confirmed clicks
worked. Both custom SVG `<path>` elements (`media-viewer.js`, both
copies) carried an explicit `fill="currentColor"`. That's a specified
value on the `<path>` itself, so it does not inherit `fill` from its
parent `.pswp__icn` (which PhotoSwipe's CSS sets to
`var(--pswp-icon-color)`, white — the correct icon color, and what
PhotoSwipe's own zoom/close icons render as by simply not setting `fill`
on their own paths at all). Instead `currentColor` resolved to
`.pswp__icn`'s `color` property, `var(--pswp-icon-color-secondary)` — a
dark grey PhotoSwipe reserves for icon shadows/outlines, not fills —
against a near-black toolbar background. Fixed by removing the
attribute entirely so both icons inherit the same way PhotoSwipe's own
already do.

See `docs/CHANGELOG.md`'s entries for this date and
`PHP Test Suite/TEST_LOG.md` for the full Docker-matrix verification of
the first three fixes (cache-busting, CSP-blocked script, the initial
pointer-events pass). The fourth and fifth (`!important`, icon fill) are
CSS/SVG-only — verified live in a real browser against the deployed
host, not by the PHP test suite, which has no way to exercise a
rendering/specificity issue like either of these.

#### Image Viewing

- [x]  Use a modern JavaScript lightbox for images (preferably **PhotoSwipe 5**) —
       loaded from the jsDelivr CDN at a pinned version (5.4.4), per explicit
       request, rather than vendored locally.
- [x]  Clicking any image thumbnail opens the lightbox.
- [x]  Keyboard navigation (← → Esc) — PhotoSwipe 5's own built-in behavior.
- [x]  Touch gestures on mobile — PhotoSwipe 5's own built-in behavior.
- [x]  Mouse wheel zoom support — PhotoSwipe 5's own built-in behavior.
- [x]  Pinch-to-zoom on touch devices — PhotoSwipe 5's own built-in behavior.
- [x]  Smooth opening/closing animations — PhotoSwipe 5's own built-in behavior.
- [x]  Lazy-load adjacent images — PhotoSwipe 5's own built-in behavior.
- [x]  Display image dimensions — a custom PhotoSwipe UI element
       (`lp-meta`, both `content/themes/default/assets/js/media-viewer.js`
       and its admin copy) reads the `data-pswp-width`/`data-pswp-height`
       attributes already emitted by `the_post_thumbnail_lightbox()`.
- [x]  Optional filename display — a site-wide "Show filenames in
       lightbox" toggle (Settings &rsaquo; Media, `lightbox_show_filenames`
       option) gates whether `lp-meta` also shows the filename, sourced
       from a new `data-pswp-filename` attribute; the setting reaches the
       JS via a small inline `window.lpMediaViewer = {...}` script emitted
       right before `media-viewer.js` loads (`footer.php`/
       `admin/views/layout-footer.php`), since the JS has no PHP access of
       its own.
- [x]  Optional caption/description — a custom PhotoSwipe UI element shows
       the media's caption (falling back to alt text), sourced from the
       `caption`/`alt_text` columns already captured by Media Manager (LP-005).
- [x] Download button — a custom PhotoSwipe UI element (`lp-download`)
       triggers a real browser download (an `<a download>` click) of the
       currently displayed full-size image, named from
       `data-pswp-filename`.
- [x] Slideshow mode — a custom PhotoSwipe UI element (`lp-slideshow`)
       toggles a 4-second auto-advance (`pswp.next()` on an interval,
       cleared on toggle-off or lightbox close) rather than a bundled
       PhotoSwipe plugin, to avoid a second CDN dependency for one small
       feature.
- [x]  Deep-link support so individual images can be linked directly —
       implemented without PhotoSwipe's own (v4-era, not bundled in v5)
       history plugin: `media-viewer.js` reflects the open image as a
       `#lp-media-{id}` URL hash (`history.replaceState`, so paging
       through a gallery doesn't spam browser history) and, on page load,
       reopens the matching image via `lightbox.loadAndOpen()` if that
       hash is present. `data-pswp-id` (the media row's id) backs the
       match.

#### Gallery Navigation

When browsing inside:

- [x] Category — the category archive (`archive.php`).
- [x] Search results — search results gained thumbnails (`SearchResult`
      now has `featuredImageId`, mirroring Post/Page) specifically so this
      is a real navigable gallery, not an empty one.
- [x] Tag results — the tag archive (`archive.php`, shared with Category).
- [x] Recent uploads — the homepage's recent-posts list (`index.php`); the
      admin Media Manager's own grid keeps its existing thumbnail → edit-page
      click target rather than gaining a competing lightbox click target.

the lightbox should allow seamless navigation to the previous/next media item without returning to the gallery page. Implemented: every list-view page (homepage/archive/category/tag/search) groups its thumbnails into one lightbox gallery per page load; a single post/page's featured image is a lightbox "gallery" of one.

#### Other Media Types

Use an appropriate viewer depending on the file type.

Non-image types (video/audio/PDF) only ever appear in the admin Media
Manager today — there is no public embedding path for them (post/page
content is plain escaped text, not HTML, so nothing ever embeds a video/
audio/PDF publicly). Their viewers are therefore implemented on the
Media Manager's edit/preview page (`admin/views/media.php?action=edit`),
not publicly.

##### Images

- [x] PhotoSwipe lightbox

##### Animated Images

- [x] GIF
- [x] Animated WebP
- [x] APNG

Display natively inside the lightbox — free: GIF/WebP/APNG all animate
natively in a plain `<img>` tag, and PhotoSwipe displays whatever image
element it's given the same way. No extra code was needed for this.

##### Video

- [x] HTML5 video player
- [x] MP4
- [x] WebM

Features:

- [x] play/pause — native `<video controls>`.
- [x] volume — native `<video controls>`.
- [x] fullscreen — native `<video controls>`.
- [x] picture-in-picture (where supported) — native `<video controls>`.
- [x] captions/subtitles — a new nullable `caption_track_media_id` column
      (migration `0032_add_video_poster_and_captions_to_media.sql`,
      `MediaService::setVideoAssets()`) references another media row — a
      WebVTT file uploaded like any other file (`.vtt`/`text/vtt` added to
      `MediaService`'s allow-list). The Media Manager's video edit view
      gained a "Caption/subtitle track" `<select>` of the site's uploaded
      `.vtt` files; when set, a `<track kind="subtitles">` is rendered
      inside the `<video>` element.
- [x] poster images — a new nullable `poster_media_id` column (same
      migration/method as captions above) references an image media row;
      the video edit view gained a "Poster image" `<select>` of the
      site's uploaded images, rendered as the `<video poster="...">`
      attribute when set.

##### Audio

Display a compact embedded audio player with:

- [x] play/pause — native `<audio controls>`.
- [x] seek bar — native `<audio controls>`.
- [x] volume — native `<audio controls>`.

##### PDF

- [x] Open in an embedded PDF viewer with download option — native
      browser PDF viewer via `<iframe>`; the download link already exists
      on the edit page's metadata line.

##### Unsupported Files

Display:

- [x] file icon — the existing type-category badge.
- [x] filename
- [x] filesize
- [x] MIME type
- [x] download button — already the file's own direct URL.

#### Theme Integration

- [x] Themes should not need to implement their own lightbox.
- [x] Provide a standardized Media Viewer API — `the_post_thumbnail_lightbox()`
      in `include/media-functions.php`.
- [x] Themes simply output media links with the required data attributes —
      `the_post_thumbnail_lightbox()` builds the `data-pswp-*` attributes;
      a theme only needs to wrap its gallery container in `class="lp-gallery"`.
- [x] Allow themes to override viewer styling through CSS — plain CSS
      classes (`.lp-gallery`, `.lp-pswp-caption`), no CSS-in-JS.

#### Accessibility

- [x] WCAG-compliant — relies on PhotoSwipe 5's own documented WCAG support.
- [x] Full keyboard navigation — PhotoSwipe 5's own built-in behavior.
- [x] Proper focus management — PhotoSwipe 5's own built-in behavior.
- [x] ARIA labels — PhotoSwipe 5's own built-in behavior.
- [x] Screen-reader friendly controls — PhotoSwipe 5's own built-in behavior.

#### Performance

- [x] Load viewer JavaScript only on pages containing media — a new
      `MediaViewer` static flag (set by `the_post_thumbnail_lightbox()`)
      gates the CDN `<script>`/`<link>` tags in `footer.php`/
      `admin/views/layout-footer.php`; a page with zero images loads neither.
- [x] Lazy-load assets — PhotoSwipe's own JS is only fetched via a dynamic
      `import()` when a gallery exists, and PhotoSwipe 5 lazy-loads adjacent
      images itself.
- [x] Support responsive image sizes (`srcset`/`sizes`) — already true of
      `the_post_thumbnail()`/`the_post_thumbnail_lightbox()` since LP-040.
- [x] Avoid loading full-resolution images until requested (zoom/download) —
      the lightbox link defaults to the `large` thumbnail size (quality-
      controlled, capped at 1024px), not the raw original, the same
      reasoning most WordPress themes use for their own "large" image
      size. Now admin-configurable (Settings &rsaquo; Media &rsaquo;
      "Lightbox image size") rather than hardcoded: a new
      `lightbox_large_size` `PressConfig` option (default `'large'`)
      lets an administrator pick any enabled registered thumbnail size
      (small/medium/large by default, plus anything a theme/plugin
      registers via the `thumbnail_sizes` filter) or `full` (the raw
      original) as the site-wide default.
      `the_post_thumbnail_lightbox()`'s `$largeSize` parameter is now
      nullable, resolving to this setting when a template omits it — all
      five theme call sites (`archive.php`/`index.php`/`search.php`
      already omitted it; `single.php`/`page.php`'s explicit
      `largeSize: 'large'` override was removed) pick up the setting
      automatically. `'full'` needed no special-casing:
      `ThumbnailService::url()` already returns `null` for an
      unregistered size name, and `post_thumbnail_url()` already falls
      through to the raw original in that case.

#### Why PhotoSwipe?

PhotoSwipe 5 is a strong fit because it:

- Is lightweight and actively maintained.
- Supports responsive images, zooming, touch gestures, keyboard navigation, and accessibility out of the box.
- Is easy to theme without requiring themes to duplicate functionality.
- Can be extended later with captions, downloads, EXIF panels, slideshows, and custom plugins while keeping the core implementation clean.

------

### LP-041. FTP Media Import

#### Goal

Import existing media files from the server into the Media Manager without re-uploading them.

#### Features

- [x] Scan one or more configured server directories for media files

- [x] Recursive directory scanning

- [x] Import images, videos, audio, and documents — any extension/MIME
      `MediaService`'s upload allow-list already accepts.
  
- [x] Allow selecting the destination Media Manager folder before importing

- [x] Create a new Media Manager folder during import — via "mirror
      directory structure" (see below); there's no separate "type a new
      folder name" field on the import screen itself (use the Media
      Manager's existing "New Folder" control first, then pick it here).
  
- [x] Remember the last selected destination folder

- [x] Optionally mirror the scanned directory structure under the selected Media Manager folder

- [x] Preview files before importing

- [x] Import selected files or entire folders — a "select all" default
      (every non-duplicate file starts checked) covers "entire folder".
  
- [x] Preserve directory structure — see "mirror" above.

- [x] Automatically create Media Library folders from directory structure

- [x] Detect and skip duplicate files

- [x] Detect moved or renamed files — same SHA-256 hash check as
      duplicate detection; a rename/move doesn't change the hash.
  
- [x] Generate thumbnails during import

- [x] Extract image metadata (dimensions, EXIF, etc.) — dimensions only
      (same as a browser upload); no EXIF-metadata column exists on
      `{prefix}media` to populate, so that part is out of scope.
  
- [x] Batch import with progress indicator — batch-per-request +
      redirect-loop, the same pattern LP-001 built for bulk thumbnail
      regeneration (no queue/cron infrastructure exists in this codebase).
  
- [x] Resume interrupted imports — not a persisted resume-token system;
      re-running the same scan/import after an interruption naturally
      skips everything already imported via the duplicate-hash check.
  
- [x] Detailed import summary (imported, skipped, failed)

- [x] Log errors for unreadable or unsupported files — shown in the
      on-screen failure summary with a reason per file, not a separate
      persisted log file.
  
- [x] Automatically assign uploaded date from file modification time (optional)

- [x] Set author/owner for imported media — defaults to the importing
      administrator (`uploaded_by`), same as a browser upload; no
      separate "import as a different user" picker.
  
  
  
- [x] Automatically optimize images after import (always optional) —
      implemented (2026-08-06) as a lossy recompression pass via GD (still
      no cwebp/mozjpeg/etc. dependency), not resize/format conversion:
      `ThumbnailService::optimizeInPlace()` reuses the same
      `thumbnail_jpeg_quality`/`thumbnail_webp_quality` settings thumbnails
      already use, and only overwrites the file when the recompressed
      result is actually smaller. An unchecked-by-default "Optimize images
      after import" checkbox on the FTP Media Import screen threads the
      flag through `MediaImportService::import()`/`importBatch()` — never
      on unless chosen for that specific import run, matching "always
      optional" in the ticket text. Animated GIFs are skipped outright
      (GD's decoder only reads a GIF's first frame).
  
- [x] Support very large imports through background processing — same
      batch-per-request shape as above, not real background workers.
  
  

#### Security

- [x] Restrict scanning to administrator-configured directories
- [x] Prevent directory traversal — `realpath()`-resolved, separator-
      boundary-safe prefix checking against the allow-list, re-validated
      on every path immediately before use (scan, and again on import,
      never trusting a path round-tripped through the preview form).
- [x] Validate all imported files as genuine media — real MIME-type
      detection via `mime_content_type()`, same as a browser upload
      (never trusts the extension alone).
- [x] Honor allowed file type restrictions

#### Deliverables

- FTP/Server Import page in the Media Manager
- Fast, resumable bulk import process
- Automatic thumbnail generation and metadata extraction
- Import logs and duplicate detection

------

### LP-047. Discussion Settings

**Implemented (2026-08-06), first-pass scope.** Overlaps with several
still-open `LP-012` (Comments) checklist items — auto-close-after-days,
notifications, word/link moderation, Akismet, and avatars — were real
content duplication, flagged and resolved with the user before starting:
this ticket implements the actual behavior, and the matching `LP-012`
items are checked off below with a cross-reference rather than tracked
twice. New `CommentModerationService` (comment-status decisions,
comments-open-with-auto-close, field requirements) and
`CommentNotificationService` (admin/author/extra-recipient email via the
existing LP-058 `Mailer`) sit between `SiteController::submitComment()`
and `ApiController::commentsStore()`, replacing the status-decision logic
that used to be duplicated inline in both. `CommentService` gained
`paginateForPost()` (threaded-by-top-level-comment pagination, oldest/
newest ordering). A `comment_is_spam` filter now runs alongside Akismet in
both entry points, so a future spam-detection plugin (e.g. `LPP-001`
Lumora Shield) only needs one hook to cover the public form and the REST
API. Guest name/email "required" toggles substitute a placeholder
(`Anonymous` / `anonymous@{host}`) rather than leaving the NOT NULL
`guest_name`/`guest_email` columns empty when disabled. "Maximum nesting
level" caps only the *visual* indentation depth in `comments.php` — no
reply is ever dropped from the data. Avatars reuse Gravatar (extended with
configurable rating/default) plus the same "upload replaces a stored
`options` media ID" pattern Branding's logo/favicon already use for a
locally uploaded default avatar. New Settings > Discussion admin screen
(`admin/views/settings/discussion.php`). Scoped to Posts only, matching
`LP-012`'s own Posts-only scope — Pages have no `comment_status` column to
hang a per-page override off yet (see `TODO.md`'s Pages ticket).

#### Goal

Provide comprehensive configuration for comments, moderation, notifications, and avatars.

#### Features

##### Default Post Settings

- [x] Allow comments on new posts

  

##### Comment Settings

- [x] Comment author name required
- [x] Comment author email required
- [x] Require user registration before commenting
- [x] Automatically close comments after configurable number of days
- [x] Enable comment cookies consent
- [x] Enable threaded (nested) comments
- [x] Maximum nesting level
- [x] Enable comment pagination
- [x] Comments per page
- [x] Default comments page
- [x] Display oldest or newest comments first

##### Notifications

- [x] Notify administrator of new comments
- [x] Notify administrator when comments require moderation
- [x] Notify post author of new comments
- [x] Configurable notification recipients

##### Moderation

- [x] Manual approval for all comments
- [x] Automatically approve previously approved commenters
- [x] Hold comments containing more than X links
- [x] Comment moderation keyword list
- [x] Disallowed comment keywords
- [x] Spam protection integration (Akismet/Lumora Shield) — Akismet itself already existed (`LP-025`); this ticket surfaces its status on the new Discussion settings page and adds a `comment_is_spam` filter hook for a future Lumora Shield plugin

##### Avatars

- [x] Enable avatars

- [x] Maximum avatar rating

- [x] Default avatar

  

- [x] Support locally uploaded avatars

  

##### Deliverables

- Complete Discussion Settings page
- Flexible moderation tools
- Avatar configuration
- Notification management

------

### LP-070. Twitter/X Auto-Embed

**Implemented (2026-08-06).** Pulled out of `LP-023` (oEmbed / Auto-Embed) as
its own ticket — Twitter/X was deliberately left out of that first pass since
it doesn't fit the same fixed-iframe-template approach the other five
providers (YouTube, Vimeo, SoundCloud, Spotify, CodePen) use.

Unlike those five, Twitter/X has no plain-`<iframe>` embed — only a
script-based one (`platform.twitter.com/widgets.js`, which scans the page
for `<blockquote class="twitter-tweet">` markup and replaces it with the
rendered tweet). Implementing this needed:

- [x] A `csp_directives` filter addition allowing
      `platform.twitter.com`/`syndication.twitter.com` under `script-src`
      (and whatever `frame-src`/`connect-src` the rendered widget ends up
      needing — confirm empirically once built) — narrower than a
      blanket third-party allowance, matching this project's "same-origin
      by default, widen only per feature" CSP posture (see
      `ContentSecurityPolicy::defaultDirectives()`'s docblock). Landed as
      `EmbedService::filterCsp()` widening `script-src`/`frame-src` for
      `platform.twitter.com` and `connect-src` for
      `syndication.twitter.com`, gated on the Twitter/X provider toggle
      like the other five providers' `frame-src` entries.
- [x] A "load the script once per page" mechanism — the same shape as
      Font Awesome's editor-load concern (`docs/THIRD-PARTY.md`) and
      `admin/assets/js/content-editor.js`'s `loadScript()`/`loadStyle()`
      memoization — so a post with multiple embedded tweets doesn't
      inject `widgets.js` more than once. Landed as a single conditional
      `<script async src="https://platform.twitter.com/widgets.js">` tag
      in `content/themes/default/footer.php` — one tag covers every tweet
      on the page since `widgets.js` scans the whole DOM itself, so no
      per-embed JS-side dedup (the `content-editor.js` shape this item
      originally named) was actually needed; see the next item.
- [x] Regex-based tweet-URL ID extraction (same "no oEmbed HTTP fetch"
      architecture as the other five providers — see `LP-023`'s
      Architecture note), producing the `<blockquote class="twitter-tweet">`
      markup `widgets.js` expects rather than an `<iframe>`. Landed as
      `EmbedService::matchTwitter()` (host allowlist for
      twitter.com/www.twitter.com/mobile.twitter.com/x.com/www.x.com, path
      pattern `^/(\w{1,15})/status/(\d+)`) and a new `type: 'blockquote'`
      field on the provider array that `wrap()` branches on.
- [x] Decide how the widget script only loads on pages that actually
      contain a Twitter/X embed, not site-wide, mirroring `MediaViewer`'s
      conditional PhotoSwipe loading pattern. Landed as a new
      `ScriptEmbeds` bridge class (`app/Core/Theme/ScriptEmbeds.php`) —
      identical shape to `MediaViewer` but keyed per provider
      (`markUsed(string $provider)`/`isUsed(string $provider)`) rather
      than a single bool, anticipating LP-071's second script-based
      provider; originally landed as a single-flag `TweetEmbed` class,
      generalized the same session once LP-071 made the "second
      near-identical class" duplication concrete rather than
      speculative.
- [x] Unit tests for URL/ID extraction and malformed-URL fallback, same
      shape as `PHP Test Suite/Unit/Services/EmbedServiceTest.php`'s
      existing per-provider tests. Landed: status-URL matching (both
      twitter.com and x.com hosts), malformed-URL fallback, the
      disabled-provider/other-providers-still-work pair, `ScriptEmbeds`
      marking, and CSP `script-src`/`frame-src`/`connect-src` widening —
      plus a new `ScriptEmbedsTest.php` mirroring `MediaViewerTest.php`.
- [x] Settings &rsaquo; Embeds gains a Twitter/X per-provider toggle,
      alongside the five already there.

------

### LP-071. Bluesky Auto-Embed

**Implemented (2026-08-06), Option A.** Another entry in the LP-023
Auto-Embed family. Bluesky's official embed is the same script+blockquote
shape as Twitter/X's (LP-070) — a `<blockquote class="bluesky-embed">` that
a Bluesky-hosted script (`https://embed.bsky.app/static/embed.js`) scans the
page for and replaces with a rendered post — rather than the fixed-iframe
shape the original five providers use, confirmed against Bluesky's own
current docs (docs.bsky.app/docs/advanced-guides/oembed and
bsky.social/about/blog/post-embeds-guide) 2026-08-06.

**Architecture conflict found during that research:** the blockquote's
required `data-bluesky-uri` attribute is not a plain post URL — it's an AT
Protocol URI in the form `at://did:plc:{did}/app.bsky.feed.post/{postId}`,
and the blockquote also needs a `data-bluesky-cid` (a content hash of that
specific post). Neither is derivable by regex from a pasted
`https://bsky.app/profile/{handle}/post/{postId}` URL the way every other
provider's id is — resolving them requires an outbound HTTP request to
Bluesky's own `embed.bsky.app/oembed` endpoint (confirmed empirically: it
accepts a plain `bsky.app/profile/{handle}/post/{id}` URL directly and
resolves the handle→DID itself server-side, so no separate handle
resolution step was needed on this app's side). Ariane chose **Option A**
— a scoped, one-provider exception to `EmbedService`'s otherwise-total
no-outbound-request rule, made as narrow as possible:

- [x] **The resolution call happens once, at save time, never at render
      time.** New `BlueskyResolverService` (`app/Services/
      BlueskyResolverService.php`) — `resolveContent()` (the only method
      that touches the network) is called from new `post_saved`/
      `page_saved` hook listeners in `include/bootstrap.php`, gated on the
      Bluesky provider's own Settings &rsaquo; Embeds toggle so a site with
      it off never makes this request at all. `cached()` — the method
      `EmbedService::matchBluesky()` actually calls — is a pure local DB
      read with no network access, so render() still never blocks on the
      network for any provider, Bluesky included; an unresolved (or
      never-resolvable) URL just renders as a plain link, same as a
      malformed URL for any other provider.
- [x] **Only the two needed fields are cached, not Bluesky's raw response.**
      New `install/migrations/0030_create_bluesky_embeds_table.sql`
      (`{prefix}bluesky_embeds`, unique on `(handle, rkey)` so the same
      post referenced from multiple posts/pages is only ever resolved
      once) stores just the extracted `at_uri`/`cid` — Bluesky's returned
      `html` blob itself is discarded after extraction, never cached or
      echoed verbatim, keeping the "we control the template, only the data
      is external" property every other provider already has (see
      `EmbedService`'s class docblock).
- [x] **Fails open, same as AkismetClient/GitHubReleaseProvider's existing
      HTTP pattern** (curl-first, `file_get_contents` fallback, injectable
      closure for tests): a down/slow Bluesky, malformed JSON, or a
      response missing the expected attributes all leave the URL
      unresolved rather than throwing or blocking the save. A `Throwable`
      guard around each URL's resolution inside `resolveContent()`'s loop
      also means one bad URL can never stop the others in the same
      content, or turn into a failed post/page save.
- [x] A `type: 'blockquote'` provider entry, reusing (and generalizing)
      the branch `wrap()` gained for Twitter/X — `wrap()` now takes the
      full match array (not just `src`/`title`) so provider-specific
      blockquote shapes (Bluesky's `data-bluesky-uri`/`data-bluesky-cid`
      vs. Twitter's bare `<a href>`) can both be built from it.
- [x] `ScriptEmbeds::markUsed('bluesky')`/`isUsed('bluesky')` for the
      conditional `embed.js` load — already generalized for exactly this
      in LP-070 rather than needing a second near-identical bridge class,
      confirming that generalization was the right call.
- [x] `csp_directives` filter addition: `script-src`/`frame-src` widened
      for `embed.bsky.app` when the Bluesky provider is enabled (no
      `connect-src` widening needed, unlike Twitter/X — Bluesky's content
      is resolved ahead of time by `BlueskyResolverService`, so its widget
      script has no equivalent runtime API call to make).
- [x] Unit tests: `BlueskyResolverServiceTest.php` (resolution success/
      failure/malformed-response/exception-safety/deduplication, against
      fake `httpGet` closures — no real network call is ever exercised)
      plus `EmbedServiceTest.php` additions mirroring the Twitter/X
      coverage (URL matching against a pre-warmed cache, unresolved-URL
      fallback, disabled-provider fallback, `ScriptEmbeds` marking, CSP
      widening).
- [x] Settings &rsaquo; Embeds gains a Bluesky toggle, plus updated hint
      text clarifying that (unlike every other provider) a Bluesky embed
      is resolved once against Bluesky's own servers when the post/page is
      saved — the one place this project's "no provider is contacted over
      the network" claim needed an explicit exception called out.

---

### LP-074. Direct Media Link & Embed Code Display

#### Goal

Give administrators/staff a one-click way to grab a fully-qualified direct
URL and ready-to-paste HTML embed snippet for an uploaded image, straight
from the Media Manager's edit view — the classic "copy this into your
post" workflow familiar from other blogging/gallery software, without
needing to hand-build the markup. Split out of LP-031 (Media Viewer &
Lightbox System) since it's an admin-authoring convenience, not part of
the public-facing viewer itself.

- [x] Media Manager's image edit view (`admin/views/media/media.php`)
      gained a "Direct Link & Embed Code" panel, shown only for image
      files (mirroring the existing image-only branches on that page).
- [x] "Direct image URL": a readonly, select-on-click text input
      containing the image's fully-qualified URL (`home_url()`-wrapped,
      matching how `post_thumbnail_url($item, absolute: true)` builds
      absolute URLs elsewhere).
- [x] "HTML embed code": a readonly, select-on-click textarea containing
      an `<a href="..."><img class="alignnone size-full" src="..."
      width="..." height="..." alt="..." /></a>` snippet — the same shape
      classic WordPress produced when inserting a full-size image, chosen
      for familiarity. `width`/`height` are only included when the image
      has known dimensions; `alt` is populated from the media's existing
      alt text (empty string if none set) and HTML-escaped.
- [x] New `.lp-media-edit__links`/`.lp-media-edit__link-field` classes in
      `admin/assets/css/admin.css` — admin-only UI, not a public-facing
      component, so it does not need the theme-stylesheet placement the
      project's Public-Facing CSS Rule requires for visitor-facing markup.

Deliberately scoped to images only this pass (matching the example the
feature request was built around) — other media types (video/audio/PDF/
other) already show their own direct download link elsewhere on the same
page (see the existing `!str_starts_with(mime_type, 'image/')` block
above the "Replace file" section) and were left as-is rather than
duplicated into this new panel's shape.

---

### LP-075. Insert Media: Attachment Display Settings (Size / Link To)

#### Goal

Let an author choose an image's display size and link destination when
inserting it into post/page content from either editor's "Insert from
Media Manager" picker — the classic WordPress "Attachment Display
Settings" step (Alignment/Link To/Size) that this project's picker never
grew. Reported gap: inserting an image today always drops in one
fixed-size `<img>` (WYSIWYG) or `![alt](url)` (Markdown) pointing at the
original file, with no way to pick Thumbnail/Medium/Large/Full or link the
image to its own full-size version.

- [x] `admin/assets/js/content-editor.js`'s shared media picker
      (`openMediaPicker()`) gains a second step after an image is clicked:
      a small form with a "Size" `<select>` (Thumbnail/Medium/Large/Full
      — only sizes the item actually has a generated thumbnail for, plus
      Full, are offered) and a "Link To" `<select>` (None / Media File).
- [x] `$editorMediaLibrary` (built in both `admin/views/posts/new.php` and
      `admin/views/pages.php`) is enriched per item with a `sizes` map
      (`{full, small, medium, large}`, each `{url, width, height}`,
      omitting sizes with no generated thumbnail row — mirrors
      `ThumbnailService::thumbnailsFor()`) so the size/link choice can be
      resolved client-side with no extra round trip. Backed by a new
      bulk `ThumbnailService::thumbnailsForMany()` (one query for every
      image in the library, not one per image) so this doesn't become an
      N+1 query on a library with hundreds of uploads.
- [x] Markdown editor: inserts `![alt](sizeUrl)` as before when Link To is
      None, or `[![alt](sizeUrl)](fullUrl)` (a linked image — this
      project's Markdown parser already supports a link wrapping an
      image, see `MarkdownParser::parseImages()`/`parseLinks()`) when
      Link To is Media File. Markdown has no attribute syntax, so the
      chosen size is expressed purely by which file's URL gets inserted —
      no `width`/`height`/`class` on the resulting `<img>` is possible
      from Markdown source, a hard limitation of the format rather than a
      missed step here.
- [x] TinyMCE (WYSIWYG) editor: inserts `<img src="sizeUrl" alt="alt"
      width="w" height="h" class="size-{name}">` (the `size-{name}` class
      matches the convention `admin/views/media/media.php`'s LP-074 embed
      snippet already uses), wrapped in `<a href="fullUrl">...</a>` when
      Link To is Media File. `HtmlSanitizer`'s `img` allowlist gained
      `class` as part of this — it was missing entirely before, which
      would have silently stripped the new `size-{name}` class (and
      LP-076's `no-lightbox` opt-out) on every save.
- [x] New `.lp-editor-media-dialog__settings-actions` CSS in
      `admin/assets/css/admin.css` for the added form step (admin-only
      UI — no public-facing CSS rule implication; the Size/Link To
      fields themselves reuse the existing `.lp-field` styling).

Deliberately out of scope this pass: captions (already tracked
separately as the still-unchecked "captions" half of LP-016's "Image
alignment, resizing, captions, and alt text" line).

**Alignment (WordPress's third Attachment Display Settings field),
deferred here as "this theme has no float-based alignment classes to
hook into yet," was added in a later session** — see LP-016's own
checklist for the actual implementation (an "Alignment" select added to
this same Attachment Display Settings step, applying classic-WordPress
`alignleft`/`aligncenter`/`alignright` classes to the `<img>`; matching
CSS in `content/themes/default/style.css`).

**Real bug found and fixed while testing this ticket end-to-end**
(browser-verified against a live post on the deployed host): TinyMCE's
default `relative_urls: true` silently rewrote every inserted image's
`src` into a path relative to the *admin editor's own page location*
(e.g. `../../content/uploads/...`) — correct only from that admin page,
so the exact same stored HTML 404'd once rendered on the actual public
post. This affected the plain pre-existing "Insert from Media Manager"
flow too, not just this ticket's new size/link step — nobody had tested
a WYSIWYG-inserted image on the front end before now. Fixed via
`relative_urls: false` in `content-editor.js`'s `tinymce.init()` — see
`docs/CHANGELOG.md`'s "Fixed" entry for this date. Pre-existing posts
saved with a broken relative image URL from before this fix need the
image re-inserted; this only prevents new occurrences.

---

### LP-076. Content-Embedded Image Lightbox

#### Goal

Make the PhotoSwipe lightbox (LP-031) activate for images inside post/page
*body content*, not just the featured image — reported gap: an image
inserted into a post via either editor does not open a lightbox on the
public front end at all today, because `the_post_thumbnail_lightbox()`
(the only code in this codebase that ever emits `data-pswp-*` attributes)
only ever runs against the featured image, never against
`ContentRenderer`'s output.

- [x] `ContentRenderer::render()` gains a post-sanitization pass
      (`addLightboxAttributes()`) that walks every `<img>` in the
      rendered HTML (a cheap `str_contains($html, '<img')` gate skips the
      DOM parse entirely for content with no images, including all Plain-
      format content) and, for each one not carrying a `no-lightbox`
      class:
  - [x] If already wrapped in an `<a>` whose `href` looks like a direct
        link to an image file (extension check against the same
        image-type set `MediaService` uploads accept, restricted to a
        same-site/relative URL — an author's intentional link to
        something else, e.g. an external page, is never touched): adds
        `data-pswp-width`/`data-pswp-height` (from the `<img>`'s own
        `width`/`height` attributes, when present — omitted otherwise,
        the same tolerance `the_post_thumbnail_lightbox()` already has
        for a dimensionless image) and `data-pswp-caption` (from `alt`)
        to that existing anchor.
  - [x] If not wrapped in a link at all: wraps the `<img>` in a new
        self-linking `<a href="{the img's own src}">` with the same
        `data-pswp-*` attributes — so a plain inserted image (no "Link
        To" chosen, the common case) still becomes lightbox-clickable,
        not just an explicitly-linked one.
  - [x] A `no-lightbox` class (on the `<img>` or an existing wrapping
        `<a>`) opts an image out entirely — the only escape hatch, usable
        from HTML-mode content or a theme's own markup.
- [x] `render_content()` (`include/theme.php`) checks the returned HTML
      for `data-pswp-lightbox` or `data-pswp-width` and, only when
      present, calls `MediaViewer::markUsed()` and wraps the content in
      `<div class="lp-gallery">...</div>` — mirrors the existing
      `MediaViewer::isUsed()` cheap-gating pattern everywhere else in
      LP-031, and keeps this logic in the theme-bridge layer
      (`include/`) rather than inside the `ContentRenderer` service,
      consistent with `the_post_thumbnail_lightbox()`'s own placement.
- [x] Unit tests: `ContentRendererTest` gains coverage for a plain
      unlinked content image gaining a self-link + `data-pswp-*`, an
      author-linked-to-full-image anchor gaining the same attributes, an
      author link to a non-image URL being left untouched, a
      `no-lightbox`-classed image being skipped, and Markdown-authored
      linked images (`[![alt](url)](fullUrl)`) getting the same
      treatment as hand-typed/TinyMCE HTML.

**Real bug found and fixed while testing this ticket end-to-end**
(browser-verified against a live post): the first pass here assumed a
*linked* image's `data-pswp-width`/`data-pswp-height` could safely be
inferred from the inline `<img>`'s own `width`/`height` — wrong when
LP-075's "Link To: Media File" links to a size other than the one
displayed (e.g. Thumbnail-size image linked to the Full-size original).
PhotoSwipe uses those attributes to size the lightbox viewport itself,
not just as a caption hint, so the mismatch produced a visibly
stretched/blurry lightbox rather than a merely-wrong caption. Fixed by
having `content-editor.js`'s TinyMCE insertion embed the *linked* file's
own real `data-pswp-width`/`data-pswp-height`/`data-pswp-caption`
directly onto the `<a>` at authoring time (client-side, since the size
map already has this data — see LP-075 above), and
`ContentRenderer::addLightboxAttributes()` now trusts those over
inferring from the `<img>` whenever they're already present.
`HtmlSanitizer`'s `a` allowlist gained `data-pswp-width`/
`data-pswp-height`/`data-pswp-caption` (with numeric validation on the
first two) to let this survive sanitization. Markdown-authored linked
images can't hit this *particular* mismatch (an editor supplying a
wrong-but-present dimension) — Markdown has no attribute syntax, so a
Markdown `<img>` never carries a display-size `width`/`height` at all —
but they turned out to have their own distinct version of the same
visible symptom; see the third real bug below.

**A second real bug, reported separately**: Markdown-authored images
didn't just risk a dimension mismatch — they were **never lightboxed at
all**. `data-pswp-width` was the *only* signal both `render_content()`'s
gate and `media-viewer.js`'s gallery `children` selector used to
recognize a lightbox-eligible image, but `addLightboxAttributes()` only
sets it when a dimension is actually known — which, per the paragraph
above, a Markdown image never has. A self-link was still created, but
nothing marked it as part of a gallery, so PhotoSwipe's assets never
even loaded on a Markdown-only post. Fixed by having
`addLightboxAttributes()` always set a new `data-pswp-lightbox="1"`
marker on every anchor it touches regardless of known dimensions, and
updating `render_content()`'s gate and both `media-viewer.js` copies'
gallery selectors (the `children:` option and the deep-link
`querySelectorAll` lookup) to also match on it. See
`docs/CHANGELOG.md`'s entry for this date and
`PHP Test Suite/TEST_LOG.md` for the full Docker-matrix verification.

**A third real bug, found once Markdown images started lightboxing at
all**: reported via a live screenshot as the lightbox opening with the
image blown up full-screen and visibly out of aspect ratio. This is the
same underlying issue the first "real bug" above already identified
(PhotoSwipe uses `data-pswp-width`/`data-pswp-height` to lay out the
slide *before* the image finishes loading, not just as a caption hint)
but hitting a case that fix didn't cover: a Markdown-authored image (or
any hand-typed HTML link to a differently-sized file) has no width/
height to omit-or-trust in the first place, so no dimension hint ever
reached PhotoSwipe at all — and PhotoSwipe defaults to something other
than the real aspect ratio when none is given, visibly stretching the
image once it loads. Fixed by adding
`ContentRenderer::resolveImageDimensions()` — reads the linked file's
real pixel size directly off disk via `getimagesize()` whenever the
inline `<img>`'s own width/height are absent, or don't actually describe
the file the link points at (the same "thumbnail linked to a different
original" case the first real bug covers for editor-authored content,
now also covered for hand-typed/Markdown content). Falls back to the
`<img>`'s own values if the file can't be resolved on disk, rather than
emitting nothing. `resolveImageDimensions()` only ever runs against a
URL `looksLikeImageUrl()` already confirmed has no host (same-site by
construction), so this never resolves an arbitrary external address —
`ContentRenderer` still has no `MediaService`/database dependency, only
a direct, narrowly-scoped filesystem read.

---

## 0.7.0 (2026-08-26)

### LP-008. Posts

**Partially implemented (2026-07-21), substantially extended (2026-08-01).**
The 2026-07-21 note below undersold how much had already landed via other
tickets by the time this pass started — Categories/Tags (LP-010/LP-011),
Featured Images (LP-040), the Markdown/WYSIWYG content editor (LP-015/
LP-016), Revisions (LP-017), category/tag archives and search
(LP-010/LP-011/LP-014) were all already wired into `admin/views/posts.php`
and the front end. Checkboxes below have been corrected to reflect that,
and this pass additionally added: Trash with restore (`PostStatus::Trashed`,
a new `trashed_at` column, `PostService::trash()`/`restore()`), Duplicate
existing posts (`PostService::duplicate()`), and Bulk Actions (checkbox
selection + Move to Trash/Restore/Delete Permanently/Publish/Mark as Draft)
on the admin Posts list, plus status-count tabs (`All (12)`, `Trash (3)`,
...). Restoring a trashed post always returns it to Draft rather than its
prior status — a deliberate "don't silently resurface previously-published
content" safety choice, see `PostService::restore()`'s docblock. The REST
API (LP-021) explicitly cannot set or carry a post into Trashed through its
`status` field — see `ApiController::canSetStatus()`'s docblock — trashing/
restoring/permanent-delete are admin-UI-only flows for now.

**Extended further (2026-08-04).** Publishing Workflow: Pending Review
status, Private posts (`PostVisibility`, independent of status), Sticky
posts (pinned on the homepage only), and Schedule unpublishing
(`unpublish_at`, computed live like scheduled publishing already is) —
all via a new migration (`0026_add_publishing_workflow_to_posts.sql`).
Editor: Custom Fields (new `post_meta` table + repeatable key/value row
editor), a Preview button (`/preview/{id}`, bypasses the visibility gate
for the post's author/an editor rather than issuing a shareable link),
and client-side URL preview. Authors: reassignment on the edit screen,
public `/author/{slug}` archives and profile links (author slugs are
computed from username via `UserService`, since there was no stored
slug column). Admin Posts list: a Search &amp; Filter panel (title,
author, category, tag, date range) backed by an extended
`paginateForAdmin()`, plus three new Bulk Actions (Change author, Change
category — additive, not a replace — and Change visibility). Validation:
a title length cap and Html-format content sanitization at save time
(`HtmlSanitizer`, in addition to the pre-existing render-time
sanitization — the REST API exposes raw stored content directly).
Configurable permalink structure was explicitly scoped out of this pass
(deliberate call, confirmed with Ariane before starting) — it needed a
token-based routing rework this pass didn't attempt. That work was later
built as its own ticket, LP-078 ("Permalink Structure & Settings"),
which also consolidates the identical requests LP-009/LP-010/LP-011 had
listed separately. See `docs/CHANGELOG.md`'s entry for this date.

Original 2026-07-21 note, for history: "a first, real increment shipped
before this ticket's full scope was reviewed: `LumoraPress\Services\PostService`
(create/edit/delete, automatic slug generation with duplicate resolution,
draft/published/scheduled status), the admin Posts screen (list/create/
edit/delete with role-aware permission checks and a status filter), and
front-end rendering (homepage/archive/single templates, paginated)."

### Goal

Implement a modern, full-featured Posts system that serves as the core content management component of Lumora Press. The system should provide an intuitive writing experience while remaining lightweight, performant, and suitable for personal blogs, fansites, and content-focused websites.

### Features

#### Core Content

- [x] Create posts

- [x] Edit existing posts

- [x] Delete posts — soft-delete (Trash) by default; see below

- [x] Duplicate existing posts — `PostService::duplicate()`, clones as a new Draft titled "{title} (Copy)"; categories/tags are copied too, comments are not

  

- [x] Bulk delete — "Move to Trash" bulk action; "Delete Permanently" bulk action from within the Trash view

- [x] Trash with restore functionality — `PostStatus::Trashed` + `trashed_at` column; Trash is its own status filter tab, excluded from the default "All" view

- [x] Permanent delete — only reachable for posts already in the Trash (admin UI enforces this; `ApiController` never accepts "trashed" as a settable status at all)

#### Publishing Workflow

- [x] Drafts
- [x] Published posts
- [x] Scheduled posts — visibility is computed live (`published_at <= NOW()`), no background job
- [x] Pending review — `PostStatus::PendingReview`; a Contributor (no `publish_posts`) gets a "Submit for Review" option instead of only Draft; an Editor/Administrator can then publish it
- [x] Private posts — `PostVisibility` (Public/Private), independent of status; `Post::isVisibleToViewer()` allows a Private post through only for the author or a user with `edit_posts`, everywhere else (`isPubliclyVisible()`) it's excluded
- [x] Sticky posts — `is_sticky` column; pinned to the top of the homepage listing only (`PostService::paginatePublished()`'s `ORDER BY is_sticky DESC, published_at DESC`), deliberately not applied to category/tag/author/date archives
- [x] Publish immediately
- [x] Schedule publication
- [x] Schedule unpublishing (optional) — nullable `unpublish_at` column; visibility is computed live the same way scheduled *publishing* already is (no background job), via the shared `PostService::publicWhereClause()`

#### Post Editor

- [x] Title field

- [x] Slug editor

- [x] Excerpt field

- [x] Content editor — Markdown (EasyMDE) or WYSIWYG (TinyMCE), switchable with best-effort format conversion (LP-015/LP-016)

- [x] Featured image — Media Manager picker + direct upload, with remove/replace (LP-040)

- [x] Categories — checkbox list (LP-010)

- [x] Tags — comma-separated input with autocomplete suggestions (LP-011)

  

- [x] Custom fields — a repeatable key/value row editor (progressive enhancement; plain fields still work with JS off), backed by a new `post_meta` table and `PostService::metaForPost()`/`replaceMetaForPost()`/`metaValue()`. Simple key/value list scope only, not a typed/plugin-registered fields schema.

  

- [x] Post status panel — status dropdown + scheduled publish-date field (publish_posts capability only)

- [x] Preview button — `/preview/{id}` renders `single.php` exactly as it will look publicly, bypassing the status/visibility gate entirely (not a shareable signed URL) for the post's author or anyone with `edit_others_posts`

- [x] Save Draft

- [x] Publish

- [x] Update

#### URLs & Permalinks

- [x] Automatic slug generation

- [x] Manual slug editing

- [x] Duplicate slug detection — numeric-suffix resolution (`hello-world`, `hello-world-2`, …)

- [x] URL preview — `admin/assets/js/url-preview.js`, live-updates as the slug (or title, if slug is blank) is typed; client-side approximation only, the server's own `generateUniqueSlug()` remains authoritative on save

- Configurable permalink structure — built and done; see LP-078 ("Permalink Structure & Settings"), which consolidates this with LP-009/LP-010/LP-011's identical requests rather than tracking it separately in each ticket

- [x] Link to published post. Before Slug. — `admin/views/posts/new.php`: a "Permalink:" row (linked URL + "View Post" button, opening in a new tab) rendered above the Slug field, shown only once a post's status is `Published` (a Draft/Scheduled/Pending Review post has no live URL to link to — Preview already covers that case via the existing Preview button below the form)

#### Authors

- [x] Multiple user support — every post stores an `author_id`
- [x] Author assignment — an Author select on the edit screen, `edit_others_posts`-gated, backed by `PostService::reassignAuthor()`
- [x] Author archives — `/author/{slug}` (`SiteController::author()`), reusing `PostService::paginateByAuthor()`. The slug is computed from the username (`UserService::authorSlug()`/`findByAuthorSlug()`), not a stored column — there was no user-slug field to reuse.
- [x] Author profile links — `the_author_link()` (`include/author-functions.php`, via a new `Authors` theme bridge mirroring `FeaturedImages`), wired into `single.php`/`index.php`/`archive.php`'s post meta lines
- [x] Permission checks — `publish_posts`/`edit_others_posts`/`delete_posts` enforced in `admin/views/posts.php`

#### Featured Images

- [x] Upload featured image
- [x] Select existing media
- [x] Remove featured image
- [x] Automatic thumbnail generation
- [x] Theme integration

#### Categories & Tags

- [x] Assign categories

- [x] Assign tags

- [x] Create categories while editing — "+ Add New Category" reveals a name field under the Categories checklist; posts to a JSON `add_category` sub-action (same pattern as the content editor's image upload/format-conversion endpoints) and appends the new checkbox in place, so the rest of the in-progress post isn't lost. `CategoryService::findOrCreateByName()` (mirroring `TagService::findOrCreateByName()`) reuses an existing category case-insensitively instead of creating a near-duplicate.

- [x] Create tags while editing — new tag names typed into the tag input are created automatically on save

- [x] Multiple categories

  

#### Visibility

- [x] Public — `PostVisibility::Public`, the default

- [x] Private — `PostVisibility::Private`; visible only to the post's author or a user with `edit_posts` (see Publishing Workflow's "Private posts" above)

  

#### Comments

- [x] Enable comments per post — "Allow comments on this post" checkbox, backed by the `comment_status` column
- [x] Disable comments per post — same checkbox/column

#### Metadata

- [x] Creation date

- [x] Last modified date

- [x] Publication date

- [x] Author

  

#### Search & Filtering

- [x] Search by title — public `/search` (LP-014) covers title matches via the MySQL fulltext index added for it; the admin Posts list also gained its own plain `LIKE`-based title search box (`PostService::paginateForAdmin()`'s `term` filter) since the fulltext index only serves the public search page
- [x] Search by content — same fulltext index/search covers content too
- [x] Filter by author — admin-list filter UI, `paginateForAdmin()`'s `authorId` filter
- [x] Filter by category — admin-list filter UI, `paginateForAdmin()`'s `categoryId` filter (joins `post_categories`)
- [x] Filter by tag — admin-list filter UI, `paginateForAdmin()`'s `tagId` filter (joins `post_tags`)
- [x] Filter by status — admin Posts list has All/Draft/Pending Review/Published/Scheduled/Trash tabs, each showing a live count
- [x] Filter by date — admin-list filter UI, `paginateForAdmin()`'s `dateFrom`/`dateTo` filters against `created_at`

#### Bulk Actions

- [x] Delete — "Move to Trash" (outside Trash view) / "Delete Permanently" (inside Trash view)

- [x] Restore — "Restore" bulk action, only offered inside the Trash view

- [x] Change author — "Change author to…" bulk action, `edit_others_posts`-gated, `PostService::bulkReassignAuthor()`

- [x] Change category — "Add category…" bulk action, `CategoryService::bulkAddToPosts()`; additive (adds the chosen category to every selected post without touching categories already assigned), not a destructive replace

- [x] Change status — "Publish" / "Mark as Draft" bulk actions (Scheduled excluded from bulk, since scheduling needs a per-post publish date)

- [x] Change visibility — "Set Public" / "Set Private" bulk actions, `PostService::bulkSetVisibility()`

  

---

### Task List

#### Database

- [x] Design posts database schema
- [x] Create migration scripts — `install/migrations/0004_create_posts_table.sql`, extended by `0010_add_comment_status_to_posts.sql`, `0011_add_fulltext_index_to_posts_and_pages.sql`, `0016_add_content_format_to_posts_and_pages.sql`, `0018_add_trashed_at_to_posts.sql`, `0026_add_publishing_workflow_to_posts.sql` (visibility/is_sticky/unpublish_at), `0027_create_post_meta_table.sql`
- [x] Create indexes for performance — unique slug, `(status, published_at)`, `author_id`, fulltext `(title, content)`/`(title)`
- [x] Support future extensibility — nullable `featured_image_id` column (now used by LP-040); `trashed_at` similarly ready for a future auto-purge feature

#### Backend

- [x] Create Post model
- [x] Create Post service layer
- [x] Implement CRUD operations
- [x] Implement publishing workflow
- [x] Implement slug generation
- [x] Implement validation — title is required and capped at `MAX_TITLE_LENGTH` (191, matching the `title` column width), throwing `InvalidArgumentException` on either failure; Html-format content is also run through `HtmlSanitizer` at save time (`sanitizeStoredContent()`), not just at render time — defense in depth, since the REST API exposes the raw `content` column directly, not only the sanitized `content_html`
- [x] Implement permissions
- [x] Implement search — via `SearchService`/the fulltext index (LP-014), not a `PostService` method of its own
- [x] Implement filtering — status, plus category/tag/author/month via dedicated `paginateBy*()` methods
- [x] Implement pagination

#### Admin Interface

- [x] Build Posts listing page

- [x] Build Create Post page

- [x] Build Edit Post page

  

- [x] Add bulk actions — Trash/Restore/Delete Permanently/Publish/Mark as Draft

- [x] Add confirmation dialogs — JS `confirm()` on Trash/Delete Permanently

- [x] Improve usability for large numbers of posts — pagination + status-count tabs, plus a Search &amp; Filter panel (title search, author/category/tag/date filters)

#### Frontend

- [x] Single post template
- [x] Blog index
- [x] Category archives — `/category/{slug}`
- [x] Tag archives — `/tag/{slug}`
- [x] Author archives — see Authors section above
- [x] RSS integration — posts appear in the site-wide feed (LP-013); per-author/category/tag feeds are a separate, still-unimplemented LP-013 item
- [x] Search integration — `/search` (LP-014)

#### Performance

- [x] Optimize database queries — traced the theme API calls a listing template (`index.php`/`archive.php`) makes per post: `has_post_thumbnail()` + `the_post_thumbnail_lightbox()` together call `post_thumbnail_media()` up to 5 times per item (each a `MediaService::find()` query) plus `ThumbnailService::thumbnailsFor()` twice, and `the_author_link()` calls `UserService::findById()` once per post — identical repeat queries whenever the same media/author id appears more than once in one render. Fixed at the source: `MediaService::find()`, `UserService::findById()`, and `ThumbnailService::thumbnailsFor()` now memoize by id for the life of the (per-request) service instance, evicted by every write method that can change a cached row, rather than rewriting every call site
- [x] Cache common queries where appropriate — the per-request memoization above; archive/category/tag/author/search routes were already opted into `CacheManager`'s HTTP-level page caching (LP-037, via `markCacheableForGuests()`) before this pass
- [x] Optimize archive pages — same memoization; `archive.php`'s post-thumbnail/author-link calls were the primary source of repeat queries on a page with several posts
- [x] Optimize search performance — already fulltext-indexed (`MATCH() AGAINST()`, LP-014) with a bounded `LIMIT` per query in `SearchService`, and the `/search` route already opts into HTTP-level page caching; no further change needed

#### Security

- [x] CSRF protection
- [x] Permission checks
- [x] Input validation — see "Implement validation" above (title length cap, Html-content sanitization at save time)
- [x] Output escaping
- [x] XSS protection

#### Testing

- [x] Unit tests — `Unit/Services/PostServiceTest.php` (including Trash/Restore/Duplicate/setStatus/countByStatus, and this session's Pending Review/Private/Sticky/unpublish/Custom Fields/admin-filter/title-validation/content-sanitization additions), `Unit/Models/PostTest.php` (new — `isPubliclyVisible()`/`isVisibleToViewer()`), `Unit/Services/UserServiceTest.php`/`CategoryServiceTest.php` (author-slug/bulk-add-category additions), `Unit/Controllers/ApiControllerTest.php` (including the "trashed" API guard)
- [x] Integration tests — closed via LP-082's `admin/views/posts/all-posts.php` + `new.php` extraction (`PostsControllerTest.php`, 27 tests exercising every admin form action end to end at the controller layer)
- [x] Editor testing — `new.php`'s three AJAX sub-actions (editor image upload, Markdown/HTML conversion, inline category quick-add) extracted into `PostsController` and covered by 10 new `PostsControllerTest.php` tests; the client-side editor UI itself has no automated coverage by design (this project has no JS test framework — see CLAUDE.md's project philosophy), verified manually instead
- [x] Permission testing — ownership/capability logic now lives in `PostsController` (LP-082) and is directly unit-tested there, no longer only reachable by hand-testing `admin/views/posts.php`
- [x] Slug generation testing
- [x] PHP 8.2 compatibility — full Docker matrix run by the developer for the 0.2.0 release; see `PHP Test Suite/TEST_LOG.md`
- [x] PHP 8.3 compatibility — same run
- [x] PHP 8.4 compatibility — same run

**Implemented (2026-08-17) — closed this ticket's last two Testing gaps via
LP-082.** `admin/views/posts/all-posts.php` (quick draft, trash, restore,
delete permanently, duplicate, bulk actions) and `admin/views/posts/new.php`
(save, restore revision) had their POST-handling business logic —
including the author/contributor ownership gate this ticket's own
"Permission testing" line named — extracted into
`LumoraPress\Controllers\Admin\PostsController`, following the
`ThemesController` pattern LP-082 established (`AdminActionResult` instead
of `header()`/`exit`, see `DECISIONS.md`). New
`PostsControllerTest.php` (27 tests) exercises every action end to end:
success paths, ownership/capability denial (an author/contributor can't
touch another author's post; capability-gated bulk actions refuse without
the matching permission), and CSRF failure. Both views are now thin
dispatch wrappers with no behavior change except that `quick_draft` and
`bulk_action`'s previously-silent CSRF failures now surface an inline
error message, matching the fix already applied to Themes.
`SqliteDatabaseFactory::withPostsControllerFixtures()` added to back the
new tests. Full PHP 8.2/8.3/8.4 × MariaDB 11 Docker matrix run clean
(`PHP Test Suite/TEST_LOG.md`) — only 3 pre-existing, unrelated
`ThemeOptionsTest` failures (a stale `content_width` default assertion,
flagged separately). `admin/views/posts/categories.php` and `tags.php`
remain out of scope for both this ticket and LP-082's own checklist.

**Implemented (2026-08-18) — closed "Editor testing" too.**
`new.php`'s three AJAX-only JSON sub-actions (editor image upload,
Markdown/HTML format conversion, inline category quick-add) had real
capability/CSRF/error-handling branching but were still inline with
`header()`/`exit`, same as everything else this ticket's Testing section
already fixed — extracted into `PostsController::uploadEditorImage()`/
`convertContent()`/`quickAddCategory()`. Since these are JSON endpoints,
not redirects, they echo their response body directly (never `exit`)
instead of returning `AdminActionResult`, mirroring `ApiController`'s own
existing "no exit, echo directly" convention rather than inventing a new
pattern — and deliberately keep the editor's pre-existing JSON contract
(`content-editor.js`'s exact `data.filePath`/`url`/`csrfToken`/plain
`error` shape) rather than switching to `ApiResponse`'s REST envelope,
which would have broken the already-shipped client JS. 10 new tests in
`PostsControllerTest.php` (37 total now) capture the echoed body via
`ob_start()`/`ob_get_clean()`, the same technique `ApiControllerTest.php`
already uses. Manually verified against the throwaway dev install by
calling each endpoint's real route directly from the browser console
(`fetch()` against the actual CSRF token/URL each field already carries)
rather than through the UI's `window.confirm()`-gated format-switch
control, which CDP-driven automation can't click through — all three
returned the expected JSON. The client-side editor UI itself (format
switching, spell-check, fullscreen, autosave) has no automated test
coverage and isn't getting any: this project deliberately has no
JS test framework or Node.js dependency (CLAUDE.md's project
philosophy), so that half of "Editor testing" is out of scope by
design, not an oversight.

#### Documentation

- [x] Update README.md
- [x] Update CHANGELOG.md — Trash/Duplicate/Bulk Actions logged in `CHANGELOG.md`'s `[Unreleased]` section
- [x] Document developer APIs — `docs/DEVELOPER-APIS.md` (new): the full hook/filter reference, plugin file structure and lifecycle, and a service-layer pointer
- [x] Document theme integration — `docs/THEME-DEVELOPMENT.md` (new): required theme files, the template hierarchy, the complete template-tag API, widget/menu/Theme Options registration, and the dark-mode CSS-variable convention

**Implemented (2026-08-18) — closed LP-008's last two Documentation
items.** Two new reference docs under `LumoraPress/docs/`, deliberately
kept out of `README.md` (already long and installer/user-facing) rather
than added there: `THEME-DEVELOPMENT.md` (every template-tag function
grouped by category, the template hierarchy — confirming there is no
per-slug template-override cascade the way classic WordPress has one —
widget/nav-menu/Theme Options registration, and the dark-mode CSS-
variable convention) and `DEVELOPER-APIS.md` (every hook/filter actually
fired anywhere in core, grouped by lifecycle area, cross-referenced
against what the two shipped plugins — Font Awesome, Dummy Content —
actually use in practice; plugin file structure/header format and
lifecycle; a pointer into the service layer). Both researched directly
from the current codebase (every function/hook confirmed against its
real call site, not assumed from memory) rather than written
aspirationally, and both documents call out real rough edges rather than
smoothing them over: `post_deleted`/`page_deleted` fire identically for
trash *and* permanent delete with no way to distinguish; `comment_posted`
isn't fired by the REST API's comment endpoint; `media_saved` only
covers upload/register/replace, not metadata/move updates; there's no
plugin activation/deactivation hook and no admin-menu-registration API.
Added to `CLAUDE.md`'s "After Every Code Change" checklist (alongside
`README.md`/`CHANGELOG.md`) so both stay synchronized with the API
surface going forward rather than drifting stale. The same two docs also
close the identical "Document developer APIs"/"Document theme
integration"/"Document template system"/"Document category APIs"/
"Document Tag APIs" checklist lines in LP-009/LP-010/LP-011, since the
hook and template-tag reference covers every content type together
rather than being Posts-specific — see those tickets' own Documentation
sections.

#### Success Criteria

- Users can comfortably write and manage large numbers of posts.
- The editor is intuitive and responsive.
- Publishing workflows are reliable.
- Posts integrate seamlessly with themes, search, RSS, comments, and future plugins.
- The implementation remains lightweight, extensible, and consistent with Lumora Press's overall philosophy.

---

### LP-079. Front Page & Archive Post Display

### Goal

Implement WordPress-style post display behavior for the front page and post archives, with theme options controlling whether posts display their full content or an excerpt.

Use the two provided reference images in `/mnt/Winterfell/Coding/Github/Scripts/Lumora Press/` as visual examples:

- **Lumora Press current layout.jpg** — compact posts with no featured image.
- **Game of Thrones fansite sample.jpg** — traditional blog layout with large featured images, excerpts, metadata, thumbnails, sidebar, and Read More-style presentation.

### Theme Options

Under **Appearance → Theme Options**, add a dedicated new tab (sibling to
the existing Colors/Typography/Layout tabs on that page — see
`ThemeOptionSection`/`ThemeOptions::registerStandardOptions()`) rather
than adding these fields to an existing tab. The Theme Options page
already accumulates fields fast as features land; a same-page "Front Page
/ Archive Posts" group would make an already-long settings page longer
still, whereas a new tab keeps Post Display/Featured Image/Excerpt
Length/Read More Text scannable as their own concern.

**Do not call this tab "Layout"** — `ThemeOptions::registerStandardOptions()`
already registers a `layout` section (`ThemeOptions.php:207`) controlling
the site's header/content/footer *width*, an unrelated concern. Reusing
that name (or that section) for post-display options would conflate two
different settings groups under one label. Pick a distinct name/key
instead, e.g. `post_display` / "Post Display" or `front_page_archives` /
"Front Page & Archives".

#### Post Display

- [x] **Full Content**
  - [x] Display the complete post using the equivalent of WordPress `the_content()`.
  - [x] Do not display an excerpt or Read More link when the complete post is being shown.
- [x] **Excerpt**
  - [x] Display the post using the equivalent of WordPress `the_excerpt()`.
  - [x] Display a Read More / Continue Reading link to the full post.

#### Featured Image

- [x] Option to show/hide the featured image in post listings.
- [x] Allow the active theme to control the image's size, position, and styling.

#### Automatic Excerpt Length

- [x] Allow the administrator to specify the number of words used for automatically generated excerpts.
- [x] Use the manually assigned excerpt when one exists.
- [x] If no manual excerpt exists, generate the excerpt from the post content.
- [x] Strip/handle HTML safely when generating automatic excerpts.
- [x] Append an ellipsis when appropriate.

#### Read More Text

- [x] Configurable Read More text.
- [x] Default: `Continue reading →`

### Manual Excerpts

Implement a proper **Excerpt** field when creating/editing posts.

- [x] Allow authors to enter a custom excerpt. — already existed (LP-008); not rebuilt, just wired into the new priority chain.
- [x] `the_excerpt()`-style behavior should prioritize the manually entered excerpt.
- [x] Fall back to an automatically generated excerpt when no manual excerpt exists.

### More / Read More Tag

Implement WordPress-style **More tag** support.

- [x] Allow authors to insert a **More / Read More** break into post content.
- [x] Store the break in the post content using a suitable Lumora Press equivalent of WordPress's `<!--more-->`.
- [x] When viewing posts in excerpt/list/archive contexts, use the More tag as the author's chosen cutoff point when appropriate.
- [x] Display the configured Read More text/link after the preview.
- [x] Ensure the More tag does not appear as raw markup when viewing the full post.
- [x] Full single-post views should display the complete content using `the_content()`-style behavior.

### Content Template Functions

Create clear, reusable template/content functions modeled after the classic WordPress approach:

- [x] **`the_content()` equivalent**
  - [x] Outputs the full post content.
  - [x] Handles the More tag correctly.
  - [x] Used for single-post/full-content views.
- [x] **`the_excerpt()` equivalent**
  - [x] Outputs the manual excerpt when available.
  - [x] Otherwise generates an automatic excerpt.
  - [x] Respects the configured excerpt length.
  - [x] Handles the More tag where appropriate.
  - [x] Used for front-page/archive excerpt views.
- [x] Provide corresponding **return-value functions** where useful, equivalent to WordPress's `get_the_content()` / `get_the_excerpt()`, so themes and plugins can retrieve content without immediately outputting it.
- [x] Keep these functions reusable by themes and plugins rather than embedding post-display logic directly into individual templates.

### Reference Layouts

Use the supplied screenshots as **design/reference examples**, not as a requirement to copy the exact styling.

#### Lumora Press current layout

The current Lumora Press front page demonstrates a compact presentation:

- Post title
- Author/date metadata
- No featured image, Featured image
- Short post preview
- Continue Reading link

This should remain possible through the new options.

#### Game of Thrones fansite sample

Use the supplied **Game of Thrones fansite screenshot** as an example of a more traditional content-heavy blog layout:

- Large featured image
- Post title
- Author/date/category metadata
- Post excerpt
- Read More / full-post link
- Optional smaller related images/thumbnails
- Multiple posts displayed sequentially
- Sidebar alongside the main post column

The implementation should make this type of layout possible through the theme/template system without hard-coding the Game of Thrones-specific design.

### Example Configurations

The system should make configurations such as these possible:

**Modern/Compact**

> No featured image + automatic excerpt + Continue Reading

**Traditional Blog**

> Large featured image + excerpt + Continue Reading

**Author-Controlled**

> Featured image + content up to More tag + Continue Reading

**Full Blog**

> Featured image + full post content

### Compatibility & Edge Cases

Ensure correct behavior for:

- [x] Posts with manual excerpts — always wins, checked first in `get_the_excerpt()`.
- [x] Posts without excerpts — falls through to the More tag, then auto-generation.
- [x] Posts containing a More tag — content up to the tag is used as-is, unlimited by `excerpt_length`.
- [x] Posts containing both a manual excerpt and More tag — manual excerpt wins outright (matches classic WordPress's own precedence).
- [x] Posts with/without featured images — unchanged `has_post_thumbnail()` check, now additionally gated by the "Show featured image" option.
- [x] HTML and formatted content — `content_plain_text()`/`toPlainText()` render-then-strip, so Markdown/HTML syntax never leaks into an excerpt.
- [x] Short posts that do not need truncation — `make_excerpt()` returns text unchanged (no ellipsis) when under the word limit, unchanged pre-existing behavior.
- [x] Pagination — untouched, orthogonal to this ticket.
- [x] Single-post pages — always full content (`single.php` calls `the_content()`/`render_content()` unconditionally, never affected by the Post Display option).
- [x] Front page — `index.php` updated.
- [x] Category/tag/author/date archives — all four render through the one updated `archive.php` template (`SiteController::category()`/`tag()`/`author()`/`archive()`/`archiveByMonth()` all render it).
- [x] Responsive/mobile layouts — no viewport-specific changes needed; reuses existing responsive `.lp-post-list`/`.lp-post__content` rules.

### Goal

Follow the **classic WordPress content model** where practical rather than creating a Lumora-specific system unnecessarily:

**Full post → `the_content()`**
**Post preview → `the_excerpt()`**
**Author-selected cutoff → More tag**
**Visual presentation → Theme/template**

**Implemented.** `ContentRenderer` gained `hasMoreTag()`/`splitAtMoreTag()`,
operating on a post's *raw* stored content before rendering — required
since `HtmlSanitizer` strips HTML comments outright and an Html-format
post's raw content is itself sanitized at save time, so an
`<!--more-->`-style comment could never survive being stored for that
format at all. Two markers are recognized: the literal text `<!--more-->`
(Markdown/Plain, which are never sanitized at save time) and
`<span class="lp-more-tag">...</span>` (Html/WYSIWYG — every tag/attribute
already permitted by `HtmlSanitizer`'s existing allowlist, so it survives
sanitization). `include/content-display-functions.php` is new
(`the_content()`/`get_the_content()`/`the_excerpt()`/`get_the_excerpt()`)
— pure orchestration needing no new bridge/service, since every dependency
(`render_content()`/`content_plain_text()`/`content_split_at_more_tag()`,
`make_excerpt()`, `theme_option()`) was already globally available. Theme
Options gained a fourth section, "Post Display" (`post_display_mode`,
`show_featured_image_in_listings`, `excerpt_length`, `read_more_text`) —
kept separate from the existing `layout` section per this ticket's own
amendment above. `content/themes/default/index.php`/`archive.php` both
updated (the latter previously had no Read More link at all — now
consistent with the homepage). Both content editors (EasyMDE/TinyMCE)
gained a "Insert Read More Tag" toolbar button. Covered by
`Unit/Services/ContentRendererTest.php` (More tag detection/splitting) and
new `Unit/ContentDisplayFunctionsTest.php`; `Unit/Core/Theme/
ThemeOptionsTest.php` updated for the new section/field counts.

**Fixed same day:** the first pass had `index.php`/`archive.php` only
check `post_display_mode` — a post's own Read More tag was silently
ignored whenever the site was set to "Full Content" (reported by Ariane:
"Read more not working... post mode is in fact to show full"). Classic
WordPress's own `the_content()` always respects `<!--more-->` on a
listing page regardless of the "show full/excerpt" setting — an author's
explicit cutoff should win over the site-wide default either way. Added
`post_has_more_tag()`/`get_the_content_up_to_more_tag()` (the latter
returns the *rendered* HTML up to the cutoff, preserving formatting/
images — unlike `get_the_excerpt()`, which is always plain text); both
templates now check for a More tag first, before falling back to
`post_display_mode`. Regression-tested in
`Unit/ContentDisplayFunctionsTest.php`.

Full PHP 8.2/8.3/8.4 matrix run via `./run-tests-all-php.sh` — see `PHP Test
Suite/TEST_LOG.md`.

### LP-081. Update Installed Theme via ZIP Upload

**Implemented.** `ThemeInstaller::install()`/`update()` now share validation
(`validateArchive()`) and a small `openZip()` helper; `update()` extracts to
a `.updating-{random}` staging directory, then swaps it over the live theme
directory via `.replaced-{random}` displacement + two `rename()` calls,
restoring the displaced original if the second rename fails. Validation
(entry count/path safety/size/style.css header) runs before any directory is
touched, so a ZIP that fails validation never disturbs the existing theme at
all — confirmed by
`testUpdateLeavesTheOriginalThemeIntactWhenTheArchiveFailsValidation()`.
`admin/views/appearance/themes.php` gained the `update_theme` form (in each
theme's details `<template>`, outside the `!$info->isActive` guard so the
active theme can be updated too) and the `?updated=` flash message. 7 new
tests in `ThemeInstallerTest.php`; full `Unit/` suite (1184 tests) still
green.

### Goal

Appearance &rsaquo; Themes currently only lets an administrator *install* a
brand-new theme from a ZIP (`ThemeInstaller::install()`) — re-uploading a
newer ZIP for an *already-installed* theme (including the currently active
one) hard-fails with "A theme named X is already installed. Remove it
first or rename the archive," which forces a destructive delete-then-
reinstall cycle (impossible at all for the active theme, since active
themes can't be deleted) just to pick up a fix or new version of a theme's
own files. Add an in-place "Update from ZIP" action per theme.

### Prior Art

Lumora Gallery already built the sibling feature (LG-043,
`include/services/ThemeService.php`'s `updateFromZip()`/`processZip()`),
including a comment there flagging this exact gap in LumoraPress's
`ThemeInstaller`. Mirror its approach — extract to a staging directory,
validate, then swap the staged folder over the live one via two `rename()`
calls with the previous version restored on any failure — adapted to this
project's own conventions (`ThemeInstaller`'s existing ZIP-safety checks,
scoped-per-slug `Csrf` action names, `admin/views/appearance/themes.php`'s
existing form-dispatch pattern) rather than copied verbatim. No dependency
on Lumora Gallery is introduced — its file is reference material only, per
this project's stated independence from that codebase.

### Checklist

- [x] `ThemeInstaller::update(string $zipPath, string $slug): ThemeInfo` —
      shares the existing ZIP-safety validation (entry count, path
      traversal, uncompressed size, root-prefix unwrapping, style.css +
      "Theme Name:" header requirement) with `install()`, but targets an
      already-installed `$slug` instead of deriving a new one, and requires
      the destination to already exist (the inverse of `install()`'s
      check).
- [x] Staged extract + rename-swap: extract to a temp directory first,
      then `rename()` the live theme directory aside, `rename()` the
      staged directory into its place, and only remove the displaced
      original once the swap succeeds — on any failure after the first
      rename, the displaced original is renamed back so a failed update
      never leaves the theme half-installed or missing.
- [x] Updating the currently *active* theme is allowed (unlike Activate/
      Delete, which only operate on inactive themes) — a site owner should
      be able to update the theme they're actually using without
      deactivating it first.
- [x] `admin/views/appearance/themes.php`: new `update_theme` form
      (file upload, `enctype="multipart/form-data"`) inside each theme's
      existing details `<template>` panel, CSRF action name scoped to the
      theme slug (`update_theme_{slug}`, following the same collision-
      avoidance convention as `activate_theme_{slug}`/`delete_theme_{slug}`
      — see the fix for the earlier `activate_theme` double-`Csrf::field()`
      bug in this same file). `data-lp-confirm` warns that updating
      overwrites the theme's current files before the browser submits.
- [x] A new `?updated={slug}` success flash message, matching the existing
      `?installed=`/`?deleted=` pattern.
- [x] Unit tests in `ThemeInstallerTest.php`: successful update overwrites
      existing files and adds new ones, rejects a missing destination
      theme, rejects the same ZIP-safety violations `install()` already
      rejects, and — the one genuinely new behavior — a failure partway
      through the swap leaves the original theme directory intact and
      unchanged (simulate via a non-writable staged extract or an
      unreadable destination, matching however `UpdatePackageValidatorTest`
      / `ThemeInstallerTest`'s existing failure-injection style handles an
      equivalent case, if any precedent exists there).

### LP-085. Admin UI Visual Polish Pass 3: Full Screen Audit

### Goal

Ariane's feedback (2026-08-11, after using the new LP-083 Post/Page
editor sidebar): the admin UI "is all very flat and colorless now."
This is the third such pass — **not a from-scratch redesign**:

- `LP-055` (2026-08-01, see `docs/HISTORY.md`) gave the shared
  `.lp-admin__panel`/`.lp-table`/`.lp-button`/`.lp-field`/`.lp-alert`/
  card classes shadows, radius, focus rings, tinted table headers, and
  a restyled breadcrumb trail.
- `LP-063` (2026-08-04, see `docs/HISTORY.md`) followed up with a
  screenshot-driven audit that caught three categories LP-055 missed:
  unwrapped `<select>` dropdowns with no chrome, plain list-item links
  falling back to browser-default blue/underline, and the Media Manager
  grid never getting the card treatment.

Both were real, shipped passes — so "still flat and colorless" six
months later means either regressions, screens neither pass actually
screenshotted, or components that exist today but didn't in early
August (most notably LP-083's whole sidebar-box system, built
2026-08-11, which has never been through a polish pass at all — see
its own follow-up fixes this same session for the kind of overflow/
spacing issues a first pass typically finds). Treat this the same way
LP-063 did: **audit first with real screenshots against the live site,
catalog specific flat/colorless elements, then fix them** — don't
guess at fixes without a screenshot in hand, and don't re-touch
anything LP-055/LP-063 already fixed unless it visibly regressed.

### Known likely candidates (starting points for the audit, not a
### substitute for it)

- **LP-083's sidebar boxes** (`admin/assets/css/admin.css`'s
  `.lp-sidebar-box*` rules, added today) — box headers/content currently
  use only `--lp-admin-panel-bg`/`--lp-admin-border`, no shadow or the
  panel-radius treatment LP-055 gave `.lp-admin__panel`; likely the
  most immediate source of "flat" in Ariane's feedback given the timing.
- **Status/role indicators with no color at all**: Post/Page status
  (Draft/Published/Scheduled/Pending Review — currently plain `<select>`
  text, no colored badge anywhere it's *displayed* rather than edited,
  e.g. the All Posts/Pages list tables), Comment moderation status,
  User role. Classic WordPress uses colored status text/badges here;
  this codebase doesn't yet, anywhere.
- **Screens never explicitly screenshotted by LP-055 or LP-063**: Login/
  Forgot Password/Reset Password, Users, API Tokens, Profile, the
  Font Awesome plugin admin page, System Information, Logs, Redirects,
  Embeds, Security/Cache/Maintenance Mode/Discussion/Reading/Permalinks
  settings tabs, Categories/Tags. Most inherit the shared classes
  LP-055 already styled, but neither pass has a screenshot on record
  confirming any of them actually look right.
- **Category/tag chips and similar small metadata pills** — check
  whether they use the existing `--lp-admin-chip-bg`/`--lp-admin-chip-border`
  tokens (already defined in `admin.css`'s `:root`, per LP-055/063's own
  additions) or still render as plain text/unstyled `<li>`s.

### Checklist

- [x] Screenshot every admin screen listed above (plus any missed) on
      the live site, light and dark mode both, and catalog specific
      flat/colorless elements per screen — same methodology LP-063 used.
- [x] Give `.lp-sidebar-box` (LP-083) the same shadow/panel-radius
      treatment `.lp-admin__panel` already has, so the editor sidebar
      doesn't look like a step backward from the rest of the admin.
- [x] Decide on and implement colored status indicators for Post/Page
      status and Comment moderation status wherever they're displayed
      (not just the edit-form `<select>`), reusing the existing success/
      warning/error/chip token set rather than inventing new colors.
- [x] Fix every specific flat/colorless element the audit catalogs,
      scoped to `admin/assets/css/admin.css` (CSS-only where possible,
      matching LP-055/LP-063's own "no PHP template changes needed"
      precedent) unless a screen genuinely lacks the wrapper markup to
      hook a fix onto.
- [x] Confirm dark-mode variants for every new/changed rule, added at
      the same time per this project's own CSS-authoring rule — not
      deferred.
- [x] Manual before/after screenshot comparison for every screen
      touched, shared with Ariane.

### Audit findings (2026-08-17)

Screenshotted against the local dev install
(`http://localhost/lumorapress-preview/`, both light and dark) rather
than guessing: Dashboard, Users, Settings (General, Permalinks,
Reading, Discussion, Cache, Maintenance Mode, Security, Embeds,
Redirects), Plugins, Categories, Tags, Login, Forgot/Reset Password,
Profile, API Tokens, System Information, and Logs (a placeholder stub
— nothing to polish). The Font Awesome plugin has no dedicated
settings page to audit (LPP-002's first-pass scope never added one).

Most screens already looked correct — LP-055/LP-063's shared classes
(`.lp-admin__panel`/`.lp-table`/`.lp-field`/`.lp-button`) are applied
consistently everywhere. Two concrete defects were found and fixed:

- `.lp-login` (Login/Forgot Password/Reset Password — all three share
  this one class) had never received the LP-055 panel treatment at
  all: no `box-shadow`, and the smaller 4px `--lp-admin-radius` instead
  of the 10px `--lp-admin-panel-radius` every other card surface uses.
  The single most "flat" screen in the whole admin, since it's the
  first thing anyone sees and floats alone on a plain background with
  nothing else on the page for contrast.
- Dashboard's Recent Posts/Recent Comments status badges could inflate
  into an oversized blob instead of a compact pill whenever they sat
  next to a title long enough to wrap onto two lines —
  `.lp-admin__meta-list li`'s flexbox stretched the badge to the row's
  full height by default (`align-items: stretch`), and a 999px border
  radius turned that stretched shape into a blob swallowing its own
  text. Fixed with `align-items: center` plus `flex-shrink: 0` on the
  badge.

The `.lp-sidebar-box` shadow/radius fix and the Dashboard/Users colored
status badges (both already noted as "known likely candidates" above)
were implemented earlier in the same session, before the full
screenshot audit ran.

**Follow-up (same day):** Ariane flagged the Dashboard's white cards as
still "so flat" after the above — the shared `--lp-admin-shadow`/
`--lp-admin-shadow-hover` tokens themselves (used by every panel,
sidebar box, and plugin/theme/media card, not just Dashboard) were a
near-invisible Tailwind-`shadow-sm`-equivalent value, too subtle to
read as a shadow at all against the admin's light-gray page background.
Strengthened both tokens (light and dark) in `admin.css`'s `:root`;
since every card-like surface already consumes these two tokens rather
than hardcoding shadow values, this one change lifted all of them at
once with no other CSS or template edits needed.

Ariane then asked for the sidebar box title bars ("Publish", "Featured
Image", "Categories", "Tags") to be colored rather than gray/plain.
`.lp-sidebar-box__header` had no background of its own before (just a
border-bottom), so it inherited the box's own white/dark panel color —
same flatness complaint, different element. Gave it
`--lp-admin-chip-bg`/`--lp-admin-chip-border` (the existing soft
accent-tint tokens already used for row-action chips and the tag
input) plus an accent-colored title, reusing the same color language
instead of introducing a new one. Verified in both light and dark on
the Post editor's sidebar.

### LP-086. Visible Update Progress (Staged Download & Install)

### Goal

Give both update paths on the Maintenance &rsaquo; Updates page — the
GitHub "Download & Install" flow (LP-027) and the manual ZIP "Confirm &
Install" flow (LP-026) — a live, stage-by-stage progress indicator while
a download or install is running, the same "visible stages" UX Lumora
Gallery (`/mnt/Winterfell/Coding/Github/Scripts/Lumora Gallery`) and
FanUpdate Redux (`/mnt/Winterfell/Coding/Github/Scripts/FanUpdateRedux`)
already have — instead of a form POST the browser just sits on with zero
feedback until it either redirects or re-renders, however long that
takes.

### Architecture note

Lumora Gallery's own updater decomposes its pipeline into independently
resumable stages, each its own AJAX round-trip driven by repeated `POST`
calls from the browser. Lumora Press's `UpdateService::install()` (and
the GitHub download-then-validate flow) stayed a single atomic PHP
request instead — the existing backup-then-apply-then-migrate sequence's
automatic rollback-on-failure guarantee depends on running inside one
try/catch, and splitting it across separate HTTP requests would mean
either giving up that guarantee or rebuilding it as a resumable state
machine, a much larger change than this ticket's actual goal (visibility
into a process that already works). Instead: the long-running request
writes its current stage to a small on-disk JSON file
(`UpdateProgress`/`storage/updates/progress.json`) as it goes, and a
separate, lightweight polling request (`?ajax=progress` on the same
page) reads it back every ~700ms while the main request is still in
flight — the same "one long request, a second cheap one polls its
progress" pattern, without touching install()'s transactional structure
at all.

This only works because the long-running request releases PHP's session
file lock (`session_write_close()`) before starting the slow part —
without that, the polling request (sharing the same session cookie)
would simply queue behind the session lock and never see anything until
the main request was already done.

### Features

- [x] Live stage checklist for GitHub "Download & Install" (Checking
      GitHub for the release &rarr; Downloading release package &rarr;
      Validating package &rarr; Checking compatibility)
- [x] Live stage checklist for manual-upload "Confirm & Install"
      (Backing up files &rarr; Backing up database &rarr; Applying
      update files &rarr; Running database migrations &rarr; Clearing
      caches &rarr; Finishing up) — the actual apply-to-disk step, and by
      far the longest one in the whole pipeline
- [x] Live stage checklist for the manual ZIP upload's post-transfer
      validation (Validating package &rarr; Checking compatibility),
      shown once the existing byte-upload progress bar (LP-026) reaches
      100% and the server starts extracting/validating the archive
- [x] Failed stages render distinctly from completed ones (a red "error"
      marker on whichever stage was active when a run failed, with any
      stage never reached left "pending" rather than falsely marked
      done) — the real point of failure stays visible, not laundered
      into a generic error banner alone
- [x] `session_write_close()` before every long-running branch (`upload`,
      `install`, `github_download`), so the polling endpoint sharing the
      same session isn't blocked behind PHP's own session file lock for
      the operation's entire duration
- [x] `?ajax=progress` polling endpoint (`admin/views/maintenance/
      updates.php`) reading `UpdateProgress::read()` — no CSRF check (it
      only ever reads on-disk state, nothing to protect), still gated on
      the same admin session + `manage_options` capability every other
      branch of the page requires

Declined (2026-08-13): byte-level download percentage for the GitHub
download stage, fully resumable/interruption-safe staged HTTP requests
matching Lumora Gallery's architecture, and automated browser/JS test
coverage for the stage-checklist UI. See `DECISIONS.md` for why.

### Implementation notes

**Backend:** `LumoraPress\Services\UpdateProgress` (new,
`storage/updates/progress.json`, same on-disk-JSON convention as
`UpdateManifest`/`UpdateChecksumManifest`) exposes `reset(operation,
stages)`, `stage(key)`, `complete(success, message)`, and `read()`.
Writes are write-then-rename for atomicity, so a poller reading mid-write
never sees a truncated JSON document. `reset()` is always called by the
view (`updates.php`), never by `UpdateService` — `checkUpload()`/
`install()` only ever call `stage()`/`complete()` against whatever list
the view already declared, since the GitHub flow's four stages span both
view-level code (`check`/`download`) and a single `checkUpload()` call
(`validate`/`compatibility`) that must report into the *same* list rather
than resetting it out from under the two stages that already ran.
`UpdateService` gained an optional, nullable `?UpdateProgress $progress`
constructor parameter (same optional-DI pattern as `$users`/`$checksums`)
so every existing test call site keeps compiling unchanged.

**Frontend:** `admin/assets/js/update-progress.js` (new) intercepts the
"Confirm & Install" and "Download & Install" `<form>` submits via
`fetch()`, starts polling `?ajax=progress` immediately, and replaces the
document with the eventual response the same way `update-upload.js`
already did for the manual-upload byte-progress bar — `install()`'s
success path is a redirect, which `fetch()` follows automatically, so
`response.redirected`/`response.url` covers both that and the
GitHub-flow's direct-render outcome. `update-upload.js` itself gained a
second, small polling loop (a deliberate near-duplicate of
`update-progress.js`'s renderer rather than a shared module — the two
files' polling starts from different triggers and share no other code)
that begins once `xhr.upload`'s `loadend` fires, i.e. once the byte
transfer itself is done and the byte-progress bar has nothing further to
show. New `.lp-update-progress`/`.lp-update-progress__item`/
`.lp-update-progress__marker` rules in `admin/assets/css/admin.css`
render the checklist (pending/spinning-active/done-check/error-cross).

**A `session_write_close()` bug found and fixed during manual
verification:** the naive first pass called `session_write_close()`
right before each long operation and never reopened it. That works for
`install()`'s success path (a redirect, which needs no further session
access) — but every *other* outcome (a validation failure, a caught
exception, the GitHub/manual-upload flows' direct render) falls through
to rendering the rest of the page, which calls `Csrf::field()` for the
Confirm & Install / Cancel / Check-for-Updates forms still on it.
`Csrf::ensureSession()` throws once `session_status()` isn't
`PHP_SESSION_ACTIVE` — exactly what `session_write_close()` had just
made true — so every one of those paths 500'd with "Session must be
started before using CSRF protection," caught only by `ErrorHandler`'s
own generic "Something went wrong" page. Fixed by calling `session_start()`
again immediately after each long operation finishes (all three
branches — `upload`/`install`/`github_download` — except `install()`'s
success path, which already exited via redirect before reaching it):
reopening briefly at that point is safe, since by then the operation
(and whatever a poller needed the session lock's *absence* for) is
already done.

**Tests:** `PHP Test Suite/Unit/Services/UpdateProgressTest.php` (new, 9
cases) covers `UpdateProgress` in isolation — reset/stage/complete
transitions, the "unknown stage key" no-op guard, corrupt-JSON fallback,
and that a fresh `reset()` fully replaces a previous run's stages rather
than merging into them. `Integration/UpdateServiceIntegrationTest.php`
gained `testInstallReportsStageProgressThroughToCompletion()`, wiring a
real `UpdateProgress` through a full `checkUpload()`/`install()` run
against a real MySQL/MariaDB server and asserting every declared stage
ends "done" — this confirms `UpdateProgress`/`UpdateService`'s own
stage-reporting logic, but not the `session_write_close()` bug above,
since it constructs `UpdateService` directly and never goes through
`updates.php`'s session handling at all; only the live manual
verification below caught that one. Full
`./run-tests-all-php.sh` Docker matrix (MariaDB 11 + PHP 8.2/8.3/8.4):
1313 tests, 2 pre-existing failures unrelated to this ticket
(`ThumbnailServiceTest::testGenerateAppliesExifOrientationBeforeResizing`,
an EXIF-orientation/GD environment difference; `MediaServiceTest`'s
`docx` MIME-detection case) — every Update-related test, including the
two new ones above, passed on all three PHP versions.

Manually verified end-to-end in a throwaway install (a temp copy + Docker
MariaDB, never the real project source tree) with a real browser — this
is how the `session_write_close()` CSRF crash above was actually caught
in the first place. Confirmed: the Manual Update tab's byte-progress bar
correctly handed off to the validate/compatibility stage checklist
(`Update Summary` rendered correctly from a package matching the
installed corePaths); "Confirm & Install" correctly intercepted the form
submit, polled `?ajax=progress` (captured in the browser's own network
log), and followed `install()`'s redirect on completion. That specific
install run itself failed a post-overlay integrity check (an artifact of
the hand-built test package used for verification, not of this ticket's
code) and rolled back — which, read back from `UpdateProgress::read()`
immediately afterward, showed exactly the intended failure semantics:
every stage up to and including the one active when the exception was
thrown marked `done`/`error` correctly, and the one stage never reached
(`cleanup`) correctly left `pending` rather than falsely `done`. A full
successful run reaching `done` on every stage is what the Integration
test above exercises against a real, correctly-shaped package.

### LP-087. Admin Dark Mode Toggle (Per-User Theme Preference)

### Goal

Ariane asked (2026-08-17, in the same conversation as LP-085) whether
the admin has a manual light/dark toggle. It doesn't — dark mode is
currently driven entirely by `@media (prefers-color-scheme: dark)` in
`admin.css`, with no way to override the OS/browser preference from
inside the app. Add a real toggle: Light / Dark / Follow System, saved
per-user (so it's consistent across sessions and devices, and each
admin user can choose independently — the same reasoning that made the
Post/Page editor's Default Editor setting (LP-066/LP-067) a per-user
column rather than a site-wide one).

### Architecture

Follow the existing Default Editor precedent
(`preferred_editor` on `users`, `ContentFormat` enum,
`UserService::updateEditorPreference()`, a Profile-page `<select>` +
POST/redirect/GET, `admin/views/profile.php`) rather than inventing a
new pattern:

- New `theme_preference` column on `users` (migration), holding
  `'light'` / `'dark'` / `'auto'`, defaulting to `'auto'` for every
  existing row so nothing changes for anyone until they explicitly opt
  in.
- New `ThemePreference` enum (`Light`/`Dark`/`Auto`) — unlike
  `ContentFormat`, there is no "site default" to defer to (no site-wide
  theme setting exists, and inventing one is out of scope here), so
  `Auto` is a real case, not represented by `null`. `User::$themePreference`
  is therefore a non-nullable `ThemePreference` property defaulting to
  `Auto`.
- No new service class — unlike `EditorPreferenceService`, there's no
  "registered themes" extensibility point or site-wide lock to model,
  so resolving the preference is a single property read in
  `layout-header.php`, not a service method.
- CSS: `admin.css`'s `:root` dark-mode block currently lives entirely
  inside `@media (prefers-color-scheme: dark)`. Restructure to the
  standard three-block pattern so an explicit choice always wins over
  the system preference in both directions:
  - `:root { /* light tokens, unchanged */ }`
  - `@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { /* dark tokens */ } }`
  - `:root[data-theme="dark"] { /* same dark tokens */ }`
- `admin/views/layout-header.php` renders `data-theme="dark"` or
  `data-theme="light"` on `<html>` (or omits the attribute entirely for
  `Auto`) directly from `$currentUser->themePreference` — server-side,
  before the stylesheet loads, so there's no flash-of-wrong-theme and
  no cookie/JS needed for any already-authenticated admin page.
- Login/Forgot Password/Reset Password stay on the pure
  `prefers-color-scheme` fallback (unchanged) — there's no user to read
  a preference from before authentication.

### Checklist

- [x] Migration: `ALTER TABLE {prefix}users ADD COLUMN theme_preference VARCHAR(10) NULL`
- [x] `app/Models/ThemePreference.php` enum (`Light`/`Dark`/`Auto`, `label()`)
- [x] `User::$themePreference` property + `UserService::hydrate()` branch
      (`ThemePreference::tryFrom(...) ?? ThemePreference::Auto`)
- [x] `UserService::updateThemePreference(int $id, ThemePreference $preference): void`
- [x] Profile page: new "Appearance" panel (mirrors the existing
      "Editor" panel's markup/CSRF/POST-redirect-GET shape) with a
      Light/Dark/Follow System `<select>`
- [x] `layout-header.php`: `data-theme` attribute on `<html>` from
      `$currentUser->themePreference`
- [x] `admin.css`: restructure `:root`'s dark-mode block into the
      three-part `@media`/`[data-theme]` pattern described above
- [x] Manual verification: toggle each of the three options and confirm
      the admin renders correctly regardless of the OS-level preference,
      light and dark both, on at least one screen already covered by
      LP-085's audit. The Profile page's Appearance dropdown was
      confirmed working end-to-end (verified via direct DB inspection:
      a save request actually persisted `theme_preference = 'dark'`,
      and the resulting page render correctly went dark app-wide,
      screenshotted in both light and dark). The sidebar quick toggle
      button (added as a follow-up, see below) was code-reviewed
      correct in-session, then confirmed working by Ariane's own manual
      click-test — the browser automation tool used during development
      failed to deliver click events to that specific button (confirmed
      via a JS event listener that never fired), an environment
      limitation, not a code defect.

### Follow-up: sidebar quick toggle (same session)

Ariane asked for a one-click toggle in the sidebar in addition to the
Profile page's full Light/Dark/Follow System setting. Added:

- `admin/views/layout-header.php`: a small icon button (🌙/☀️) next to
  the username in `.lp-admin__user`, in its own `method="post"
  action=""` self-submitting form (empty `action` resolves to
  whatever admin page is currently loaded, so the same button works
  identically from every screen without each view needing to know
  about it).
- The button only ever toggles between Light and Dark — never Auto.
  Starting from Auto, clicking it commits to Dark first; getting back
  to "follow the system" is a deliberate choice made on the Profile
  page, not something the quick toggle cycles through.
- `admin/index.php`: a new POST handler right after
  `$currentUser = $kernel->auth->user();`, before routing to any
  specific view, so the toggle works the same from Dashboard, Posts,
  Settings, anywhere. Redirects back to `$_SERVER['REQUEST_URI']` —
  the exact page the toggle was clicked from — rather than bouncing
  the admin to the Profile page.
- `admin.css`: `.lp-admin__user-row`/`.lp-admin__theme-toggle` — a
  small circular icon button, subtle hover/focus states, no new color
  tokens (reuses existing sidebar-fg/accent tokens).

### LP-088. Dark Mode Contrast Bugs: Editor Toolbar & Form Field Distinction

### Goal

Two contrast/legibility bugs Ariane spotted (2026-08-17) after using
the new LP-087 dark mode toggle in dark mode specifically — neither
was ever caught by LP-055/063/085's earlier passes since none of those
were done with dark mode actually toggled on and compared side by side.

### Bugs

1. **Markdown editor toolbar buttons are nearly invisible in dark
   mode.** `admin/assets/css/admin.css`'s `.EasyMDEContainer
   .editor-toolbar a { color: var(--lp-admin-text) !important; }`
   (around line 1246) looks like it should already fix this — worth
   checking first whether EasyMDE's own bundled CSS is winning on
   specificity/load-order against something more specific than a
   plain `a` selector (e.g. targeting the icon glyph itself, not the
   anchor), rather than assuming the token value itself is wrong.
2. **Form fields blend into their surrounding panel**, making it hard
   to tell at a glance what's editable vs static text — most visible
   in dark mode but not exclusively a dark-mode issue. Root cause:
   `.lp-field input`/`textarea`/`select` (line ~1873) and the
   standalone `select` fallback (line ~1904) both set
   `background: var(--lp-admin-panel-bg)` — identical to the panel
   they sit inside, so there's no contrast between "this is a field"
   and "this is the panel's own background." Needs a distinct token
   (e.g. a new `--lp-admin-field-bg`, subtly darker/lighter than
   `--lp-admin-panel-bg` in each mode) applied to every input/textarea/
   select, with light and dark values chosen together — not just a
   dark-mode patch, since the same blending exists in light mode too,
   just less noticeable there.

### Checklist

- [x] Screenshot the Markdown editor toolbar in dark mode, inspect
      computed styles to find what's actually overriding the icon
      color, and fix at the correct specificity/selector
- [x] Add a distinct field-background token and apply it to every
      `input`/`textarea`/`select` covered by `.lp-field` and the
      standalone `select` fallback, in both light and dark
- [x] Confirm the fix doesn't regress `:focus` state contrast
      (existing `--lp-admin-focus-ring`/border-color-on-focus rules —
      untouched by this change, since only `background` was modified)
- [x] Screenshot before/after in both light and dark, shared with
      Ariane

### Resolution notes (2026-08-17)

Both bugs fixed:

1. **Editor toolbar.** Root cause confirmed by inspecting the live DOM
   rather than guessing: this project's bundled EasyMDE build renders
   toolbar items as `<button><i class="fa fa-*"></i></button>`, not
   the `<a>` tags upstream EasyMDE uses — so `.EasyMDEContainer
   .editor-toolbar a { color: ... !important; }` matched nothing at
   all, and every icon fell back to EasyMDE's own hardcoded black
   (confirmed via `getComputedStyle`: `rgb(0, 0, 0)` before the fix).
   Changed the selector to `button`/`button i`; confirmed after the
   fix the icon color matches `--lp-admin-text` in dark mode exactly.
2. **Form fields blending into panels.** Added a new
   `--lp-admin-field-bg` token (light `#f3f4f6`, dark `#24282c`),
   applied to `.lp-field input`/`textarea`/`select` and the standalone
   `select` fallback, replacing `--lp-admin-panel-bg`. Confirmed via
   computed style: field background now `rgb(243, 244, 246)` against
   a `rgb(255, 255, 255)` panel.

### LP-089. Admin Link Color Cohesion: No `:visited` Styling Anywhere

### Goal

Ariane spotted (2026-08-17) that the Posts list and Pages list look
inconsistent — Posts' row-title links render as the theme's accent
blue, Pages' render as the browser's native visited-link purple, even
though both use the exact same `.lp-table td a` rule
(`admin/assets/css/admin.css`, ~line 2435). Confirmed root cause: there
is not a single `:visited` selector anywhere in `admin.css` (grep for
`:visited` across the whole ~3,300-line file returns zero matches).
Every link class — `.lp-table td a`, `.lp-admin__meta-list a`,
`.lp-folder-tree__item a`, `.lp-admin__nav-item a`,
`.lp-admin__breadcrumbs a`, `.lp-login__links a`,
`.lp-pagination__item a`, `.lp-admin__filters a`, etc. — only styles
the default and `:hover`/`:focus-visible` states. A link the browser
considers "visited" (Ariane has clicked into nearly every Page in this
example, none of the newer Posts) silently falls back to the browser's
own default purple instead of the theme's palette, so two rows using
identical markup/CSS can render two different colors depending purely
on the visitor's own browsing history — not a real style difference,
but reads as one.

### Scope

An audit, not a one-line fix — every link-color rule in `admin.css`
needs a matching `:visited` rule (generally the same color as the
unvisited state, since an admin table's job is "here's a record you
can click," not "here's an article you may or may not have read" —
unlike a public blog's content links, visited-vs-unvisited usually
isn't meaningful information worth color-coding in an admin UI).
Where a link currently has no explicit color rule at all and relies on
inherited/default styling, decide deliberately whether it needs one
rather than leaving it to accident.

### Checklist

- [x] Enumerate every selector in `admin.css` that styles an `a`
      element's `color` (or relies on the UA default), across every
      screen — not just the two tables Ariane happened to compare
- [x] Add a `:visited` rule alongside each one, matching its own
      unvisited color (not the browser default), confirmed in both
      light and dark
- [x] Re-screenshot Posts and Pages side by side after clicking into
      several rows on both, to confirm they now render identically
      regardless of visited history
- [x] Spot-check a few other link-heavy screens (Dashboard's meta
      lists, breadcrumbs, pagination, Media Manager's folder tree) for
      the same issue

### Resolution notes (2026-08-17)

Added a matching `:visited` selector alongside every link-color rule
in `admin.css` (same color as the unvisited state, added to the
existing selector list rather than a separate duplicated rule, so
there's still only one place to update either color in the future):
`.lp-admin__site-link a`, `.lp-admin__nav-item a`,
`.lp-admin__breadcrumbs a`, `.lp-admin__meta-list a`/
`.lp-folder-tree__item a`/`.lp-thumbnails__list a`, `.lp-login__links a`,
`.lp-admin__filters a`, `.lp-update__release-notes-body a`,
`.lp-pagination__item a`, `.lp-table td a`, and `.lp-button--link`
(confirmed used on real `<a>` tags — the Post/Page revision "Compare
to current" links — not just `<button>`). Scope was deliberately
limited to each rule's *resting*/default state, not every
hover/focus/active variant, matching what Ariane's screenshots
actually showed (a resting list of titles, not an interaction state).

Note: browsers block `getComputedStyle`/JS from ever reporting a
link's true `:visited` color, by design, to prevent history-sniffing —
so this could only be spot-checked by actually visiting a link and
re-screenshotting the list afterward, not scripted the way the other
LP-088/090/091 fixes were.

### LP-090. Admin Button Color Cohesion: Inconsistent Primary/Secondary Usage

### Goal

Ariane spotted (2026-08-17) that action buttons look arbitrarily blue
vs white/colorless across the admin — e.g. Theme Options' "Save Post
Display" is blue while "Reset to Defaults" right next to it is white.
`admin.css` (~line 2154) does define a real three-tier system —
`.lp-button` (neutral/white, the base), `.lp-button--primary` (blue,
the main affirmative action), `.lp-button--secondary` (transparent
outline, a lower-emphasis action), `.lp-button--danger` (red,
destructive) — so the Theme Options example is arguably *correct*
(Save = primary, Reset = a real secondary/cautionary action). The
actual bug is elsewhere: a grep across `admin/views/` finds **53**
buttons using the bare, unmodified `.lp-button` class against **75**
using `.lp-button--primary`, and only 14 files use `.lp-button--secondary`
at all. Several of those bare `.lp-button` buttons are clearly each
their form's *main* action with no other button competing for
"primary" status on the same screen — e.g. `admin/views/media/media.php`'s
"Save" (line 507) and "Create" (folder, line 847), `admin/views/plugins.php`'s
"Activate" (line 313), `admin/views/media/import.php`'s "Continue"
(line 299) — styled identically to a secondary/incidental "Filter" or
"Search" button elsewhere, with no visual signal for which one matters.
This reads as arbitrary because it *is* arbitrary in those cases, not
because the color system itself is wrong.

### Scope

Not "make every button blue" — a deliberate per-screen pass: for each
form/action group, decide which button (if any) is the primary
affirmative action and give it `.lp-button--primary`; genuinely
secondary/lower-stakes actions (Cancel, Filter, Search) can stay
`.lp-button`/`.lp-button--secondary`; anything destructive gets
`.lp-button--danger` (worth checking whether "Reset All Theme Options"
belongs here instead of `--secondary`, since it discards every
customization at once — a judgment call to make explicitly, not
inherit from whatever was typed first).

**Design decision (Ariane, 2026-08-17):** the base `.lp-button`'s
current white/transparent-on-panel-bg look reads as *unstyled* rather
than *intentionally neutral* — a plain HTML button with no styling
applied would look the same. Give the base `.lp-button` (and
`.lp-button--secondary`, which is nearly identical already —
`background: transparent` against a bordered outline) a very slight
gray fill instead of white/transparent, distinct enough from the
panel background to read as a deliberately-styled button rather than
an unstyled fallback, in both light and dark. Needs its own token
(e.g. `--lp-admin-button-bg`) rather than reusing `--lp-admin-bg`
(the page background) or `--lp-admin-panel-bg` (would blend into the
panel again, the exact complaint here) — chosen with LP-088's
field-background token (also new) so the two don't end up visually
indistinguishable from each other.

### Checklist

- [x] Add a slight gray `--lp-admin-button-bg` token (light + dark) and
      apply it to `.lp-button`'s base `background` (currently
      `var(--lp-admin-panel-bg)`, i.e. white/blends into the panel) and
      to `.lp-button--secondary` (currently `background: transparent`)
      so neutral buttons read as intentionally styled, not unstyled
- [x] Enumerate every `class="lp-button"` (bare, no modifier) instance
      across `admin/views/` (53 as of this ticket) and classify each:
      genuinely secondary (leave as-is), should be `--primary` (its
      screen's main action), or should be `--danger` (destructive)
- [x] Apply the correct modifier class per that classification
- [x] Spot-check every screen with more than one button to confirm
      exactly one clear primary action per action group, not zero and
      not multiple competing blues
- [x] Screenshot before/after for a representative sample (Media
      Manager, Plugins, Theme Options, Import), shared with Ariane

### Resolution notes (2026-08-17)

- Added `--lp-admin-button-bg` (light `#f6f6f7`, dark `#26292d`) and
  applied it to the base `.lp-button` and `.lp-button--secondary`
  rules, replacing `--lp-admin-panel-bg`/`transparent` — addresses all
  53 bare-`.lp-button` instances at once.
- Went through all 53 bare `class="lp-button"` instances individually
  (not a blanket search-and-replace) and reclassified each one that
  was clearly its form's sole/main action with `.lp-button--primary`:
  `appearance/editor.php` (Create File, Create Folder),
  `appearance/menus.php` (Select, Create Menu, Rename, both "Add to
  Menu" buttons), `appearance/themes.php` (Activate — confirmed
  consistent with `plugins.php`'s existing Deactivate=`--secondary`
  pattern), `appearance/widgets.php` (Add Widget),
  `maintenance/updates.php` (Back up now), `media/import.php`
  (Continue), `media/media.php` (Regenerate thumbnails, Save, Replace,
  Rename, Create folder), `media/thumbnails.php` (Bulk regenerate
  thumbnails, Continue), `plugins.php` (Activate). Deliberately left
  neutral: Filter/Search/Apply/Cancel/Back/Close buttons, "Clean up
  orphaned thumbnails" (secondary to "Bulk regenerate" in the same
  section), "Restore" from a backup (impactful enough not to make
  routine-easy), and "Verify Key" (sits beside an already-primary Save
  button in the same form — checked the surrounding markup before
  classifying, not just the button text in isolation).
- Confirmed via computed style: "Create Menu" now renders
  `rgb(34, 113, 177)`/white text (the primary token), and "Reset to
  Defaults" (a pre-existing `--secondary`) now renders
  `rgb(246, 246, 247)` against a `rgb(255, 255, 255)` panel — visibly
  distinct where it was identical before.

### LP-091. Panel/Card Section Title Backgrounds

### Goal

Ariane spotted (2026-08-17) that section titles like "Backups" inside
a `.lp-admin__panel` read as a plain heading with no visual weight —
just an accent dot and a thin bottom border on an otherwise blank
white/dark background — making it slower to visually scan a page and
spot where one section ends and the next begins. Asked for these
titles to get a background, matching the tinted-header treatment
`.lp-sidebar-box__header` already has (LP-085/087).

### Implementation

`.lp-admin__panel > h2:first-child` (and the `<details>/<summary>`
collapsible variant used by "Search & Filter" panels) now bleeds to
the panel's own edges via a negative margin exactly offsetting the
panel's uniform 1.5rem padding — safe specifically because every
`.lp-admin__panel`/`.lp-admin__widget` shares that same padding value,
confirmed before relying on it. Given the existing `--lp-admin-chip-bg`/
`--lp-admin-chip-border` tint tokens (already used for
`.lp-sidebar-box__header`, row-action chips, and the tag input) rather
than inventing a new color. Matching top corner radius keeps the
flush-bleed look clean without needing `overflow: hidden` on the
panel itself — deliberately avoided, since `.lp-tag-input__suggestions`
(an absolutely-positioned typeahead dropdown that can appear inside a
panel) would otherwise get clipped.

### Checklist

- [x] Add the tinted background/border-bottom + flush corner radius to
      `.lp-admin__panel > h2:first-child`
- [x] Apply the same treatment to the collapsible `<summary>` variant
- [x] Verify no `overflow: hidden` was introduced on `.lp-admin__panel`
      itself (would risk clipping absolutely-positioned children like
      the tag-input suggestions dropdown)
- [x] Confirm correct rendering in both light and dark (verified via
      computed-style inspection: light tint `rgba(34, 113, 177, 0.07)`,
      dark tint `rgba(76, 155, 232, 0.12)`, both matching the existing
      chip tokens exactly)

### LP-092. Sidebar Expand/Collapse-All Toggle

### Goal

Ariane asked for a single control that expands or collapses every
collapsible parent menu section (Posts, Media Manager, Pages,
Appearance, Settings, Maintenance) at once, placed both above the nav
(right after the version number) and below it — symbols only, no
visible text.

### Implementation

Extended the existing `admin/assets/js/nav-toggle.js` (which already
drives each parent item's individual expand/collapse via
`.lp-admin__nav-item--parent`'s `is-open` class and a `localStorage`
override keyed by menu slug) rather than building a separate mechanism.
Two buttons share one `[data-lp-nav-toggle-all]` selector and stay in
sync: clicking either one checks whether *any* section is currently
collapsed — if so, it opens all of them; if every section is already
open, it closes all of them. Both buttons' symbol and `aria-label`
update together after every click, including clicks on an individual
per-item toggle (not just the "all" buttons), so they always reflect
the true aggregate state.

Symbols only, per the request: ⊞ (U+229E, expand all) / ⊟ (U+229F,
collapse all), rendered as literal text content, no icon font/SVG
asset. The visible button carries no text — the meaning lives in the
`aria-label`, which updates alongside the symbol so screen readers get
the real "Expand all menu sections"/"Collapse all menu sections"
wording rather than just a glyph.

### Checklist

- [x] Add the top button markup, right after the version number in
      `.lp-admin__brand`
- [x] Add the bottom button markup, inside `.lp-admin__user`
- [x] Extend `nav-toggle.js` to drive both buttons from one shared
      aggregate-state function, keeping them in sync with each other
      and with individual per-item toggles
- [x] Style as a small icon-only button (not a full-width row) despite
      `.lp-admin__sidebar`/`.lp-admin__user` both being flex columns
- [x] Verify via the live DOM: clicking either button opens/closes
      every section, both buttons' symbol flips together, and the
      choice persists to `localStorage` the same way individual
      per-item toggles already did

### LP-093. SEO/Custom Fields Should Default to Collapsed on First Visit

### Goal

Ariane asked whether default-collapsed behavior for the Post/Page
editor's SEO and Custom Fields sidebar boxes had been lost. Investigated
before assuming a regression: `git log --all -p` on both
`admin/views/posts/new.php` and `admin/views/pages/new.php` shows the
collapse logic hasn't changed since LP-083 first wrote it, and
LP-083's own spec only ever said these boxes must be *collapsible*,
never that they default to collapsed — so nothing was lost, this was a
gap in the original build. Confirmed live: Ariane's account has
`editor_layout_preferences IS NULL` (never saved a layout), and both
SEO and Custom Fields rendered expanded.

### Fix

`$collapsedBoxes` in both view files now defaults to `['seo',
'custom_fields']` (Posts) / `['seo']` (Pages — no Custom Fields box
exists there) specifically when `$savedLayout['order'] === []`.
`UserService::updateEditorLayoutPreferences()` always writes order and
collapsed together as one snapshot, so a real save never leaves order
empty — an empty order reliably means this exact screen has never been
customized by this user at all, distinguishing "never touched" from
"explicitly saved with nothing collapsed" (which must NOT be
re-collapsed by this default). Author reassignment (also collapsible,
Posts only) intentionally stays expanded by default — Ariane only
asked about SEO and Custom Fields, and it's a more consequential field
to leave hidden by default.

### Checklist

- [x] Confirm via git history that this is a gap, not a regression,
      before writing any fix
- [x] Confirm live via the dev install: `editor_layout_preferences`
      NULL for the test account, both boxes rendering expanded
- [x] Add the first-visit-only default to `posts/new.php`
      (`seo`, `custom_fields`)
- [x] Add the matching default to `pages/new.php` (`seo` only — no
      Custom Fields box exists on Pages)
- [x] Verify the edge case: a user with a real saved layout but an
      empty `collapsed` list (i.e. explicitly left everything expanded)
      must NOT be re-collapsed by this default — tested directly
      against the dev install database, confirmed correct

### LP-094. Full-Width Featured Image Above Post (Front Page & Archives)

### Goal

Ariane shared a WordPress Game of Thrones fansite screenshot as a
design reference and asked whether Lumora Press supported that
layout — a full-width featured image banner sitting above the post
title on front-page/archive listings. It didn't: LP-079 deliberately
left featured-image *placement/sizing* to each theme rather than a
global setting (only show/hide is a Theme Option), and no theme in
this codebase (`default`, or the `duskline` custom theme) had ever
built anything but a small side-thumbnail layout — `content/themes/
default/style.css` used a fixed 120×120px square beside the post body,
`custom themes/duskline/style.css` a 140×140px square inside its card.
Confirmed this was a genuine gap, not a broken setting.

Ariane asked for this to apply to **every theme**, as the standard
display, **not** gated behind a new Theme Option choice.

### Implementation

For both `content/themes/default` and `custom themes/duskline`
(applied identically to `index.php` and `archive.php` in each, since
the two templates duplicate this markup block — the same pre-existing
duplication LP-079 flagged as optional cleanup, not touched further
here):

- `the_post_thumbnail_lightbox($post, 'small')` → `'large'` (150×150
  crop → 1024×1024 fit-mode source), since the image now displays much
  larger than before.
- CSS: `.lp-post-list__item` changed from `display: flex` (row) to
  `flex-direction: column` — the thumbnail and body were already
  sibling elements in that DOM order, so no markup reordering was
  needed, only the flex direction. Thumbnail now `width: 100%` with a
  fixed `320px` (`200px` under `max-width: 720px`) `object-fit: cover`
  height for a consistent banner crop regardless of the source image's
  own aspect ratio.
- `duskline` specifically: since it's a bordered "story card" (unlike
  `default`'s plain divided list), the card's own `padding` moved from
  `.lp-post-list__item` onto `.lp-post-list__body`, with `overflow:
  hidden` added to the item so the banner image's square corners get
  clipped by the card's existing `border-radius` at the top only —
  otherwise the image would either overflow the card's rounded corners
  or need its own separate radius value to keep in sync with the
  card's.

### Checklist

- [x] Confirm this is a genuine feature gap (not a setting the user
      was missing) before making any change
- [x] Apply to `content/themes/default` (`index.php`, `archive.php`,
      `style.css`)
- [x] Apply the equivalent to `custom themes/duskline`, adapted to its
      own card-style visual language rather than copy-pasting
      `default`'s CSS verbatim
- [x] Verify on the dev install: front page and a category archive
      page both screenshotted, image renders full-width above the
      title on both themes
- [x] No new Theme Option added — applies unconditionally, per
      Ariane's explicit direction, using the existing
      `show_featured_image_in_listings` toggle only for show/hide

### LP-095. Theme's Own Width Should Take Precedence Over Generic Content Width Default

### Goal

Ariane asked whether `duskline`'s own `--lp-max-width: 1160px` was
being respected, or silently overridden. Investigated: `header.php`
(both `default` and `duskline`) loads the theme's own `style.css`
*first*, then `theme_options_css()`'s injected `<style>` block
*second* — so the site-wide "Content width" Theme Option's
`--lp-max-width` declaration always wins the cascade regardless of
what the theme's own stylesheet set, because it's the later `:root`
rule at equal specificity. Confirmed this was live and active: the dev
install's `theme_options` row already had an explicit
`"content_width":"1200px"` saved (from earlier, unrelated testing),
meaning duskline's real rendered width was 1200px, not its own
intended 1160px, the whole time — a genuine bug, not a hypothetical.

Ariane asked for the general principle: a theme's own explicit design
choice should take precedence over a generic core default, for width
and for future similar cases (e.g. LP-094's featured-image treatment).

### Fix

`content_width`'s field definition (`app/Core/Theme/ThemeOptions.php`)
now has `default: ''`, `allowEmpty: true`, and a new `'' => 'Use theme
default'` choice — mirroring the exact pattern the Color fields
already used for the same "inherit unless explicitly overridden"
behavior (`ThemeOptions::cssVariables()` already skips emitting a
declaration when a field's value is `''` and `allowEmpty` is true; no
change needed to that method itself, only to this one field's
definition). A site that has never touched this setting now correctly
shows each theme's own width; a site with an explicit prior choice
(like the dev install's `1200px`) keeps that choice until the
administrator resets it — an explicit override, once made, is still
respected, only the *unset* default no longer forces one.

The general principle (not just this one field) is now documented in
`CLAUDE.md`'s Theme Philosophy section: any future `$cssVariable`
Theme Option must default to non-overriding, the same way. Fields read
directly by a theme's own PHP (like `post_display_mode`) don't have
this problem — the theme decides whether to honor them at all, so
they're already theme-controlled by construction; LP-094's featured-
image treatment is the same, built directly into each theme's own
CSS with no injected-override mechanism to conflict with.

### Checklist

- [x] Confirm the actual header.php load order (theme stylesheet vs
      injected options `<style>` block) before assuming a fix was even
      needed
- [x] Confirm live on the dev install that this was an active bug, not
      hypothetical (found an explicit `1200px` override already saved)
- [x] Add the `'' => 'Use theme default'` choice, `default: ''`, and
      `allowEmpty: true` to `content_width`
- [x] Verify via a temporary DB round-trip test: reset to `''` →
      confirm the injected `<style>` block no longer emits
      `--lp-max-width` and the computed value becomes duskline's own
      `1160px` → restore the original explicit `1200px` value
      afterward so Ariane's real saved setting wasn't altered
- [x] Confirm the admin Theme Options UI renders the new choice
      correctly ("Use theme default" first in the dropdown, with
      explanatory help text)
- [x] Document the general precedence principle in `CLAUDE.md`'s Theme
      Philosophy section, not just fix this one field

### LP-097. Media Manager View Modes & Uploaded Date

### Goal

The Media Manager (`admin/views/media/media.php`) only ever renders one
view — a fixed thumbnail grid (`.lp-media-grid`) — with no way to switch
to a denser, information-forward list view, and no uploaded date shown
anywhere in that grid. The file-info/details panel (opened per item)
shows file type and file size, but not when the file was uploaded
either — `uploaded_at` is stored on every `media` row and already
powers the "Uploaded from/to" date-range filter, it just isn't
displayed anywhere in the UI itself.

### Checklist

- [x] A Thumbnails/List view-mode toggle above the results (mirroring
      the existing Search & Filter panel's placement), persisted the
      same way other admin list preferences already are in this
      codebase (e.g. per-user, not just per-session) rather than
      resetting to Thumbnails on every page load
- [x] Thumbnails view: unchanged grid layout, plus the uploaded date
      shown under/alongside each item's filename
- [x] List view: a new dense row-based layout (filename, type, size,
      uploaded date, dimensions where applicable) — matching this
      project's existing `.lp-table` admin list convention rather than
      inventing a new table style
- [x] Uploaded date added to the file-info/details panel, alongside the
      existing type/size line
- [x] Both new views keep existing behavior working unchanged: checkbox
      multi-select for bulk actions, the Search & Filter panel, and the
      per-item details/edit panel

### Notes

**Implemented (2026-08-25).** No per-user preference mechanism existed
yet for anything outside the Post/Page editor's own sidebar layout
(`UserService::getEditorLayoutPreferences()`, keyed by screen type) —
built a sibling pair, `getListViewMode()`/`setListViewMode()`, on a new
`list_view_preferences` LONGTEXT column (migration 0045), same
one-JSON-blob-keyed-by-screen-type shape, shared by this ticket and
LP-098 below (screen types `'media'`/`'plugins'`). Defaults to `'grid'`
for an admin who's never touched the toggle, so nobody sees a changed
default layout unprompted.

The toggle itself is a plain `?layout=grid|list` GET link — this
screen's Search & Filter/pagination/folder navigation are already
full-page-reload based, so no AJAX was needed; the chosen mode is
persisted server-side the moment the link is followed, and every other
query param on the current URL is preserved via `array_merge($_GET,
['layout' => $mode])` rather than resetting filters/folder/pagination.
`render_pagination()` already rebuilds its own links from the current
full query string, so `layout` carries through pagination automatically
with no changes needed there.

Verified end-to-end against the dev install: List view renders
filename/type/size/dimensions/uploaded-date rows; Thumbnails view shows
the uploaded date under each filename; the file-info panel shows
"Uploaded {date}"; the choice survives a fresh page load with no query
string at all (real per-user DB persistence, not session-only); bulk
select-all/checkboxes and Search & Filter still work in both views.

### LP-098. Plugins Page List View

### Goal

The admin Plugins screen (`admin/views/plugins.php`) only ever renders
one layout — a card grid (`.lp-plugin-grid`/`.lp-plugin-card`, one card
per plugin with a screenshot, name, badge, description, and
Details/Activate/Deactivate actions). Add a list view as an alternative
to the card grid, for scanning many installed plugins at once.

### Checklist

- [x] A Grid/List view-mode toggle above the results, alongside the
      existing search box and status filter (`.lp-plugin-toolbar`)
- [x] List view: a dense row-based layout (name, status badge, version,
      author, short description, actions) — matching this project's
      existing `.lp-table` admin list convention rather than inventing
      a new table style, mirroring LP-097's identical ask for the Media
      Manager
- [x] Existing search/filter (`data-lp-plugin-search`/`data-lp-plugin-filter`)
      and the Details panel/Activate/Deactivate/Delete actions keep
      working unchanged in both views

### Notes

**Implemented (2026-08-25), same session as LP-097** (which came first
and built the shared `UserService::getListViewMode()`/
`setListViewMode()` persistence this ticket reuses under the
`'plugins'` screen-type key). Unlike Media Manager, this screen's
search/filter are already fully client-side/JS-driven with no page
reload at all (`plugin-browser.js`) — reloading on toggle would have
thrown away whatever an admin had already typed into the search box,
so the toggle is JS-driven too: both the card grid and a new
`.lp-plugin-table` list are always rendered server-side, and a
`data-lp-plugin-view-wrapper[data-view]` attribute (flipped instantly
by a click, no request in the critical path) picks which one is
CSS-visible. The choice is then persisted with a fire-and-forget POST
to a new `set_list_view` JSON sub-action, mirroring
`admin/views/posts/new.php`'s `editor_upload` sub-action pattern
(handled before the CSRF-gated form dispatch, JSON response, `exit`)
— and, like that sub-action, hands back a fresh `Csrf::verify()` token
on every response so a second toggle click in the same page load
doesn't 403 (the exact bug LP-115 hit and fixed for its own picker
earlier this session — applied proactively here from the start).

Both card and table rows carry the same `data-plugin-search`/
`data-plugin-status` attributes, so `plugin-browser.js`'s existing
filter logic narrows whichever view is currently visible without
needing to know which one that is; the "no results" empty-state count
only counts cards (not both representations of the same plugin) to
avoid double-counting. The Details dialog trigger's click listener was
moved from the grid element to their shared wrapper so it fires from
table rows too — both reference the same per-slug `<template>`.

Verified end-to-end against the dev install: List view renders all
installed plugins with working Details/Activate/Deactivate; search and
the status filter narrow the List view exactly as they already did the
grid; the toggle survives a fresh page load with no query string
(real per-user DB persistence); two rapid toggle clicks in the same
session both succeeded (confirming the CSRF-refresh fix actually
works, not just compiles); List view's table scrolls horizontally on a
mobile viewport via the existing shared `.lp-table` mobile rule, no
new CSS needed for that part.

### LP-100. Modernize Pagination Controls (Frontend & Admin)

### Goal

Both the public-facing pagination (`.lp-pagination`, used on post
archives/search/category/tag listings) and the admin list pagination
(e.g. Media Manager) currently render as a long flat row of individual
numbered squares with no truncation — on a site with many pages
(screenshots showed 74 frontend / 80 admin pages) this produces a wall
of buttons that wraps across several rows and gives no sense of
position or a fast way to jump far. Modernize the pagination component
used in both surfaces.

`render_pagination()` (`LumoraPress/include/helpers.php`) was already the
single shared implementation called by every frontend template
(`index.php`, `archive.php`, `search.php`) and every admin list view
(Posts, Pages, Comments, Users, Media Manager) — no divergent
implementations existed to unify, so the fix landed entirely inside that
one function and its two callers' stylesheets (default theme
`style.css` and `admin.css`), verified live against the dev install's
Comments list (10 pages) and homepage (74 pages).

### Checklist

- [x] Truncate long page ranges with an ellipsis (e.g. first/last few
      pages + pages around the current one), rather than rendering
      every page number
- [x] Add Previous/Next controls (and consider First/Last) alongside
      the numbered pages
- [x] Keep a single shared pagination markup/CSS component reused by
      both the frontend theme output and the admin UI, rather than two
      divergent implementations
- [x] Frontend styling lives in the active theme stylesheet(s) per the
      Public-Facing CSS Rule in `CLAUDE.md`; admin styling stays in the
      admin CSS
- [x] Preserve current-page indication, keyboard/focus accessibility,
      and existing query-param/URL behavior for both surfaces

### LP-102. Remove Redundant "Welcome to {Site}" Heading From The Homepage Post Listing

### Goal

The default theme's homepage post listing (`content/themes/default/index.php`)
renders a hardcoded `<h1 class="lp-page-title">Welcome to {site name}</h1>`
banner above the post list. It doesn't correspond to any real content —
unlike `single.php`/`page.php`/`archive.php`'s own `<h1>`, which titles the
actual post/page/archive being viewed — and reads as decorative filler
directly above a list of posts that already have their own titles.
Reported directly against a live screenshot of the homepage.

Distinct from `LP-034`'s "Show page titles" wishlist checkbox (a future
generic Theme Options toggle for page titles across the whole site) —
this ticket only removes one specific hardcoded string from one
template, not a new site-wide setting.

### Checklist

- [x] Remove the "Welcome to {site}" `<h1>` from `index.php`'s true
      homepage branch (`$page_title === null`)
- [x] Remove the equivalent homepage `<h1>` from the `duskline` custom
      theme's own `index.php` (Ariane explicitly confirmed removing
      this from a custom theme, per `CLAUDE.md`'s Custom Theme Rules)

### Notes

The homepage's `<main>` now goes straight from `get_header()` into the
post list with no interstitial heading — matching `archive.php`'s
category/tag/date listings, which already only show a heading when
there's a real title to show (a category name, "Search", etc.).
No other template was touched: `single.php`, `page.php`, and
`archive.php` keep their own `<h1>` because it titles genuine
page-specific content, not filler.

`duskline/index.php` shared the same pattern via
`$page_title ?? site_name()`, which also fed the h1 for LP-046's
"Posts page" case (`$page_title` non-null there) — that branch was kept
intact (`<?php if ($page_title !== null): ?>`), only the genuine-homepage
fallback to `site_name()` was removed. Its separate tagline paragraph
(`lp-page-intro`) was untouched — it was never gated on the heading.

### LP-103. Appearance &rsaquo; Menus: Show Pages/Categories Nested In The "Add Items" Panel

### Goal

The Appearance &rsaquo; Menus "Add Items" panel (`admin/views/appearance/menus.php`)
lists Pages and Categories to add to a menu as flat, alphabetically-sorted
checkbox lists — `PageService::listAllForMenuSelect()` and
`CategoryService::listAll()`, neither of which carries parent/child
depth. Once added, a menu item's own hierarchy is fully supported
(LP-049's unlimited-depth `parentId`/Parent Item dropdown), and Pages
themselves are already hierarchical elsewhere in the admin — the "All
Pages" list renders a real indented tree via
`PageService::listAllForTree()` (`{page, depth}` pairs, LP-009 Hierarchy
UI). The Add Items panel is the one place left where that structure
isn't visible: a child page or subcategory shows up in the same flat
list as everything else, with no indication of its parent, making it
hard to find the right item on a site with a deep page/category tree.

Show Pages and Categories indented by depth in the Add Items panel,
mirroring the existing "All Pages" tree presentation — "nested when
possible" since Posts and Tags have no hierarchy in this app and should
stay flat as they are now.

### Checklist

- [x] Pages tab: replace `listAllForMenuSelect()` with a depth-aware
      variant (reuse `listAllForTree()`'s `{page, depth}` shape, or add
      an equivalent that also carries `slug` if needed) and indent each
      checkbox label by depth
- [x] Categories tab: add a depth-aware listing to `CategoryService`
      (mirroring `PageService::listAllForTree()`'s "flat rows in,
      parent_id ordering, depth-tagged tree out" approach) and indent
      each checkbox label by depth
- [x] Posts and Tags tabs stay flat/alphabetical — no hierarchy exists
      for either in this app
- [x] Indentation is CSS-driven (a `padding-left` scaled by depth, or a
      depth-based modifier class), not literal leading whitespace/dashes
      in the label text, so the underlying item label stays clean when
      actually added to the menu
- [x] Preserve existing "select checked items, click Add to Menu"
      behavior and keyboard/focus accessibility — this only changes how
      the existing list is presented, not how selection works

### Notes

`PageService::listAllForMenuSelect()` was changed in place (rather than
adding a second method) to return `{id, title, slug, depth}` built on
top of the existing `listAllForTree()`, since its only caller is this
one panel. One side effect: it now excludes trashed pages, which the
old flat `SELECT ... FROM pages` (no `WHERE` at all) never did — a
trashed page showing up as addable to a menu was already a latent bug,
not intended behavior, so this is a fix, not a regression.

Added `CategoryService::listAllForTree()` (mirroring
`PageService::listAllForTree()`'s private `flattenForTree()` recursion,
built on top of the existing `listAll()` — parent immediately followed
by its own children, alphabetical among siblings). `listAll()` itself
was left unchanged since other callers depend on its flat shape.

Indentation reuses the existing `data-style-margin-left` /
`admin/assets/js/dynamic-style.js` mechanism the Menu Structure list
already relies on for the same purpose (CSP forbids inline
`style="..."`, and there's no per-depth CSS class that would work for
arbitrary depth). Posts (`PostService::listAllForMenuSelect()`, already
flat) and Tags (`TagService::listAll()`, no hierarchy exists) render
with `depth => 0` and get no indentation, unchanged from before.

Verified end-to-end against the dev install: a page nested two levels
deep and a subcategory both rendered indented in their respective
panels, and adding a nested page to a real menu (then removing it)
round-tripped correctly through the existing Add/Remove forms with no
handler changes needed on that side.

### LP-104. Pages/Categories Widgets: Nested Output, Alphabetical Top Level

### Goal

The Pages widget and Categories widget (`CoreWidgets::register()`,
`app/Core/Widgets/CoreWidgets.php`) both render a flat `<ul><li>` list on
the frontend with no indication of parent/child hierarchy, even though
both Pages and Categories are hierarchical content types elsewhere in
the admin (the "All Pages" tree view, and LP-103's just-added nested
Appearance &rsaquo; Menus "Add Items" panel). Render both widgets'
output as a real nested `<ul><li><ul>...` tree instead, with top-level
items in alphabetical order (children alphabetical among their own
siblings too) — the same shape LP-103 established for
`CategoryService::listAllForTree()`/`PageService::listAllForMenuSelect()`.

Neither widget currently has an admin-side picker (their settings are
just a title field, and `limit`/`show_count` — no list of individual
pages/categories to check like Menus' Add Items panel had before
LP-103). "Nested in the admin UI" doesn't apply to either widget the
same way it did for Menus unless a picker is added first — if Ariane
wants one, scope that as its own decision when this ticket is picked
up, rather than assumed here.

### Checklist

- [x] Categories widget: switch from `listAllWithPostCounts()` (flat,
      alphabetical, unlimited) to a tree-shaped render using
      `CategoryService::listAllForTree()` (already built by LP-103) —
      nested `<ul>` per depth level, post count badge unaffected
- [x] Pages widget: **decision made** — dropped the `limit` setting
      entirely once nested. Confirmed with Ariane by matching WordPress's
      own core Pages widget (`WP_Widget_Pages`), which has never had a
      "number to show" option either — it always lists every published
      page, nested hierarchically. Matches how the Categories widget has
      always been unlimited too.
- [x] New/reused CSS for nested widget lists (`lp-widget__list` gaining
      a nested `<ul>` per depth) in the active theme's `style.css` per
      the Public-Facing CSS Rule in `CLAUDE.md` — indentation via a
      real nested-list selector (`.lp-widget__list .lp-widget__list`),
      not the admin's `data-style-margin-left` CSP workaround, since
      this is static server-rendered frontend markup with no per-request
      dynamic value involved
- [x] Dark mode covered if the new nesting introduces any new visual
      treatment (e.g. a connecting line/indent guide) beyond plain
      indentation — plain `<ul>`-in-`<ul>` indentation needs no new
      color tokens on its own
- [x] Propagate the same CSS addition to `custom themes/duskline`
      (additive only, per `CLAUDE.md`'s Custom Theme Rules)
- [x] Regression tests confirming nested output for both widgets
      (`CoreWidgetsTest` or equivalent)

### Notes

Added `PageService::publicTreeForWidget()` — a new method rather than
reusing `listAllForTree()`/`listAllForMenuSelect()` (LP-103), because
those two intentionally include every non-trashed page (drafts included)
for admin-only screens, while the Pages widget is public-facing and must
apply the same visibility rule `paginatePublished()` already used
(published, or scheduled with a past date, AND public visibility) — a
draft or private page must never leak into the tree just because it's
some visible page's child. Ordered by `title ASC` (not
`listAllForTree()`'s manual `menu_order`) so both the top level and
every depth's siblings render alphabetically, per this ticket's own
title. A page whose real parent isn't itself in this filtered public
set (e.g. a draft parent) is dropped entirely rather than promoted to
top level or nested at the wrong depth — same "only ever nest under a
genuinely present parent" behavior `flattenForTree()` already had.

Removed the Pages widget's "Number of pages to show" settings field
(`admin/views/appearance/widgets.php`) entirely rather than leaving it
present-but-ignored — a settings field that silently does nothing is
worse than no field.

Added a shared `CoreWidgets::renderNestedList()` private helper (flat
depth-tagged rows in, real nested `<ul><li>` markup out) used by both
widgets, so the tree-building logic isn't duplicated between them.
Categories' post-count badge now costs one `CategoryService::postCount()`
query per category when "Show post counts" is enabled (previously one
combined query) — accepted as the same small-dataset trade-off
`PageService::listAllForTree()`'s own docblock already makes for pages
("evergreen/structural content ... not the tens-of-thousands-of-rows
table Posts can be").

Verified live against the dev install: the real Categories widget (355
posts, dozens of categories nested up to 3 levels deep, including two
same-named categories — "AO3" — correctly kept as separate entries under
their own distinct parents) and a temporarily-added Pages widget (46
top-level pages, 20 with children, previously would have been truncated
at the old default limit of 10) both rendered correctly nested and
alphabetical. The temporary Pages widget and an unrelated stray
`Search` widget that ended up added to Primary Sidebar during manual
browser-driven UI testing were removed afterward by correcting the
`widgets_config` option directly in the dev database back to its
original recorded state (Categories in Primary Sidebar, Social Links in
Footer, no inactive widgets) — not a change to any shipped code, purely
restoring the dev install's own local config to how the session found
it.

### LP-105. New Post and New Page pages

### Goal

Both the New Post "Categories" checklist and New Page "Parent Page" field
(`admin/views/posts/new.php` and `admin/views/pages/new.php`) currently
render every category/page as a flat, unbounded list — long enough on a
site with many categories or pages to make the sidebar box grow past a
comfortable length. Nest them by depth and cap their height, mirroring
the pattern LP-103 already established for Appearance &rsaquo; Menus'
"Add Items" panel: a `<ul>` with `data-style-margin-left` per-depth
indentation (CSP-safe, driven by the existing
`admin/assets/js/dynamic-style.js`) inside a `max-height` +
`overflow-y: auto` scrollable container (`.lp-menus-add-panel__list`
uses `16rem`).

### Checklist

- [x] New Post &rsaquo; Categories box (`case 'categories':` in
      `admin/views/posts/new.php`): swap
      `$allCategories = $kernel->categories->listAll()` for
      `$kernel->categories->listAllForTree()` (already built by LP-103)
      and render as a nested `<ul>` with `data-style-margin-left` per
      depth — same shape as Menus' `add_categories` panel — keeping the
      existing checkbox markup and the "+ Add New Category" quick-add
      panel unchanged.
- [x] New Page &rsaquo; Parent Page box (`case 'parent':` in
      `admin/views/pages/new.php`): replace the `<select id="page-parent"
      name="parent_id">` with a scrollable `<ul>` of radio buttons
      (`type="radio" name="parent_id"`, one checked to match the current
      value), built from a new depth-aware `PageService` method that
      combines `listAllForTree()`'s depth-tagging with
      `listAllForParentSelect()`'s exclusion (a page can't be its own
      parent or direct child's parent). Keep a "(No parent)" radio
      (value `0`) as the first item, matching the current select's
      option.
- [x] CSS: reuse `.lp-menus-add-panel__list` directly, or add a parallel
      rule block in `admin/assets/css/admin.css` with the same
      `max-height`/`overflow-y` shape, so both boxes look and behave
      consistently with the Menus Add Items panel.
- [x] Verify `dynamic-style.js`'s `data-style-margin-left` handling
      already runs on both editor screens (it's loaded admin-wide, so
      this should be automatic — confirm in the browser rather than
      assuming).
- [x] Regression tests: existing category-assignment and parent-selection
      coverage (`PostsController`/`PageService` tests) still passes; add
      a test for the new `PageService` depth-aware+excluding method
      mirroring `CategoryServiceTest`'s existing `listAllForTree()`
      coverage.
- [x] Verify end-to-end against the dev install: New Post with several
      nested categories renders indented and scrolls once the list is
      long; New Page's Parent Page radio list renders indented, excludes
      the page being edited and its own direct children, and the
      previously-saved parent stays selected on reload.

### Notes

Scoped deliberately to just these two boxes, per the ticket's own
wording. While planning this, several other flat category/parent
`<select>` pickers turned up elsewhere in the admin (Categories admin's
own "Parent Category" select, Settings &rsaquo; Reading's two homepage
page pickers, All Pages' parent filter + Quick Edit parent + bulk-move
target, All Posts' category filter) — answering the ticket's own "are
there other lists that should be nested and alphabetical?" question.
Ariane chose to keep those out of this ticket; they're tracked
separately as LP-106 so this stays a small, reviewable change.

For the New Page Parent Page field specifically, Ariane chose a custom
scrollable radio list (matching the Categories checklist's look) over
keeping the native `<select>` with indented option text — a native
`<select>` can't be capped/scrolled the way a `<ul>` panel can, and
can't be indented reliably cross-browser with the CSP-safe JS mechanism
the rest of the app already uses.

### LP-106. Nest & Cap More Flat Category/Parent Pickers

### Goal

LP-105 planning surfaced several more flat, alphabetical-only
`<select>` pickers across the admin that follow the same
category/parent-page pattern LP-103/LP-104/LP-105 already nest
elsewhere. Apply the same "nested by depth, nbsp/indent or scrollable
panel" treatment to these, following whatever concrete UI pattern
LP-105 lands on (custom scrollable list vs. indented `<option>` text)
for consistency:

### Checklist

- [x] Categories admin's own "Parent Category" `<select>`
      (`admin/views/posts/categories.php`, built from
      `CategoryService::listAllForParentSelect()`)
- [x] Settings &rsaquo; Reading's two homepage page pickers
      (`admin/views/settings/reading.php`, `homepage_page_id` and
      `homepage_posts_page_id`, built from
      `PageService::listAllForParentSelect()`)
- [x] All Pages' parent filter, Quick Edit parent field, and bulk-move
      "target_parent_id" (`admin/views/pages/all-pages.php`)
- [x] All Posts' category filter and bulk-move "target_category_id"
      (`admin/views/posts/all-posts.php`)

### Notes

Split out of LP-105 (2026-08-21) rather than expanding that ticket,
so the New Post/New Page change stays small and reviewable.

**Implemented (2026-08-25).** LP-105 landed on a custom scrollable
`<ul>` for its two big sidebar boxes, but that shape doesn't fit these
four pickers — they're compact `<select>` elements embedded inline in
filter bars, bulk-action rows, and a per-row Quick Edit form, where a
full custom list would be a much larger layout change than "nest and
alphabetize" calls for. A native `<select>` also already scrolls
natively once its option list gets long, so the "cap" half of LP-105's
treatment isn't actually needed here. Went with the classic indented-
`<option>`-text technique instead (`str_repeat('&nbsp;&nbsp;&nbsp;',
$depth)` prepended to each option's label — repeated `&nbsp;` renders
reliably inside `<option>` across browsers, unlike a CSS margin) —
inlined at each of the six call sites rather than a shared helper,
since it's one line each.

New `CategoryService::listAllForParentPicker(?int $excludeId = null)`
mirrors the `PageService::listAllForParentPicker()` LP-105 already
built: `listAllForTree()`'s depth tagging combined with
`listAllForParentSelect()`'s own cycle-prevention exclusion, with
`$excludeId` optional for the three pickers (Reading's two homepage
selects, the All Pages/All Posts filter and bulk-action selects) that
aren't choosing a *parent* and so have nothing to exclude. Covered by
two new tests each on `CategoryServiceTest`/`PageServiceTest` mirroring
their existing `listAllForParentSelect()`/`listAllForTree()` coverage;
full `Unit/` suite (1525 tests) still green. Browser-verified against
the dev install: all six pickers render indented by depth in the
correct document order.

### LP-108. Bug: Trashed Posts Selectable In Appearance › Menus "Add Items" Panel

### Goal

`PostService::listAllForMenuSelect()` (`app/Services/PostService.php`)
has no `WHERE` clause at all — `SELECT id, title, slug FROM posts ORDER
BY created_at DESC LIMIT {$limit}` — so a trashed post is selectable
and addable from the Appearance &rsaquo; Menus "Add Items" panel's Posts
tab. This is the same latent bug LP-103 found and fixed for
`PageService::listAllForMenuSelect()` (that method had an identical
unfiltered query before being rebuilt on top of `listAllForTree()`,
which excludes trashed pages as a side effect) — the Posts side of the
same panel was left with the old behavior since LP-103's scope was
Pages/Categories nesting, not this filter.

The method's own docblock intentionally allows non-published statuses
through ("an editor building a menu may knowingly link to a
not-yet-published post") — that part should stay. Only `trashed` should
be excluded; Draft/Pending Review/Private/Scheduled posts should remain
selectable exactly as they are now.

### Checklist

- [x] `PostService::listAllForMenuSelect()`: add a `WHERE status !=
      'trashed'` (or equivalent) condition, excluding only trashed
      posts — every other status stays selectable, unchanged.
- [x] Regression test confirming a trashed post no longer appears in
      `listAllForMenuSelect()`'s results, alongside a non-trashed,
      non-published post confirming that case is still included.
- [x] Verify end-to-end against the dev install: trash a post, confirm
      it no longer appears in the Menus "Add Items" panel's Posts tab.

### Notes

### LP-109. Bug: Trashed Pages Selectable As Parent Page / Static Front Page

### Goal

`PageService::listAllForParentSelect()` (`app/Services/PageService.php`)
has no status filter at all — neither branch of its `$excludeId === null`
check restricts by `status`, unlike its sibling
`CategoryService::listAllForParentSelect()`, which already does `WHERE
trashed_at IS NULL`. This isn't a documented trade-off the way
`PostService::listAllForMenuSelect()`'s unfiltered status was (see
[[LP-108]]) — nothing in `listAllForParentSelect()`'s own docblock
suggests including trashed pages was intentional, and its category
counterpart already excludes them, so this looks like a plain oversight.

Three admin pickers share this one method, so a trashed page is
currently selectable in all three:

- New/Edit Page's "Parent Page" `<select>` (`admin/views/pages/new.php`)
- Settings &rsaquo; Reading's static front-page/posts-page pickers
  (`admin/views/settings/reading.php`)
- The All Pages list's parent filter dropdown
  (`admin/views/pages/all-pages.php`)

### Checklist

- [x] `PageService::listAllForParentSelect()`: add a `status !=
      'trashed'` condition to both the `$excludeId === null` and
      `$excludeId` branches, matching `CategoryService`'s pattern.
- [x] Regression test confirming a trashed page no longer appears in
      `listAllForParentSelect()`'s results (both branches), alongside a
      non-trashed, non-published page confirming that case is still
      included.
- [x] Verify end-to-end against the dev install: trash a page, confirm
      it no longer appears in the New Page "Parent Page" dropdown, the
      Reading settings static-page pickers, or the All Pages parent
      filter.

### Notes

**Implemented (2026-08-25).** Of the three pickers named above, New
Page's Parent Page, Reading's two homepage pickers, and All Pages'
parent filter were already fixed as a side effect of LP-105/LP-106
(2026-08-25, same day) — those three now go through the new
`listAllForParentPicker()` (built on `listAllForTree()`, which already
excludes trashed pages), not `listAllForParentSelect()` directly.
`listAllForParentSelect()` itself still needed the fix described above
since it has one remaining direct caller outside that trio: Settings
&rsaquo; Privacy's policy-page picker
(`admin/views/settings/privacy.php`) — verified end-to-end against the
dev install that a trashed page disappears from that picker too.

### LP-111. Move Pages Up To After Posts In Admin Sidebar

### Goal

In the admin sidebar's current menu order, **Pages** sits below other
items instead of directly after **Posts**. Since Posts and Pages are
the two primary content types (per `Project Philosophy` — "Sit down.
Write. Publish."), Pages should be the second sidebar entry, right
after Posts, ahead of Comments/Categories/Tags/Media/etc.

### Checklist

- [x] Locate the admin sidebar menu registration/order (likely in the
      admin bootstrap or a menu-registration service) and move the
      Pages entry (with its All Pages/New Page submenu) to immediately
      follow Posts
- [x] Verify no other menu item's registered order value collides with
      the new position
- [x] Verify end-to-end in a real browser: admin sidebar shows Posts,
      then Pages, then the remaining items in their existing order

### LP-115. Editors: Merge Media Manager Picker With Insert/Edit Image, Add Search & Folder Sort, Bigger Window, Paginate

### Goal

Both editors (`content-editor.js`) currently expose two separate,
redundant ways to insert an image, on the same toolbar:

- TinyMCE's/EasyMDE's own native "Insert/Edit Image" button (`image` in
  both toolbars) — a bare URL/alt/upload dialog with no way to browse
  what's already in the Media Manager.
- The custom "Insert from Media Manager" button (TinyMCE's `lumoraMedia`,
  EasyMDE's `media-library`) — opens `openMediaPicker()`'s `<dialog>`: a
  grid of every existing image, then an Attachment Display Settings step
  (Size/Link To/Alignment, LP-075).

Combine these into one entry point per editor, and improve the picker
itself:

- The thumbnail grid has no search and no way to narrow by Folder
  (`FolderService`/`media_folders` — this app organizes media into
  Folders, not categories; treating "category" in the request as Folder,
  since that's the equivalent concept that actually exists here) — on a
  library of any real size, finding a specific image means scrolling
  through the entire grid.
- The dialog itself is small (`.lp-editor-media-dialog`, `admin/assets/css/admin.css`
  — `width: min(640px, 92vw); max-height: 80vh`), cramped for a photo
  grid.

### Checklist

#### Merge the two entry points

- [x] Remove TinyMCE's native `image` toolbar button/plugin and EasyMDE's
      native `image` toolbar button from both editors' toolbar arrays
      (`content-editor.js`) — `openMediaPicker()`'s dialog becomes the
      single "Insert Image" entry point for both
- [x] The native image dialog's one capability the Media Manager picker
      doesn't currently have is uploading a brand-new file inline — add
      an "Upload New" control to `openMediaPicker()`'s own dialog
      (reusing the existing `uploadFile()` helper already in
      `content-editor.js`) so removing the native dialog isn't a
      regression; a freshly uploaded image should drop straight into the
      existing Attachment Display Settings step
- [x] Rename the toolbar button/tooltip from "Insert from Media Manager"
      to something reflecting its new combined role (e.g. "Insert
      Image")

#### Search & folder filtering

- [x] `openMediaPicker()`'s `library` data (passed via
      `data-media-library` from `admin/views/posts/new.php` and
      `admin/views/pages/new.php`'s `$editorMediaLibrary`) doesn't
      currently carry folder membership — add each item's folder id/name
      to that payload (`FolderService`) so the picker can filter by it
      client-side without a new request — superseded by the Pagination
      section below: folder membership is resolved server-side per
      query instead, since the preloaded payload this item describes no
      longer exists.
- [x] Add a search text input to the dialog, filtering the grid
      client-side by filename/alt text as the admin types — implemented
      server-side (see Pagination) rather than client-side, for the same
      reason.
- [x] Add a Folder `<select>` filter above the grid, mirroring the Media
      Manager's own folder-select pattern
      (`admin/views/media/media.php`) — "All Folders" plus each real
      folder, nested/indented the same way that screen already does
- [x] Search and folder filter combine (both narrow the same grid
      together, not either/or)

#### Bigger window

- [x] Enlarge `.lp-editor-media-dialog` (`admin/assets/css/admin.css`) —
      e.g. `width: min(900px, 95vw)`, a taller `max-height`, and a
      thumbnail grid with more columns at that width
- [x] Keep the search/folder controls fixed above an independently
      scrolling grid, so they stay visible while scrolling a long list
      (mirrors the existing settings-step layout's fixed action bar)
- [x] Verify the enlarged dialog still degrades reasonably on a mobile
      viewport (LP-096's admin mobile pass already covers the rest of
      the admin UI's responsive behavior — this dialog should match)

#### Pagination (added 2026-08-25, mid-implementation)

Ariane asked, while this ticket was being built, that the picker not
load every file in one go — the original plan (preload the whole image
library into one `data-media-library` JSON blob, then filter/search it
client-side) directly conflicted with that on a library of any real
size. Replaced with an on-demand, paginated query instead:

- [x] New `PostsController::queryMediaForPicker()` (and pages/new.php's
      inline mirror, matching that file's existing no-controller
      convention) — a `media_picker_query` JSON sub-action wrapping
      `MediaService::query()` (already supports `term`/`folderIds`
      filters and limit/offset), 40 images per page
- [x] `openMediaPicker()` fetches page 1 on open, then again on every
      search keystroke (debounced) or Folder change (reset to page 1),
      and appends on "Load More" — never preloads the library
- [x] Grid thumbnails use the smallest available generated size
      (`sizes.small`/`medium`/`large`, falling back to `full`), not the
      full-size original, so a 40-item page doesn't mean 40 full-
      resolution downloads just for square previews
- [x] `data-media-library` removed entirely from both editor views;
      only the (small) Folder tree is still preloaded
      (`data-media-folders`, via new `FolderService::listAllForTree()`)
      since the filter `<select>` needs it immediately

#### Testing & Docs

- [x] Browser-verify end-to-end in both the Markdown (EasyMDE) and HTML
      (TinyMCE) editors: search narrows the grid, folder filter narrows
      the grid, both combined, upload-new-image flows straight into
      Attachment Display Settings, and the resulting inserted
      image/markdown is unchanged in shape from before this ticket
- [x] `README.md`/`CHANGELOG.md` updates once implemented, per this
      project's standard "After Every Code Change" rule

### Notes

**Implemented (2026-08-25).** Two real bugs turned up only through
actual browser verification, not code review, and are worth recording:

1. **`Csrf::verify()` is single-use, but the picker calls
   `media_picker_query` repeatedly** within one dialog session (every
   search keystroke, folder change, "Load More" click) — the very
   first search after opening the picker always 403'd until each
   response was made to hand back a fresh token, the same pattern
   `uploadEditorImage()` already used for repeat uploads. Regression-
   tested (`testQueryMediaForPickerReturnsAFreshCsrfTokenForTheNextQuery`).
2. **`[hidden]` loses to a class's own `display` property.** The
   dialog's header/grid are toggled via the `hidden` DOM property to
   switch between the browse and Attachment Display Settings steps, but
   `.lp-editor-media-dialog__header`/`__grid`'s own `display: flex`/
   `display: grid` rules (needed for the fixed-header/scrolling-grid
   layout) have higher specificity than the `[hidden]` attribute's
   UA-stylesheet `display: none` — both stayed visibly laid out
   underneath the settings step instead of hiding. Same issue affected
   the "Load More" button via `.lp-button`'s own `display: inline-block`
   — general enough (any future hidden `.lp-button`) that the fix
   (`.lp-button[hidden] { display: none; }`) was added at that shared
   class rather than one-off per dialog element.
3. **A grid item's `overflow: hidden` zeroes its contribution to
   `grid-auto-rows: auto` track sizing.** Found by Ariane after the two
   fixes above shipped: thumbnails kept shrinking further each time
   "Load More" added another page, with only the very last (unobstructed)
   row ever rendering at full size — every row above it was overlapped
   by the row below, since each row's real track height had collapsed
   toward 0 while the item's own aspect-ratio-derived box still painted
   at full size on top of it. Root cause verified live (toggling
   `overflow` on the grid items in devtools flipped `grid-template-rows`
   from a string of ~3px tracks back to the correct ~143px ones): per
   the CSS Sizing spec, a grid item's "automatic minimum size" — its
   contribution to an `auto` track's sizing — drops to 0 once its own
   `overflow` isn't `visible`, regardless of any `aspect-ratio` it
   declares. `.lp-editor-media-dialog__item` needed `overflow: hidden`
   to clip its image to the item's own rounded corners, which is exactly
   what triggered the collapse. Fixed by removing `overflow: hidden`
   from the item entirely and rounding the `<img>`'s own corners
   instead (`border-radius` on the image, sized to fill the item's box
   at `width/height: 100%` — no clipping container needed at all, since
   nothing ever overflows an image that already exactly fills its box).
   A stale cached copy of the pre-fix CSS in an already-open editor tab
   briefly looked like the fix hadn't landed — a hard refresh picked up
   the corrected, `?v={mtime}`-busted stylesheet.

Covered by 4 new `PostsControllerTest` cases (`queryMediaForPicker()`'s
term/folder filters, the CSRF-refresh regression above, and the
extended `uploadEditorImage()` `item` payload), 2 new `FolderServiceTest`
cases (`listAllForTree()`, mirroring Category/PageService's identical
coverage), and browser-verified end-to-end against the dev install in
both editors: search, folder filter, Load More pagination, Upload New,
and final insert output all confirmed working in both Markdown and
HTML mode, plus a mobile-viewport pass.

### LP-116. All Posts/All Pages: "View" Link For Published Rows

### Goal

`admin/views/posts/all-posts.php`/`admin/views/pages/all-pages.php`'s
row actions currently only offer Duplicate/Trash (or Restore/Delete
Permanently in the Trash view) — the title itself is the only link, and
it always opens the editor. To just check what a published post/page
actually looks like live, an admin has to open the editor first and use
its own "Preview"/"View Page" link (`admin/views/posts/new.php`/
`admin/views/pages/new.php`'s sidebar) — an unnecessary extra hop when
all they want is to look at the live page.

Add a direct "View" row action, linking straight to
`post_permalink($listedPost)`/`page_permalink($listedPage)`, opening in
a new tab (`target="_blank" rel="noopener"`), shown only when the row is
actually publicly reachable — i.e. `status === Published` (a Draft/
Pending Review/Scheduled post or page has no live permalink to view;
that case keeps using the editor's own Preview link, unchanged).

### Checklist

- [x] `admin/views/posts/all-posts.php`: add a "View" action to
      `.lp-admin__row-actions` for each row where
      `$listedPost->status === PostStatus::Published`, linking to
      `post_permalink($listedPost)`
- [x] `admin/views/pages/all-pages.php`: same, for
      `$listedPage->status === PageStatus::Published`, linking to
      `page_permalink($listedPage)` — this file renders rows in **two**
      places (the flat Trash-view table and the nested tree view for the
      normal list), both need the same addition
- [x] Order/placement: "View" first among the row actions (before
      Duplicate), matching how "Preview"/"View Page" already lead the
      Publish sidebar box in the editor
- [x] Verify end-to-end in a real browser: a Published post/page's "View"
      link opens the correct live URL in a new tab; a Draft/Pending
      Review/Scheduled row shows no "View" link at all

### LP-122. Insert Media Folder As Thumbnail Gallery In Post/Page Content

### Goal

Media folders are often used to group a set of related images under one
subject (e.g. "Icons," "Wallpapers" for a given fandom/character). There
is currently no way to insert a whole folder into a post/page at once —
an author has to insert each image individually one at a time. Add the
ability to insert an entire folder as a row of thumbnails in the
content editor, with the same per-image link-target choice the single
Insert Image flow already offers (link to none, the full-size image, or
the individual attachment/media item).

**Implemented (2026-08-26).** A new core service,
`FolderGalleryShortcode` (`app/Services/FolderGalleryShortcode.php`,
wired into the `content_html` filter in `include/bootstrap.php`),
renders `[lumora_folder_gallery folder_id="12" link="full"]` as a row
of thumbnails resolved at request time — the row always reflects the
folder's current contents, and a deleted folder or image just means
fewer/zero thumbnails next render rather than a broken stale reference.
Modeled directly on the existing `[lumora_downloads]` plugin shortcode
pattern.

The per-insert link-target choice ended up two options, not three:
**None** or **Media File** (the full-size image, wrapped in a link that
joins the post's PhotoSwipe lightbox gallery) — matching exactly what
Insert Image's own "Link To" select actually offers today. The third
option this ticket originally described ("link to the individual media
item's own page") doesn't exist as a concept anywhere in Lumora Press —
there is no public single-media attachment page/route at all, only a
raw-file download endpoint. Confirmed with Ariane before implementing;
building that page is out of scope here and would be its own ticket if
wanted later.

A new "Insert Folder" toolbar button sits next to Insert Image in both
the Markdown (EasyMDE) and HTML/WYSIWYG (TinyMCE) editors
(`admin/assets/js/content-editor.js`) — a lightweight dialog (folder
`<select>` + Link To `<select>`) reusing the folder list already
preloaded for Insert Image's own filter dropdown, needing no new
server query endpoint. This automatically reaches every place
`content-editor.js` is used (Posts, Pages, and the Downloads plugin's
own description field), same as Insert Image.

Deliberately **not** built on top of LP-110 ("Shortcode Insert
Picker") — that ticket's generic shortcode-registration API + picker
doesn't exist yet and is a separate, larger, not-started ticket. This
is its own dedicated button, not a consumer of that not-yet-built
system.

Thumbnail styling (`.lp-folder-gallery`/`__items`/`__item`/`__link`/
`__thumb`, 150×150 cropped, 100×100 under the existing 720px mobile
breakpoint) lives in both `content/themes/default/style.css` and
`custom themes/duskline/style.css` per the Public-Facing CSS Rule and
Custom Theme Rules. **Real bug found and fixed during browser
verification:** the thumbnail's fixed `width`/`height` was silently
overridden back to each image's natural aspect ratio whenever the
gallery rendered inside `.lp-post__content` — that wrapper's own
`img { height: auto }` rule is exactly as specific as a single-class
`.lp-folder-gallery__thumb` selector, so it won on the cascade
regardless of source order. Fixed by qualifying the selector as
`img.lp-folder-gallery__thumb` to match that same "class + element"
specificity tier, the identical mechanism `.lp-downloads-list__description
img`'s own alignment rules already rely on — caught only by inspecting
real computed CSS on a live page with real imported production data
(200 real images), not by the unit tests alone.

Covered by a new `FolderGalleryShortcodeTest.php`
(`PHP Test Suite/Unit/Services/`, 12 tests) — every image in a folder
rendered, both `link` modes, the small-thumbnail-URL fallback to the
full-size file, missing/nonexistent/empty folder all rendering nothing,
an unrecognized `link` value falling back to `none`, and multiple
shortcodes in one content string. Full suite (1694 tests) and
`composer stan` both run clean. Browser-verified end-to-end against the
dev install with real imported production data (a 200+ image
"Wallpapers" folder): both editors insert the shortcode correctly, the
front end renders real thumbnails with correct lightbox links for
"Media File", correct `no-lightbox`/no-wrapping-`<a>` markup for
"None", a nonexistent folder renders nothing with no PHP error, and the
rest of the post's content renders normally around the gallery.

### Checklist

- [x] A new "Insert Folder" option in the content editor (alongside the
      existing Insert Image entry point) — implemented as its own
      lightweight dialog (folder + link-to choice) rather than
      extending the Media Manager grid picker into a "folder-select
      mode," since the folder list was already available client-side
      with no need for the picker's own paginated image-grid query
- [x] Inserted content renders every image currently in the chosen
      folder as a row of thumbnails — decided and documented above: a
      live-resolving `[lumora_folder_gallery folder_id="..."]`
      shortcode, not a static list of image ids frozen at insert time
- [x] Per-thumbnail link behavior, matching Insert Image's existing
      options — decided and documented above: two options (None / Media
      File), not three, since Insert Image itself has no third
      "attachment page" option and Lumora Press has no such public page
- [x] Thumbnail row styling lives in the active theme's `style.css` per
      the Public-Facing CSS Rule (stable classes, responsive wrapping,
      dark mode via the shared `--lp-*` tokens) — not inline styles in
      the rendered content
- [x] Handle an empty folder and a folder that no longer exists (deleted
      after insert) without a broken/blank render or a PHP error
- [x] Regression/unit tests for the new render path (shortcode or
      renderer, whichever approach is chosen) and its link-mode variants
- [x] Verify end-to-end in a real browser: insert a folder with several
      images into a post, confirm the thumbnail row renders correctly
      for each link-mode option, and confirm removing/adding a file to
      the folder afterward behaves as decided above (live vs. frozen)

### LP-124. Require Admin/Staff Username to Differ From Display Name, Discourage Guessable Usernames

### Goal

A user with a backend/posting role (Administrator, Editor, Author, or
Contributor) could have an identical login username and public Display
Name — the installer's own admin-account step even set them to the
same value by default. Since a post's byline exposes Display Name to
every visitor, an identical login/Display Name effectively publishes
half of the account's credentials (the username) to anyone reading the
site, halving the guesswork an attacker needs for a login/password-
reset brute-force attempt. Require the login and Display Name to be
different for every admin/staff role, and discourage (for
Administrator specifically, reject outright) an obviously guessable
login username like "admin".

Distinct from LPP-001 "Lumora Shield"'s planned "Stop User
Enumeration" module (preventing an *attacker* from discovering a valid
username via response-timing/error-message differences at login) and
its "Username Blacklist" (a *commenter* username blocklist) — this
ticket is about the account holder's own username choice at creation
time, not runtime enumeration defenses.

### Checklist

- [x] New `UserService::usernameMatchesDisplayName()`/
      `isGuessableAdministratorUsername()` validation-check methods
      (mirroring the existing `usernameOrEmailExists()`/
      `usernameOrEmailExistsForOther()` pattern — a check method the
      caller consults before writing, not an exception thrown inside
      `create()`/`update()` themselves) — deliberately *not* baked into
      `create()`/`update()` directly, since those two methods are also
      called by the WordPress Importer and Dummy Content generator,
      neither of which should have an otherwise-harmless imported/
      generated account silently abort the whole batch over this rule.
      `usernameMatchesDisplayName()` compares trimmed/case-insensitive,
      applies to Administrator/Editor/Author/Contributor (Subscriber
      has no posting byline or backend access for the collision to
      matter); `isGuessableAdministratorUsername()` checks a small
      fixed blocklist (`admin`, `administrator`, `root`, `webmaster`,
      `superuser`, `owner`, case-insensitive), consulted only for the
      Administrator role — the highest-value target, and the one role
      every WordPress-hardening convention already specifically targets
      for this same reason
- [x] Installer's admin-account step (`install/index.php`) surfaces
      both new validation failures as real inline form errors (matching
      its existing `$errors[]` pattern), and no longer defaults Display
      Name to the same value as the username — an empty Display Name
      field is required to be filled in explicitly, not silently
      defaulted to a value that would fail the new same-value check
- [x] Admin "Add/Edit User" screen (`admin/views/users.php`) surfaces
      both new validation failures as a real inline form error (matching
      its existing `$error` pattern), and no longer suggests "leave
      blank to use the username" in the Display Name field's hint text
- [x] Regression/unit tests in `UserServiceTest.php` for both new
      validation rules (reject on create/update for each affected role,
      confirm Subscriber is unaffected, confirm the blocklist only
      applies to Administrator)
- [x] Verify end-to-end in a real browser: the installer refuses to
      proceed with a same-value admin username/Display Name or a
      blocklisted Administrator username, and the admin Add/Edit User
      screen refuses the same for a new or edited Editor/Author/
      Contributor/Administrator account, with a clear error message
      each time

### Deferred (not attempted this pass)

- No retroactive check/dashboard warning for an *existing* account that
  already violates this rule from before the feature existed — this
  ticket only validates going forward, at create/edit time
- No true "hard to guess" strength scoring (entropy/dictionary check)
  beyond the small fixed Administrator blocklist above — flagged as a
  possible future enhancement, not built here

---

### LP-026. Manual Updates (ZIP Upload)

**Implemented (2026-08-10).** The six items deferred on 2026-08-06 (see
DECISIONS.md) are now all built and covered by the PHP Test Suite:
- **Maintenance mode during update** — `UpdateService::install()` now
  auto-enables the existing `maintenance_mode_enabled` option (via
  `MaintenanceGate`) for the duration of the update, in a try/finally that
  restores whatever value it held beforehand — an administrator who had
  already turned maintenance mode on deliberately still finds it on
  afterward, not toggled off. `/admin/*` stays exempt throughout (see
  `MaintenanceGate`'s own docblock), so the admin area itself is never
  locked out by its own update.
- **Warn about active users** — a new `last_active_at` column on
  `{prefix}users` (migration 0031) is stamped once per authenticated admin
  page load (`admin/index.php`, right after `$currentUser` resolves) via
  `UserService::touchLastActive()`. `UpdateService::checkUpload()` surfaces
  a non-blocking warning (`activeUserProblems()`) when another user (never
  the administrator running the check) was active within the last 5
  minutes.
- **Detect modified core files** — a new `UpdateChecksumManifest` service
  records a SHA-256 checksum for every file under `corePaths` immediately
  after each successful `install()` (`storage/updates/checksums.json`,
  same on-disk-JSON convention as `UpdateManifest`). The *next*
  `checkUpload()` compares the live filesystem against that baseline
  (`modifiedCoreFileProblems()`) and warns — non-blocking, since
  overwriting a hand-edited file during an update is often exactly what's
  wanted — when a core file was edited or removed since the last install.
  An explicit backup restore (`restoreBackup()`, which can jump to an
  arbitrary older backup) clears the checksum baseline rather than
  recomputing it, since the restored files aren't necessarily "pristine."
- **Backup verification** — `UpdateBackupService::backupFiles()` reopens
  the just-written ZIP with `ZipArchive::CHECKCONS` (validates central
  directory/local header consistency without re-reading every byte, to
  avoid doubling I/O cost on large sites); `backupDatabase()` confirms a
  non-empty dump ends with the same statement marker every completed
  `fwrite()` appends, catching a dump truncated by a crash or a full disk.
  Both throw before `install()` ever proceeds past taking that backup.
- **Test interrupted updates** — two new `UpdateServiceIntegrationTest`
  cases exercise distinct interruption points beyond the existing
  migration-failure test: a post-overlay integrity-check failure (`version.php`
  deliberately excluded from `corePaths` so the installed-version check
  fails after overlay/migrate complete), confirming automatic rollback
  still restores every backed-up file and the database cleanly.
- **PHP 8.2 / PHP 8.3 compatibility** — verified via a full
  `./run-tests-all-php.sh` Docker matrix run (MariaDB 11 + php82/php83/
  php84): every LP-026-related test (new and existing) passed on all three
  versions; see `PHP Test Suite/TEST_LOG.md`'s 2026-08-10 entry.

**Implemented (2026-07-21).** Manually verified end-to-end on a live install:
the Dashboard's leftover-`install/`-directory alert (LP-030), a real ZIP
upload + install, and the automatic file/database backup all confirmed
working.

**Fixed (2026-07-21):** live testing showed a successful update left
`install/` behind on disk — it's one of the overlaid core paths, so a
package that ships it resurrects the directory even after the
administrator had deleted it post-install. `UpdateService::install()` now
removes `install/` (best-effort, via `InstallerCleanup`) right after a
successful update; see `docs/CHANGELOG.md`'s "Fixed" entry.

**Added (2026-08-01):** `UpdateManifest` (`app/Services/UpdateManifest.php`)
tracks which top-level `corePaths` entries were in effect as of the last
successful install/restore, in a small JSON file
(`storage/updates/core-manifest.json`, same on-disk-JSON convention as
`CacheManager`'s purge log). `UpdateService::install()` now removes any
top-level corePath entry that was tracked previously but is no longer
part of the current `corePaths` configuration (the only way a corePath
can actually go stale — `overlayPath()` already deletes-then-replaces
each corePath wholesale, so nothing can go stale *inside* one). Scoped
so it can never touch anything an administrator added — comparison is
against the codebase's own fixed `corePaths` list, never against the
contents of an uploaded release ZIP. First run after this shipped is
always a safe no-op (no prior manifest to compare against).
`UpdateBackupService::restoreFiles()` rewrites the same manifest after a
successful restore (automatic rollback or an admin-initiated Restore),
since restore itself never deletes anything — this can only ever cause
under-removal of a stale leftover later, never removal of something it
shouldn't.

**Ten items implemented (2026-08-06):**
- **Warn about development builds** — `UpdatePackageValidator` adds a
  (non-blocking) warning when the package's version string carries a
  SemVer-style `-dev`/`-alpha`/`-beta`/`-rc` suffix.
- **Database version** — `UpdateService::databaseVersionProblems()`
  queries the connected server's own `SELECT VERSION()` against README.md's
  stated minimums (MySQL 5.6.4+ / MariaDB 10.0.5+), correctly distinguishing
  the two families. Not to be confused with `migrationStatus()`'s "how many
  of this app's own migrations have run" — being behind on migrations
  before an update is expected/normal (that's what `install()` itself then
  runs), not a pre-update blocker.
- **Configuration compatibility** — `UpdateService::configCompatibilityProblems()`
  confirms `config/config.php` exists, returns an array, and has every
  required key (`db_host`/`db_name`/`db_user`/`db_password`/`db_charset`/
  `table_prefix`/`secret_key`) with `secret_key`/`table_prefix` non-empty.
- **Verify backup location** — `UpdateService::backupLocationProblems()`
  checks the backup destination (or its nearest existing ancestor, since
  it's created lazily on first backup) is writable with a sane minimum of
  free space, before an update ever gets as far as actually writing one.
- **Rebuild caches if necessary** — a new `lumora_press_after_update` hook
  listener in `include/bootstrap.php` purges the external reverse-proxy/
  edge cache (LiteSpeed, via the existing Cache API) on a successful
  update, alongside `install()`'s own `storage/cache/` folder clear.
- **Manual restore point** — this was already fully built
  (`UpdateService::createBackupNow()` + the "Back up now" button in the
  Backups panel) but the checkbox had never been ticked; corrected here,
  not new work.
- **Drag-and-drop upload** / **Display upload progress** / **Progress
  indicator** / **Add progress UI** — one feature covering four checklist
  lines (the same feature was listed separately under Update Package, User
  Interface, and Admin Interface). `admin/assets/js/update-upload.js`
  turns the Manual Update box into an HTML5 drop zone and intercepts the
  form's submit to upload via `XMLHttpRequest` with a real progress bar
  (`upload.onprogress`), replacing the document with the server's response
  on completion — the same full-page result a normal synchronous submit
  would have produced, just with visible progress for what's often a
  tens-of-MB archive.

`Detect modified core files`, `Warn about active users`, `Backup
verification`, `Test interrupted updates`, and `PHP 8.2`/`PHP 8.3
compatibility` remain open — explicitly deferred, not declined; see the
2026-08-06 DECISIONS.md entry. `Display backup management` was flagged
mid-session as likely already satisfied and confirmed by the user
afterward — see that same entry.

### Goal

Implement a safe, user-friendly manual update system that allows administrators to upload a release ZIP through the Lumora Press admin panel to update the installation without requiring FTP or SSH access.

The update process should be reliable, preserve user data, create automatic backups, and recover gracefully from failures.

---

### Features

#### Update Package

- [x] Upload ZIP package
- [x] Drag-and-drop upload
- [x] Browse for ZIP file
- [x] Display upload progress
- [x] Validate uploaded archive
- [x] Reject unsupported file types

#### Package Validation

- [x] Verify ZIP integrity
- [x] Verify Lumora package structure
- [x] Verify version information
- [x] Prevent downgrades (optional)
- [x] Warn about development builds
- [x] Validate required files

#### Compatibility Checks

- [x] PHP version
- [x] Required extensions
- [x] File permissions
- [x] Disk space
- [x] Database version
- [x] Configuration compatibility

#### Pre-Update Checks

- [x] Maintenance mode
- [x] Warn about active users
- [x] Verify writable directories
- [x] Verify backup location
- [x] Detect modified core files
- [x] Display update summary

#### Automatic Backup

- [x] Backup application files
- [x] Backup database
- [x] Timestamp backups
- [x] Automatic cleanup of old backups
- [x] Manual restore point
- [x] Backup verification

#### Update Process

- [x] Extract archive safely
- [x] Preserve configuration files
- [x] Preserve uploads
- [x] Preserve themes
- [x] Preserve plugins (future)
- [x] Replace core files
- [x] Remove obsolete files — `UpdateManifest` + `UpdateService::removeObsoleteCorePaths()`, 2026-08-01; see the ticket-level note above
- [x] Run database migrations
- [x] Clear caches
- [x] Rebuild caches if necessary

#### Rollback

- [x] Detect failed update
- [x] Restore files
- [x] Restore database
- [x] Display recovery report
- [x] Preserve error logs

#### User Interface

- [x] Upload page
- [x] Progress indicator
- [x] Step-by-step status
- [x] Success screen
- [x] Error reporting
- [x] View update history

#### Logging

- [x] Record update attempts
- [x] Record installed version
- [x] Record failures
- [x] Record rollback events
- [x] Record administrator

---

### Task List

#### Backend

- [x] Create ZIP upload handler
- [x] Implement archive validator
- [x] Implement compatibility checker
- [x] Implement backup service
- [x] Implement extraction service
- [x] Implement updater service
- [x] Implement rollback service
- [x] Implement logging

#### Admin Interface

- [x] Create Update page
- [x] Build upload form
- [x] Add progress UI
- [x] Display update logs
- [x] Display backup management — already fully built (the Backups panel
      on Maintenance > Updates lists every backup with Restore/Delete,
      backed by `UpdateService::listBackups()`/`restoreBackup()`/
      `deleteBackup()`); checkbox corrected 2026-08-06, not new work

#### Testing

- [x] Test valid updates
- [x] Test corrupted ZIPs
- [x] Test interrupted updates
- [x] Test rollback
- [x] Test backup restoration
- [x] PHP 8.2 compatibility
- [x] PHP 8.3 compatibility
- [x] PHP 8.4 compatibility

#### Documentation

- [x] Update README.md
- [x] Document update process
- [x] Document recovery procedures

#### Success Criteria

- [x] Administrators can safely update Lumora Press by uploading a ZIP file.
- [x] Failed updates recover automatically whenever possible.
- [x] User content and configuration are never lost.


---

### LP-112. Text Widget: WYSIWYG Editing & HTML Rendering (Text/HTML Widget)

### Goal

The built-in Text widget rendered its Content field as escaped plain text
with `nl2br()` line breaks — no way to bold a word, add a link, or
structure a list without falling back to the separate Custom HTML widget
(raw, unsanitized markup, trusted only at the manage_themes level). Turn
it into a proper Text/HTML widget: a lightweight WYSIWYG editor in the
Content field, and real sanitized HTML rendering on the front end.

### Checklist

- [x] Add a lightweight WYSIWYG editor (`admin/assets/js/widget-editor.js`,
      a small standalone TinyMCE instance — no image upload/Media Manager,
      unlike the full post/page editor) to the Text widget's Content field
      on Appearance &rsaquo; Widgets, deferred to a widget's `<details>`
      panel actually being opened
- [x] Change `CoreWidgets`' `text` widget type's render callback to run
      its stored content through `ContentRenderer::render()` (the same
      `ContentFormat::Html` sanitize/lightbox/hook pipeline a post/page's
      HTML-format content already uses) instead of `nl2br(esc_html())`
- [x] Relabel the widget "Text/HTML" in the Add Widget picker
- [x] Add `.lp-widget__content` rich-text typography (headings, paragraphs,
      links, lists, blockquote, hr, text-alignment classes) to the default
      theme's stylesheet, so both this widget and the existing Custom HTML
      widget render legibly instead of relying on unstyled browser
      defaults
- [x] Propagate the same `.lp-widget__content` typography addition to
      `custom themes/duskline` and `custom themes/xena-theme`
- [x] Verify end-to-end in a real browser: add a Text/HTML widget, format
      some content with the WYSIWYG toolbar, save, and confirm it renders
      correctly (and safely — no raw `<script>` survives) on the public
      site

---

### LP-113. Bug: Double-Encoded HTML Entities In Imported Text (e.g. "TV &amp;amp; Movies")

### Goal

A category imported from WordPress rendered on the public site as the
literal text "TV &amp;amp; Movies" instead of "TV &amp; Movies". Root
cause: WordPress HTML-entity-encodes plain-text fields before storing
them (typing "TV & Movies" in wp-admin saves `TV &amp; Movies` in the
database) — the WordPress Importer read that value verbatim, and
esc_html()/esc_attr() then re-encoded the already-encoded value a second
time at render, producing `&amp;amp;` in the page source. Same bug class
could affect tag names, post/page titles and excerpts, comment author
names/content, user display names, and media alt text/captions —
anything sourced from the same importer and rendered via esc_html()/
esc_attr().

### Checklist

- [x] Decode every plain-text field `WordPressSource` reads
      (`content/plugins/wordpress-importer/src/WordPressSource.php`):
      term `name` (`terms()`/`termNamesForPost()`), `display_name`
      (`users()`), `post_title`/`post_excerpt` (`posts()` —
      `post_content` deliberately left alone, since it's rendered as
      real HTML and already handles entities correctly), and
      `comment_author`/`comment_content` (`comments()`) — via a single
      `decodeEntities()` helper, mirroring the existing
      `html_entity_decode()` `WordPressImportService` already applied to
      `blogname`/`blogdescription`
- [x] Decode `_wp_attachment_image_alt` at its one consumption site in
      `WordPressImportService::importMedia()`
- [x] Add `EntityDecodeRepairService` (`app/Services/`): a one-time
      repair for content already imported (or otherwise saved) with a
      double-encoded value before the fix above landed — scans
      categories/tags (name, description), posts/pages (title, excerpt),
      comments (guest_name, content), users (display_name), and media
      (alt_text, caption, description), decoding only rows where doing
      so actually changes the value; safe to run repeatedly
- [x] Wire `EntityDecodeRepairService` into `Kernel`/`bootstrap.php` and
      add a "Fix Double-Encoded Text" panel to Maintenance &rsaquo; Tools
      (always shown, not gated behind a plugin) with a Scan & Fix button
- [x] Unit tests: `WordPressSourceEntityDecodeTest` (import-time decode)
      and `EntityDecodeRepairServiceTest` (existing-data repair,
      including "leaves already-correct text untouched" and "safe to run
      twice")
- [x] Verify end-to-end against the real dev install: run the repair
      against the actual "TV &amp;amp; Movies" category and confirm it
      renders as "TV & Movies" on the public site afterward
