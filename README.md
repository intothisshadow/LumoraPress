# Lumora Press

Lumora Press is a lightweight, self-hosted PHP blogging platform inspired by the elegance and simplicity of classic WordPress. It focuses on writing, publishing, and customization through traditional themes, widgets, and plugins—without block editors, page builders, AI features, or unnecessary complexity. Designed for personal websites, fansites, and hobbyists, Lumora Press aims to be fast, stable, familiar, and enjoyable to use on modern PHP while remaining friendly to shared hosting environments.

> Sit down. Write. Publish.

## Compatibility

Lumora Press is inspired by classic WordPress (circa 2012–2015) in
philosophy and workflow — traditional PHP theme templates, a familiar
`add_action()`/`do_action()`/`add_filter()`/`apply_filters()` hook API —
but it is an independent, from-scratch codebase, not a WordPress fork or
compatibility layer. **Actual WordPress themes and plugins will not work
unmodified.** Themes call WordPress-specific template tags and globals
(`wp_head()`, `get_header()`, `$wp_query`, ...) that don't exist here;
plugins call WordPress-specific APIs (`WP_Query`, `wpdb`,
`wp_enqueue_script()`, ...) that Lumora Press doesn't implement, even
where its own hook names or theme file names look similar. A developer
familiar with classic WordPress theme/plugin development will recognize
the shape of both systems immediately, but existing WordPress themes and
plugins need to be rewritten against Lumora Press's own APIs (see
Architecture below), not simply dropped in.

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
- **`LumoraPress\Core\Theme\ThemeOptions`** — the Theme Options system
  (Appearance &rsaquo; Customize): themes and plugins register sections and
  fields (`ThemeOptionField`, one of `ThemeOptionType::{Text,Textarea,Number,
  Checkbox,Select,Color,Url,Html}`) via `add_action('register_theme_options', function
  (ThemeOptions $options) { ... })`, and the admin page + validation +
  storage are generated automatically. A field with a `cssVariable` is
  exposed to every public page as a CSS custom property via the
  `theme_options_css()` template helper (or read directly with
  `theme_option($key)`); core ships sixteen built-in options — Colors (Accent,
  Text, Muted Text, Background, Alt Background, Border), Typography (Body
  font, Base font size, Line height, Google Fonts URL + font family — the
  URL field is restricted to `fonts.googleapis.com`), Layout (Content
  width), Header (site title toggle, header image height), Welcome
  Message (Markdown/HTML/Plain content + placement), and Footer (Markdown/
  HTML/Plain content). Values are scoped per active theme — each theme
  keeps its own independent set.
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
  `{param}` (single path segment) and `{param*}` (LP-084; matches
  greedily across slashes, for a variable-depth path like a hierarchical
  Page URL) placeholders; no third-party routing library.
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
either of two ways, each on its own tab (**GitHub**, the default tab, and
**Manual Update**):

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
disk space, writable directories, connected database server version,
`config/config.php`'s own health, and that the backup destination is
writable with enough free space) before anything is touched. Two further
checks are non-blocking warnings rather than reasons to stop: another user
recently active in the admin area, and a core file that appears to have
been hand-edited since it was last installed and is about to be
overwritten.

1. Review the summary screen — it shows the version change and any
   warnings — then confirm.
2. Lumora Press automatically backs up the core application files and the
   database to `storage/backups/` before applying the update (each backup
   is verified immediately after being written, so a corrupted or
   truncated one is caught before the update proceeds), briefly enables
   maintenance mode for the duration of the update (restored to whatever
   it was set to beforehand once finished — the admin area itself always
   stays reachable), runs any new database migrations, and verifies the
   new version took effect. If anything goes wrong after the backup, it
   automatically restores the files and database from that backup.

Every download, validation, and install step shows live, step-by-step
progress on the Updates page while it runs (e.g. Backing up files &rarr;
Backing up database &rarr; Applying update files &rarr; Running database
migrations), rather than leaving the page blank until it finishes — a
failed step is shown distinctly from a completed one, so the actual
point of failure stays visible.

Every download, validation, and install step shows live, step-by-step
progress on the Updates page while it runs (e.g. Backing up files &rarr;
Backing up database &rarr; Applying update files &rarr; Running database
migrations), rather than leaving the page blank until it finishes — a
failed step is shown distinctly from a completed one, so the actual
point of failure stays visible.

Every attempt (success, failure, or rollback) is recorded in the
`{prefix}update_log` table, tagged with its source (`github` or `manual`),
and listed on the Updates page. Only `app/`,
`admin/`, `include/`, `install/`, `docs/`, the default theme, the bundled
Font Awesome, Dummy Content, WordPress Importer, Downloads, and Contact
Forms plugins
(`content/plugins/font-awesome`, `content/plugins/dummy-content`,
`content/plugins/wordpress-importer`, `content/plugins/downloads`,
`content/plugins/contact-forms`), and
the root PHP files are ever replaced — `config/`, `content/uploads/`, any user-installed plugin, any
theme other than the default, and `storage/` are never touched.
`install/` is deleted again automatically once the update succeeds (the
same best-effort cleanup the installer itself performs), so a package that
ships it doesn't leave it lying around on disk. If a future release drops
one of those top-level core paths entirely, the old one is automatically
removed too — tracked via a small on-disk manifest, scoped so it can only
ever act on Lumora Press's own core paths, never anything else on the
server.

Every backup pair is also listed in a **Backups** panel on the Updates
page — "Back up now" creates one on demand, independent of running an
actual update — with one-click "Restore" and "Delete" per backup (both
behind a confirmation prompt). The Updates page also shows the database
schema's migration status and a System status panel (PHP version, ZIP/cURL
availability, file permissions, disk space, and the update staging
directory), reflecting this server's current environment independent of
anything else on the page.

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

## Privacy: Anonymous Install Ping

Lumora Press includes an opt-in, off-by-default mechanism to anonymously
count active installs. This provides the developer with a rough,
privacy-respecting understanding of real-world adoption, including which
PHP versions are still in active use — useful for deciding when support for
an older PHP version can be safely phased out.

- **Off by default.** Nothing is ever sent unless you explicitly enable
  **Anonymous install ping** in Settings &rsaquo; Privacy.
- **What is sent, and nothing else:**
  - A randomly generated install ID, created the first time the feature is
    enabled. It has no relationship to your domain, content, admin
    account, or any other data — there is no way to trace it back to your
    specific site from the ping alone.
  - Your installed Lumora Press version.
  - Your PHP version.
- **What is never sent:** your domain or site title, admin email, post/
  page/comment content, visitor data, or anything else.
- **Cadence.** The ping fires once immediately when you enable the
  feature, then at most roughly once a month afterward. It never fires on
  every page load. A "Send a test ping now" button on the Settings &rsaquo;
  Privacy screen lets you confirm it's working without waiting a month.
- **Independence from the update checker.** This uses a completely
  separate request from the GitHub release-check described above
  (`GitHubReleaseProvider`) — enabling or disabling one never affects the
  other.

## Current Status

**Version 0.8.0**

- **Foundation** — installer, routing, database layer, configuration
  service, authentication, user roles, admin dashboard, classic theme
  system, plugin hook API, widgets, and navigation menus.
- **Posts** — full CRUD with drafts/scheduling/pending review, Private
  and Sticky posts, scheduled unpublishing, Trash & restore, bulk
  actions (including change author/category/visibility), duplicate,
  custom fields, preview, author archives, categories and tags,
  revision history.
- **Pages** — static pages with parent/child relationships, hierarchical
  URLs matching that structure (e.g. `/about/team`), a
  drag-and-drop-reorderable tree view, drafts/scheduling/pending
  review, Private pages, comments, Trash & restore, bulk actions,
  duplicate, Quick Edit, search & filtering, preview, breadcrumbs, and
  revision history.
- **Content editors** — Markdown (EasyMDE) and WYSIWYG (TinyMCE), with
  best-effort conversion between formats, text/image alignment, underline,
  a fixed-palette font color, blockquotes, an Attachment Display
  Settings step (size, link-to) when inserting media, and an Insert
  Folder button that drops a whole Media folder into the content as a
  row of thumbnails.
- **Categories & Tags** — taxonomies for posts, with per-item archive
  pages. Categories support Trash with restore, Merge (moves a
  category's posts and child categories into another before removing
  it), and bulk actions (Move to Trash / Restore / Delete Permanently /
  Merge), matching Posts/Pages where applicable.
- **Permalinks** — a Settings &rsaquo; Permalinks screen to choose the
  post URL structure (Post name, Day and name, Month and name, or a
  custom token-based pattern) and rename the Category/Tag archive URL
  prefixes. Unconfigured, URLs are unchanged from `/post/{slug}`.
- **Comments** — threaded discussion on both Posts and Pages, with
  moderation (including bulk approve/spam/trash/delete), honeypot/CSRF/
  submission-timing spam protection, and optional Akismet spam-checking
  (Settings &rsaquo; Security — off by default, never required).
- **Discussion settings** — a dedicated Settings &rsaquo; Discussion screen
  for comment defaults (required name/email, registered-only commenting,
  auto-close after N days, cookie-remembered guest info, threading depth,
  pagination and ordering), moderation (manual-approval, link/keyword
  holds, disallowed-keyword rejection, a `comment_is_spam` filter for
  spam-detection plugins), admin/author email notifications, and avatars
  (Gravatar rating/default, or a locally uploaded default image).
- **Privacy Policy Page** — a Settings &rsaquo; Privacy screen to name an
  existing Page as the site's privacy policy, exposed to themes via the
  `privacy_policy_url()` template tag.
- **RSS & Atom feeds** — a site-wide feed of published posts, plus a
  per-category feed (`/category/{slug}/feed`) for each category.
- **Search** — full-text search across posts and pages.
- **User management** — admin-managed accounts and roles, with Trash &
  restore, bulk actions (trash/restore/delete/change role),
  search/filter by username, email, or role, and avatars (Gravatar by
  default, with an optional per-user upload).
- **Updates** — install official release ZIPs from the admin panel, either
  by checking GitHub Releases directly or uploading a ZIP manually, with
  automatic backup and rollback either way.
- **Maintenance mode** — take the public site offline for visitors while
  admins keep working.
- **Appearance** — theme browser, branding, custom CSS, a tabbed
  Customize screen (Header, Welcome Message, Body — colors/typography/
  layout/post display, Menu, Widgets, Footer; values are scoped per
  active theme, no CSS editing required), widgets, navigation menus, and
  a built-in theme file editor.
- **Plugin browser** — install and manage plugins from the admin panel.
- **Font Awesome plugin** (bundled) — an `[icon]` shortcode and a small
  developer API (`lp_icon()` and friends) for icons in theme/plugin markup,
  with CDN or self-hosted delivery (Settings &rsaquo; Appearance &rsaquo;
  Font Awesome, off by default).
- **Media Manager** — multi-file uploads with per-file progress, virtual
  folders, metadata, thumbnail generation, a lightbox viewer (covering
  both featured images and images embedded directly in post/page
  content), download statistics for document/archive/audio/video files,
  and usage tracking with delete-time warnings.
- **Featured images** — per-post/page featured images with manual
  cropping (with a configurable output size), or crop any already-
  uploaded image directly from its Media Manager edit screen into a new,
  independently reusable featured-image-ready Library item.
- **FTP media import** — bring in files already on the server without a
  browser upload.
- **REST API** — a versioned, token-authenticated API for posts, pages,
  categories, tags, comments, and search.
- **Settings** — site info, date/time formatting, SEO/social sharing
  defaults, and more.
- **Reading settings** — choose a "latest posts" or static-page homepage
  (with an optional separate posts page), set how many posts each
  listing page shows, and discourage search engines from indexing the
  site (a virtual `robots.txt` plus a `noindex` meta tag).
- **Front page & archive post display** — Appearance &rsaquo; Customize
  &rsaquo; Body &rsaquo; Post Display controls whether the front page and archives show each post's
  full content or an excerpt (with a configurable Read More link and
  automatic excerpt length), and whether the featured image appears in
  listings. A Read More tag, insertable from the content editor toolbar,
  lets an author choose the excerpt cutoff point by hand. Single-post
  pages always show the complete post regardless of this setting.
- **Caching** — HTTP cache headers and conditional `304` responses on
  cacheable public pages, first-class LiteSpeed Cache purge integration
  (auto-detected, with a manual override), and automatic cache
  invalidation whenever content or settings change.
- **SEO tools** — per-post/page SEO title and meta description overrides,
  canonical URLs, an XML sitemap, JSON-LD structured data, and
  admin-managed URL redirects.
- **Auto-Embed** — paste a bare YouTube, Vimeo, SoundCloud, Spotify,
  CodePen, Twitter/X, or Bluesky link on its own line in a post/page and it
  automatically becomes an embedded player, tweet, or post (Settings
  &rsaquo; Embeds). No outbound request is made to build the embed, with
  one exception: a Bluesky link is resolved once against Bluesky's own
  servers when the post/page is saved, not on every page view. Themes and
  plugins can register additional providers via
  `apply_filters('embed_providers', ...)`.
- **Default Editor** — a site-wide default content editor (Settings
  &rsaquo; General), with a per-user override on each user's own "My
  Profile" page (or set for them by an administrator) and an optional
  toggle to lock everyone to the site default.
- **Public light/dark mode toggle** — a header button lets a visitor
  explicitly pick Light or Dark, persisted in their browser and applied
  before the page paints; falls back to the OS's preference when no
  explicit choice has been made.
- **Post categories & edit link** — single posts and post listings show
  each post's assigned categories, and a signed-in author/editor with
  permission sees a quick "Edit this post" link on the single post view.

See `TODO.md` for planned work and known gaps.
