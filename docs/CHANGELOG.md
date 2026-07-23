# Changelog

All notable changes to Lumora Press are documented in this file.

## [Unreleased] — 2026-07-23

### Added

- User Management (LP-032, first pass): an admin Users screen
  (`admin/views/users.php`, mirroring Categories/Tags' list/create/edit/
  delete pattern) behind the `users` admin menu entry, which previously
  fell through to a placeholder page. Administrators can create/edit/
  delete user accounts, assign a role (Administrator/Editor/Author/
  Contributor/Subscriber — Guest is excluded, since it isn't a stored
  account role), and change a user's password from the edit screen
  (leave blank to keep the current one). Three safety guards on delete:
  an admin cannot delete their own account, the last remaining
  Administrator cannot be deleted or demoted to another role, and a user
  who has authored posts or pages cannot be deleted (there is no admin
  reassignment UI yet, so deleting them would orphan that content's
  `author_id`). Deleting a user also revokes their "Remember Me" tokens
  via `RememberMeService::forgetUserTokens()`. `UserService` gained
  `update()`, `delete()`, `changePassword()`, `listAll()`,
  `countByRole()`, and `usernameOrEmailExistsForOther()`; `PostService`
  and `PageService` each gained `countByAuthor()` to support the
  authored-content delete guard.
- Tags (LP-011, first pass): a flat, non-hierarchical taxonomy for Posts,
  backed by new `{prefix}tags` and `{prefix}post_tags` tables (migration
  `0008_create_tags_tables.sql`) and `LumoraPress\Services\TagService`.
  Tags are typed by name rather than picked from a list — the Post editor
  gets a comma-separated "Tags" field, and `TagService::findOrCreateByName()`
  resolves each name case-insensitively (so "Sci-Fi" and "sci-fi" reuse the
  same tag rather than creating a duplicate) and creates it automatically
  if it doesn't exist yet. That field is progressively enhanced by the
  project's first admin JavaScript file
  (`admin/assets/js/tag-input.js`, loaded on every admin page via
  `admin/views/layout-footer.php`): a dependency-free chip + live-filtered-
  suggestions widget (Arrow Up/Down, Enter, comma, Backspace, Escape, and
  mouse all work) that reads/writes the same underlying plain text field,
  which still works correctly with JavaScript disabled. `PostService`
  gained `paginateByTag()` (same shape as `paginateByCategory()`) and
  `delete()` now also cleans up `post_tags` rows. The admin Tags screen
  (`admin/views/tags.php`) mirrors Categories' list/create/edit/delete with
  a post count, minus the parent selector (tags have no hierarchy).
  `/tag/{slug}` now renders a real archive (previously a placeholder);
  `content/themes/default/archive.php`'s category-only description block
  was generalized to a plain `archive_description` value so both
  Categories and Tags share it.
- Categories (LP-010, first pass): a hierarchical taxonomy for Posts,
  backed by new `{prefix}categories` and `{prefix}post_categories` tables
  (migration `0007_create_categories_tables.sql`) and
  `LumoraPress\Services\CategoryService`. A post can be assigned any
  number of categories (a real many-to-many relationship, via a new
  "Categories" checklist in the Post editor) rather than just one.
  Categories support a basic, flat parent selector like Pages (a category
  can never be made its own parent or its own parent's parent), and
  deleting a category orphans its children (`parent_id` becomes `NULL`)
  instead of cascading the delete to them. The admin Categories screen
  (`admin/views/categories.php`) provides create/edit/delete with a
  per-category post count (one `LEFT JOIN ... GROUP BY` query, not one
  count per row). `/category/{slug}` now renders a real category archive
  (previously a placeholder) listing only posts directly assigned to that
  exact category — posts in child categories are not included — via a new
  `PostService::paginateByCategory()`; the category's description, if set,
  is shown above the list (`content/themes/default/archive.php`, new
  `.lp-archive__description` style). `PostService::delete()` now also
  removes that post's category assignments, so deleting a post doesn't
  leave orphaned rows in `post_categories`.
- Pages (LP-009, first pass): a new static-content type alongside Posts,
  backed by a new `{prefix}pages` table (migration
  `0006_create_pages_table.sql`) and `LumoraPress\Services\PageService`.
  Mirrors Posts closely: create/edit/delete, automatic slug generation with
  duplicate resolution, and draft/published/scheduled status (a scheduled
  page becomes visible automatically once its `published_at` time passes,
  same mechanism as Posts). Adds a basic, flat "Parent Page" selector
  (`parent_id`) so a page can be marked as a child of another — no tree
  view, drag-and-drop ordering, or hierarchical URLs yet, and a page can
  never be made its own parent or its own parent's parent. The admin Pages
  screen (`admin/views/pages.php`) provides the full list/create/edit/
  delete flow, reusing the existing Posts capabilities (`edit_posts`,
  `publish_posts`, `delete_posts`, `edit_others_posts`) since no dedicated
  page permissions exist yet. `/page/{slug}` now renders a real page via
  the default theme's `page.php` (previously a placeholder); a draft or
  not-yet-due scheduled page correctly 404s. `render_pagination()`
  (`include/helpers.php`) now accepts an optional `$label` for its
  `aria-label`, defaulting to the previous "Posts pagination" text, so the
  new Pages list can render its own "Pages pagination" landmark instead of
  a mislabeled one.
- Leftover installer directory warning (LP-030): the Dashboard now shows a
  prominent alert if `install/` is still present on disk — whether left
  over from a fresh install that couldn't delete itself, or restored by a
  manual ZIP update (LP-026's `install/` is one of the paths a package
  overlays back onto the installation).
- Manual updates via ZIP upload (LP-026): a new Updates admin page lets an
  Administrator upload an official Lumora Press release ZIP and apply it
  in place, without FTP/SSH access. The pipeline validates the archive
  (integrity, size caps, path-traversal protection, required-file
  structure, version number), runs compatibility checks (PHP version,
  required extensions, disk space, directory writability), and blocks
  downgrades unless explicitly confirmed. On confirmation it automatically
  backs up the core application files and the database (a pure-PDO SQL
  dump — no `mysqldump`/shell dependency) before overlaying the new
  version's files, running any new database migrations, and verifying the
  installed version matches. Any failure after the backup triggers an
  automatic rollback of both files and database. Every attempt is recorded
  in a new `{prefix}update_log` table (migration
  `0005_create_update_log_table.sql`), shown as a "Recent Updates" list on
  the Updates page. New service classes: `UpdatePackageValidator`,
  `UpdateBackupService`, `UpdateService` (`LumoraPress\Services`), and a
  small `UpdateStatus` enum. The Dashboard's "Update Status" widget now
  links to the new Updates page instead of a hardcoded placeholder.
- Version number display (LP-029): the Dashboard's System Information
  widget now shows the codename alongside the version number (e.g.
  "0.2.0 (Posts)"), and the admin sidebar's "Lumora Press" brand now
  shows a short version label underneath it, visible on every admin page
  rather than only the Dashboard.

### Fixed

- Saving or editing a Page (LP-009) threw "An unexpected error occurred"
  on a real MySQL/MariaDB server: `PageService::listAllForParentSelect()`
  used the same named placeholder (`:id`) twice in one query. Real
  (non-emulated) prepared statements — `Database::connect()` sets
  `PDO::ATTR_EMULATE_PREPARES => false` — don't allow a named placeholder
  to repeat within a single query; MySQL's native prepare protocol throws
  `SQLSTATE[HY093]: Invalid parameter number` at execute() time. SQLite,
  which the unit test suite runs against, doesn't have this restriction,
  so `PageServiceTest` passed while the same query broke immediately on a
  live site. Fixed by using two distinct placeholders bound to the same
  value. A new `Integration/PageServiceIntegrationTest.php` (runs only
  against a real MySQL/MariaDB server) now covers this specifically, since
  no SQLite-backed unit test can catch this class of bug.
- Manual ZIP updates (LP-026) never cleaned up the `install/` directory:
  `install/` is one of the core paths a release package overlays onto the
  installation, so an update that shipped it would resurrect it even on a
  site where the administrator had already deleted it after their
  original install — silently reopening exactly the surface LP-030's
  Dashboard alert warns about. `UpdateService::install()` now removes
  `install/` (best-effort, via the same `InstallerCleanup` the installer
  itself uses) immediately after a successful update.

## [0.2.0] — 2026-07-21 — "Posts"

### Added

- Website URL detection during installation (LP-028):
  `LumoraPress\Core\Http\SiteUrl` detects the site's absolute URL (scheme +
  host + install path) from the installer's own request, the same way
  `BasePath` already detects the install's subdirectory. Installer step 2
  now shows an editable "Website URL" field pre-filled with the detected
  value (validated as a URL on submit) and stores it as a DB-backed
  `site_url` option — unlike `base_path` (fixed file config), the site's
  public URL is editable later without re-running the installer. A new
  `home_url()` helper (mirroring `site_url()`/`admin_url()`) exposes it to
  core, themes, and plugins as an absolute URL rather than a root-relative
  one; installs made before this option existed fall back to live
  detection automatically. Nothing consumes it yet (no RSS/sitemaps/emails
  exist), but it's the foundation those will need.
- Posts (Phase 2): the first real content type, backed by a new `{prefix}posts`
  table (migration `0004_create_posts_table.sql`) and
  `LumoraPress\Services\PostService`. Supports create/edit/delete, automatic
  slug generation from the title with numeric-suffix duplicate resolution
  (`hello-world`, `hello-world-2`, …), and draft/published/scheduled status.
  A scheduled post becomes publicly visible automatically once its
  `published_at` time passes — the visibility query checks
  `status = 'published' OR (status = 'scheduled' AND published_at <= NOW())`
  directly, so nothing needs to run a background job to flip its status.
  The admin Posts screen (`admin/views/posts.php`) provides the full list/
  create/edit/delete flow with pagination and a status filter, and respects
  each role's capabilities: a Contributor can only ever save drafts, and an
  Author/Contributor without `edit_others_posts` can only touch their own
  posts. The dashboard's previously-disabled "Quick Draft" widget now saves
  a real draft, and its "Recent Posts" widget lists real posts. The
  homepage and the `/archive` route now list published posts with
  pagination via a new shared `render_pagination()` helper; `/post/{slug}`
  renders the full post. `admin/index.php` now wraps its page dispatch in
  an output buffer so that a page view required after
  `views/layout-header.php` (like `views/posts.php`) can still call
  `header()` for its own process-POST-then-redirect and per-record
  authorization handling.

## [0.1.0] — 2026-07-21 — "Foundation"

### Added

- Initial application foundation: directory structure, PDO database service
  with migrations, configuration service, session-based authentication with
  CSRF protection, user roles, the admin dashboard framework, the plugin
  hook system (`add_action`/`do_action`/`add_filter`/`apply_filters`), the
  widget and navigation menu systems, a basic media library, and a
  two-step installer.
- Default theme (`content/themes/default`): classic template set
  (`header.php`, `footer.php`, `sidebar.php`, `index.php`, `single.php`,
  `page.php`, `archive.php`, `search.php`, `404.php`, `functions.php`) with
  a responsive, accessible `style.css` supporting light and dark mode, a
  primary widget area, and primary/footer/social/secondary nav menu
  locations.
- Login attempt throttling (Phase 1.17): `LumoraPress\Core\Security\LoginThrottle`
  tracks failed admin logins by IP address, persisted in a new
  `{prefix}login_attempts` table (migration `0002_create_login_attempts_table.sql`)
  rather than the session, since an attacker won't necessarily carry
  cookies across requests. After 5 failed attempts from the same IP within
  15 minutes, further attempts are blocked for 15 minutes with a friendly
  "try again in N minutes" message. Successful logins clear that IP's
  history. The throttle check fails open (never blocks a login) if its
  underlying table can't be read — see `LoginThrottle`'s docblock for the
  tradeoff. Existing installs pick up the new migration automatically the
  next time any admin page (including the login page) is visited, without
  needing to re-run the installer.
- "Remember Me" persistent login (Phase 1.7): a new "Remember Me" checkbox
  on the admin login form issues a long-lived (30-day) cookie via
  `LumoraPress\Core\Security\RememberMeService`, backed by a new
  `{prefix}remember_tokens` table (migration
  `0003_create_remember_tokens_table.sql`). Uses the selector/validator
  pattern: only a hash of the token is stored, comparisons are
  timing-safe, and every token is single-use — a successful auto-login
  immediately rotates to a fresh token, and a validator that doesn't match
  its selector revokes every remember-me token for that user as a
  precaution against a stolen cookie being replayed. Logging out clears
  the cookie and its underlying token on this device.
- Content Security Policy support (Phase 1.17): `LumoraPress\Core\Security\ContentSecurityPolicy`
  sends a strict, same-origin-only `Content-Security-Policy` header on
  every response — the front end, the admin area, and the installer.
  Nothing shipped in core needed loosening for this (no inline scripts,
  inline styles, or third-party origins anywhere in the codebase).
  Themes and plugins can loosen individual directives via the new
  `csp_directives` filter; site owners can turn the header off entirely
  via the new `csp_enabled` config option (e.g. if a reverse proxy already
  sets its own policy).
- Automatic installer removal (LP-004): once a fresh install completes
  successfully, `install/` is deleted automatically via the new
  `LumoraPress\Core\InstallerCleanup`, reducing the chance of leaving the
  installer accessible after deployment. If deletion isn't possible (e.g.
  restrictive shared-hosting permissions), the success page tells the
  administrator to remove the directory by hand instead — installation
  itself has already fully completed by the time cleanup is attempted, so
  a cleanup failure never blocks it.
- Installer system requirements check: before attempting anything else,
  the installer now checks the PHP version, the `pdo`, `pdo_mysql`,
  `session`, and `json` extensions, and that `config/`, `storage/logs/`,
  `storage/sessions/`, `storage/cache/`, and `content/uploads/` are
  writable, via the new `LumoraPress\Core\RequirementsCheck`. If anything
  is missing, a clear page lists exactly what needs fixing instead of a
  generic "Installation failed: ..." message appearing partway through
  the wizard.

### Fixed

- Subdirectory installs (e.g. `https://example.com/lumorapress/` rather than
  the domain root): the installer's step 1 redirect and every hardcoded
  `/install/`, `/admin/`, and `/admin/assets/css/admin.css` reference in
  `install/views/*.php` previously assumed the app lived at the domain root,
  sending subdirectory installs to a 404 partway through. The installer now
  derives its own URL from the request instead of assuming it, and the root
  `index.php`'s pre-install redirect to `install/` does the same. The
  install-time location is also persisted to `config/config.php` as
  `base_path`, and the `site_url()`/`admin_url()` helpers (used throughout
  the admin area, the default theme, and RSS output) now read it via a new
  `LumoraPress\Core\Http\BasePath` bridge instead of hardcoding a leading
  `/`, so every internal link continues to resolve correctly after
  installing to a subdirectory.
- Root `.htaccess`: the rewrite bypass for `install/` and `content/` matched
  against `%{REQUEST_URI}` with a pattern anchored to the domain root
  (`^/(admin|install|content)`), which never matched under a subdirectory
  install (`%{REQUEST_URI}` is always the full path, e.g.
  `/lumorapress/install/`) — every request to the installer was silently
  rewritten to the front controller instead, which then redirected back to
  the same broken path, producing an infinite redirect loop on step 1.
  Replaced with a match against `%{REQUEST_FILENAME}` (an absolute
  filesystem path, already location-independent) for a `/install/` or
  `/content/` path segment. `admin` was also removed from the bypass
  entirely — `admin/.htaccess` already denies direct requests to
  `admin/index.php` as defense in depth, since it's only meant to be
  reached through the front controller's router, so bypassing the rewrite
  for it would have made the admin area unreachable (403) the moment
  someone got past installation.
- Subdirectory installs (continued): the previous subdirectory-install
  fixes above only addressed *outgoing* URLs (`site_url()`/`admin_url()`
  building correct links). The incoming side was still broken: `index.php`
  passed the raw `$_SERVER['REQUEST_URI']` (which always carries the
  install's subdirectory prefix, e.g. `/lumorapress/admin/`) straight into
  `Router::dispatch()`, but every route pattern in `include/bootstrap.php`
  is registered without that prefix (`/`, `/admin`, `/post/{slug}`, …) — so
  on any subdirectory install, every single route failed to match and
  every request, including the homepage, returned the application's own
  404. This went unnoticed through all of Phase 1 because a domain-root
  install has an empty prefix and nothing to strip; it surfaced via a real
  subdirectory install returning "Page Not Found" for both `/admin/` and
  the site root itself. Fixed by adding `BasePath::stripFrom(string $uri):
  string` and calling it in `index.php` before dispatch; see
  `DECISIONS.md`'s "Subdirectory installs never matched a route" for the
  full root-cause writeup.
- Subdirectory installs (continued): the admin stylesheet
  (`admin/assets/css/admin.css`) was still hardcoded as a domain-root
  path (`href="/admin/assets/css/admin.css"`) in `admin/views/login.php`,
  `forbidden.php`, and `layout-header.php`, 404ing on a subdirectory
  install (rendered, but completely unstyled) — missed by the earlier
  `site_url()`/`admin_url()` fix above since a static asset `<link>` isn't
  a page link. Added a new `admin_asset_url()` helper and used it in all
  three views.

### Removed

- The empty `public/` directory. Its `index.php` was a dead redirect stub
  left over from before the document-root architecture decision (see
  `DECISIONS.md`), and no static asset ever moved into `public/assets/`.
  Nothing referenced a `/public/...` URL. Also corrected two stale
  comments (`app/Core/Kernel.php`, `admin/.htaccess`) that still described
  `public/index.php` as the application's entry point, and removed a
  leftover `public` entry from the root `.htaccess`'s rewrite-bypass
  pattern.
- `admin/.htaccess` entirely, matching how this developer's other
  applications protect their own admin folders (no per-directory Apache
  config at all). It was also the likely actual cause, all along, of a
  persistent 403 a live subdirectory install kept hitting on bare
  `/admin/` requests (see `cPGuard-403-support-ticket.md`) — disabling it
  reliably made the 403 go away in every test performed. Every file in
  `admin/views/` now carries the same `isset($kernel)` direct-access
  guard `admin/index.php` already had (previously only Apache's `Require
  all denied` stopped a direct request to e.g. `admin/views/dashboard.php`
  from running with no `$kernel` in scope), and `admin/assets/*.css` is
  now just served as a normal static file. See `DECISIONS.md`'s "admin/
  has no .htaccess of its own" for the full writeup.

### Changed

- Installer (LP-002): the database table prefix field is now pre-filled with
  a cryptographically random suggestion (e.g. `lum_a8f3d1_`) instead of the
  predictable `lp_` default, while still allowing manual override.
- `Database` (LP-007): replaced the public constructor with two named
  constructors — `Database::connect(...)` for a real MySQL/MariaDB
  connection (used everywhere in the running application) and
  `Database::fromPdo(PDO $pdo)` for wrapping an already-open PDO connection.
  This is a testability-motivated refactor with no behavior change for the
  running application; every call site (`include/bootstrap.php`,
  `install/index.php`) was updated in the same change.
- `PluginManager` and `ThemeRenderer` no longer take an unused `HookManager`
  constructor dependency (flagged by PHPStan's first-ever run against this
  codebase this session — see `PHP Test Suite/TEST_LOG.md`). Plugins and
  themes still register hooks the same way, via the procedural
  `add_action()`/`add_filter()` bridge, not through these classes directly.
