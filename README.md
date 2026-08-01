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
**Maintenance &rsaquo; Updates** (`/admin/maintenance/updates`), without FTP or SSH access,
either of two ways:

- **Check for Updates (GitHub)** — click "Check for Updates" to query the
  GitHub Releases API (configurable repository, optional personal access
  token, and a stable/pre-release channel setting) for the latest release.
  If a newer version is available, "Download & Check" downloads the
  official curated release package, verifies its SHA-256 checksum when the
  release publishes one, and continues into the same review/confirm flow
  as a manual upload. This stays a fully manual, administrator-initiated
  process — nothing downloads or installs automatically.
- **Upload Update Package** — upload an official Lumora Press release ZIP
  directly.

Either way, the package is validated (integrity, structure, version
number) and checked for compatibility (PHP version, required extensions,
disk space, writable directories) before anything is touched:

1. Review the summary screen — it shows the version change and any
   warnings — then confirm.
2. Lumora Press automatically backs up the core application files and the
   database to `storage/backups/` before applying the update, runs any new
   database migrations, and verifies the new version took effect. If
   anything goes wrong after the backup, it automatically restores the
   files and database from that backup.

Every attempt (success, failure, or rollback) is recorded in the
`{prefix}update_log` table, tagged with its source (`github` or `manual`),
and listed on the Updates page. Only `app/`,
`admin/`, `include/`, `install/`, `docs/`, the default theme, and the root
PHP files are ever replaced — `config/`, `content/uploads/`,
`content/plugins/`, any theme other than the default, and `storage/` are
never touched.
`install/` is deleted again automatically once the update succeeds (the
same best-effort cleanup the installer itself performs), so a package that
ships it doesn't leave it lying around on disk.

Every backup pair is also listed in a **Backups** panel on the Updates
page, with a one-click "Restore" per backup (behind a confirmation prompt)
for undoing an update yourself later without touching the filesystem
directly.

If a newer version is available on GitHub, a notice appears on the
**Dashboard** as well as the Updates page — Lumora Press checks
automatically (Dashboard-triggered, not a real server cron job, since none
is required to install Lumora Press) on a schedule you control from the
GitHub Update Settings panel (hourly/daily/weekly, or disabled entirely).
This only ever checks; nothing downloads or installs without an explicit
click.

If the admin panel itself becomes unreachable after a failed update,
restore manually: unzip the most recent `storage/backups/files-*.zip` over
the installation directory, and re-import the most recent
`storage/backups/db-*.sql` into the database (both files are plain,
human-readable formats — `storage/` is never web-accessible, so retrieve
them via FTP/SFTP or your hosting file manager).

## Current Status

**Version 0.4.0 "Updates"**

- **Foundation** — installer, routing, database layer, configuration
  service, authentication, user roles, admin dashboard, classic theme
  system, plugin hook API, widgets, and navigation menus.
- **Posts** — full CRUD with drafts/scheduling, Trash & restore, bulk
  actions, duplicate, categories and tags, revision history.
- **Pages** — static pages with parent/child relationships and revision
  history.
- **Content editors** — Markdown (EasyMDE) and WYSIWYG (TinyMCE), with
  best-effort conversion between formats.
- **Categories & Tags** — taxonomies for posts, with per-item archive
  pages.
- **Comments** — threaded discussion with moderation and basic spam
  protection.
- **RSS & Atom feeds** — a site-wide feed of published posts.
- **Search** — full-text search across posts and pages.
- **User management** — admin-managed accounts and roles.
- **Updates** — install official release ZIPs from the admin panel, either
  by checking GitHub Releases directly or uploading a ZIP manually, with
  automatic backup and rollback either way.
- **Maintenance mode** — take the public site offline for visitors while
  admins keep working.
- **Appearance** — theme browser, branding, custom CSS, widgets,
  navigation menus, and a built-in theme file editor.
- **Plugin browser** — install and manage plugins from the admin panel.
- **Media Manager** — uploads, virtual folders, metadata, thumbnail
  generation, and a lightbox viewer.
- **Featured images** — per-post/page featured images with manual
  cropping.
- **FTP media import** — bring in files already on the server without a
  browser upload.
- **REST API** — a versioned, token-authenticated API for posts, pages,
  categories, tags, comments, and search.
- **Settings** — site info, date/time formatting, SEO/social sharing
  defaults, and more.

See `TODO.md` for planned work and known gaps.
