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

