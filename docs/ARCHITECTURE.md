# Architecture

An internal map of how Lumora Press's codebase is put together — for
contributors working on Lumora Press itself, not for theme/plugin authors
(see [`THEME-DEVELOPMENT.md`](THEME-DEVELOPMENT.md) and
[`DEVELOPER-APIS.md`](DEVELOPER-APIS.md) for those) or for site owners
(see the root [`README.md`](../README.md)).

## Directory structure

```
LumoraPress/
├── admin/          Admin area (dashboard, settings, etc.) — reached via /admin
├── assets/         Framework-owned static JS/CSS themes depend on but don't vendor themselves
├── app/
│   ├── Core/        Framework internals (database, hooks, security, theming, routing)
│   ├── Controllers/ Front-end and admin request handlers
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

The document root is the project root itself (`LumoraPress/`), matching
classic WordPress's flat layout: `admin/` and `install/` are real,
independently reachable directories, while `app/`, `include/`, `config/`,
and `vendor/` are denied direct web access via `.htaccess` and are only ever
loaded through PHP `require`.

## Core services

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
  API, exposed procedurally via `include/hooks.php`. See
  [`DEVELOPER-APIS.md`](DEVELOPER-APIS.md) for the full hook reference.
- **`LumoraPress\Core\Theme\ThemeRenderer`** — locates and renders classic
  PHP theme templates (`header.php`, `single.php`, etc.), with `get_header()`
  / `get_footer()` / `get_sidebar()` helpers available inside templates.
- **`LumoraPress\Core\Theme\ThemeOptions`** — the Theme Options system
  (Appearance &rsaquo; Customize). See
  [`THEME-DEVELOPMENT.md`](THEME-DEVELOPMENT.md) for the field-registration
  API and the full list of core's built-in options.
- **`LumoraPress\Core\Widgets\WidgetManager`** and
  **`LumoraPress\Core\Menus\MenuManager`** — sidebar/widget and nav-menu
  registration and rendering, exposed procedurally for theme authors.
- **`LumoraPress\Core\Security\Auth`**, **`SessionManager`**, **`Csrf`**,
  **`LoginThrottle`**, **`RememberMeService`**, **`ContentSecurityPolicy`**,
  **`FormTiming`**, **`PasswordResetService`**, **`PasswordResetThrottle`** —
  session-based authentication with session-fixation protection, secure
  cookie defaults, per-action CSRF tokens, database-backed login attempt
  throttling by IP address (thresholds configurable on Settings &rsaquo;
  Security), an optional "Remember Me" persistent login via a rotating,
  single-use selector/validator cookie, a strict, same-origin-only
  Content-Security-Policy header sent on every response, an HMAC-signed
  submission-timing check that rejects scripted instant form submissions,
  and self-service password reset via a single-use, one-hour-expiring
  emailed link (Log In &rsaquo; "Forgot password?"), itself IP-rate-limited
  the same way login attempts are.
- **`LumoraPress\Core\Mail\Mailer`** — a minimal outbound-email interface,
  backed by `NativeMailer` (PHP's built-in `mail()`, no external mail
  library) — used today for password-reset emails.
- **`LumoraPress\Core\Http\Router`** — a small, dependency-free router with
  `{param}` (single path segment) and `{param*}` (matches greedily across
  slashes, for a variable-depth path like a hierarchical Page URL)
  placeholders; no third-party routing library.
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
  emails, canonical tags).
- **`LumoraPress\Core\InstallerCleanup`** — best-effort recursive directory
  removal, used by the installer to remove itself after a successful
  install.
- **`LumoraPress\Core\RequirementsCheck`** — checks the PHP version,
  required extensions, and writable directories before the installer
  attempts anything else.

## Related documentation

- [`THEME-DEVELOPMENT.md`](THEME-DEVELOPMENT.md) — template-tag API,
  template hierarchy, Theme Options field registration, widget/menu
  registration.
- [`DEVELOPER-APIS.md`](DEVELOPER-APIS.md) — the hook/filter system,
  plugin lifecycle and file structure, service-layer classes plugins can
  call.
