# Lumora Press

Lumora Press is a lightweight, self-hosted PHP blogging platform inspired by the elegance and simplicity of classic WordPress. It focuses on writing, publishing, and customization through traditional themes, widgets, and plugins—without block editors, page builders, AI features, or unnecessary complexity. Designed for personal websites, fansites, and hobbyists, Lumora Press aims to be fast, stable, familiar, and enjoyable to use on modern PHP while remaining friendly to shared hosting environments.

> Sit down. Write. Publish.

**Current release**: 0.11.0

## Who is Lumora Press for?

Lumora Press is made for people who want to run their own website without turning website management into a full-time job.

It is particularly suited to:

- Personal websites and blogs
- Fansites and fan communities
- Hobby and interest sites
- Small community websites
- Developers who enjoy building traditional PHP themes and plugins

If you like the simplicity of classic WordPress but want a smaller, independent, self-hosted platform, Lumora Press is built for you.

## Features

### Compatibility

Lumora Press is inspired by the philosophy and workflow of classic WordPress, particularly its pre-block-editor era — traditional PHP theme templates and a familiar `add_action()`/`do_action()`/`add_filter()`/`apply_filters()` hook API.

Lumora Press is an independent, from-scratch codebase, not a WordPress fork or compatibility layer. **Actual WordPress themes and plugins will not work unmodified.** WordPress themes depend on WordPress-specific template tags and globals such as `wp_head()`, `get_header()`, and `$wp_query`; plugins commonly depend on APIs such as `WP_Query`, `wpdb`, and `wp_enqueue_script()`, which Lumora Press does not implement.

Developers familiar with classic WordPress will find the overall structure familiar, but existing WordPress themes and plugins must be adapted to Lumora Press's own APIs. See [Themes & Plugins](#themes--plugins) below.

### Publishing
- Posts and Pages, with hierarchical page URLs
- Drafts, scheduling, revisions, previews, and trash
- Categories and tags, with hierarchical category URLs, archive pages, merge, and bulk actions
- Sticky and private posts
- Threaded comments and moderation
- RSS and Atom feeds (site-wide and per-category)
- Full-text search across posts and pages

### Media
- Media library with virtual folders and metadata
- Featured images with manual cropping
- Multi-file uploads with per-file progress
- FTP media import
- Image lightbox viewer
- Audio and video player, embeddable in post/page content
- Download statistics for documents, archives, audio, and video

### Appearance
- Classic PHP themes (header.php, single.php, etc.)
- Theme browser with live, whole-site preview
- Theme Options / Customize screen — no CSS editing required
- Custom CSS
- Widgets and navigation menus
- Built-in theme file editor
- Public light/dark mode toggle

### Administration
- User and role management
- Maintenance mode
- Automatic, downloadable backups and one-click rollback
- Updates via GitHub Releases or a manual ZIP upload
- Portable settings export/import between installs
- Token-authenticated REST API

### SEO & Privacy
- SEO titles and meta descriptions
- Canonical URLs
- XML sitemap and JSON-LD structured data
- Admin-managed URL redirects
- Configurable `robots.txt`/`noindex`
- Optional, off-by-default anonymous install ping (see [Privacy](#privacy-anonymous-install-ping))

For the complete feature list and implementation details, see [`docs/FEATURES.md`](docs/FEATURES.md).

## Requirements

- PHP 8.2, 8.3, or 8.4
- MySQL 5.6.4+ or MariaDB 10.0.5+ (InnoDB `FULLTEXT` index support, used by search)
- The `pdo`, `pdo_mysql`, `session`, `json`, and `zip` PHP extensions (`zip` is required only for the manual core-update and theme-install features)
- Apache with `mod_rewrite` (the shipped `.htaccess` files assume Apache)
- `config/`, `storage/logs/`, `storage/sessions/`, `storage/cache/`, and `content/uploads/` writable by the web server user

The installer checks all of the above before doing anything else and shows a clear, specific message if something is missing.

## Installation

1. Point your web server's document root at this directory (`LumoraPress/`), or at a subdirectory of it if you're hosting Lumora Press alongside other sites (e.g. `https://example.com/blog/`) — both are supported, and the installer detects which one it's running under automatically.
2. Visit the site in a browser. If no configuration exists yet, you will be redirected to the installer automatically.
3. Follow the two-step installer: database connection details, then site name, timezone, language, and the administrator account.
4. The `install/` directory is removed automatically once installation succeeds (permissions allowing — if it can't be removed, the success page tells you to delete it by hand, and the admin Dashboard keeps showing a reminder until it's gone — including if a manual update ever restores it).
5. Log in at `/admin/` (or `/blog/admin/` etc. for a subdirectory install).

## Updating

Administrators can update Lumora Press entirely from within the admin panel — no FTP or SSH required — under **Maintenance &rsaquo; Updates**. You can either check GitHub Releases directly or upload an official release ZIP by hand.

- The update package is validated before anything is touched.
- Core files and the database are backed up automatically first.
- Any database migrations the new version needs run automatically.
- Your uploads, your own themes/plugins, and your configuration are never overwritten.
- If anything goes wrong mid-update, Lumora Press rolls back to the pre-update backup automatically.
- If the admin panel itself ever becomes unreachable after a failed update, the backup files can also be restored by hand.

Update history, backup management, and full mechanics are documented in [`docs/UPDATES.md`](docs/UPDATES.md).

## Privacy: Anonymous Install Ping

Lumora Press includes an opt-in, off-by-default mechanism to anonymously count active installs. This provides the developer with a rough, privacy-respecting understanding of real-world adoption, including which PHP versions are still in active use — useful for deciding when support for an older PHP version can be safely phased out.

- **Off by default.** Nothing is ever sent unless you explicitly enable **Anonymous install ping** in Settings &rsaquo; Privacy.
- **What is sent, and nothing else:**
  - A randomly generated install ID, created the first time the feature is enabled. It has no relationship to your domain, content, admin account, or any other data — there is no way to trace it back to your specific site from the ping alone.
  - Your installed Lumora Press version.
  - Your PHP version.
- **What is never sent:** your domain or site title, admin email, post/page/comment content, visitor data, or anything else.
- **Cadence.** The ping fires once immediately when you enable the feature, then at most roughly once a month afterward. It never fires on every page load. A "Send a test ping now" button on the Settings &rsaquo; Privacy screen lets you confirm it's working without waiting a month.
- **Independence from the update checker.** This uses a completely separate request from the GitHub release-check described above — enabling or disabling one never affects the other.

## Cookies

Lumora Press sets a small, fixed set of cookies — useful reference for writing your own site's privacy/cookie policy.

- **Session cookie** (`PHPSESSID` or your server's configured session cookie name). Strictly necessary — keeps a logged-in admin/editor session working. Set only for a logged-in user, never for an anonymous visitor.
- **Remember-me cookie**, set only when a user checks "Remember Me" on the login screen. Strictly necessary for the feature the user explicitly opted into; never set otherwise.
- **`lp_commenter_name` / `lp_commenter_email` / `lp_commenter_url`** — optional convenience cookies that pre-fill a guest's name/email/website on their next comment. Off by default site-wide (Settings &rsaquo; Discussion &rsaquo; "Enable comment cookies consent"), and even when the site owner turns that on, an individual guest still has to check "Save my name/email in this browser for next time" on the comment form itself before any of the three is ever set — no guest gets these cookies without their own explicit, per-comment opt-in.

Nothing else in Lumora Press core sets a cookie. A theme or plugin you install may set its own — check its own documentation.

## Themes & Plugins

Themes are built from familiar, traditional PHP template files (`header.php`, `footer.php`, `single.php`, `page.php`, `archive.php`, `functions.php`, and friends) — no block editor, no `theme.json`, no Full Site Editing. Plugins use a classic hook API (`add_action()`/`do_action()`/`add_filter()`/`apply_filters()`).

- Building a theme: [`docs/THEME-DEVELOPMENT.md`](docs/THEME-DEVELOPMENT.md)
- Building a plugin, or the hook/filter reference: [`docs/DEVELOPER-APIS.md`](docs/DEVELOPER-APIS.md)

## Documentation

- [`docs/FEATURES.md`](docs/FEATURES.md) — full feature inventory
- [`docs/SHORTCODES.md`](docs/SHORTCODES.md) — every `[shortcode]` tag you can type into a post, page, or Download, with its full attribute reference
- [`docs/UPDATES.md`](docs/UPDATES.md) — update system mechanics
- [`docs/THEME-DEVELOPMENT.md`](docs/THEME-DEVELOPMENT.md) — theme author reference
- [`docs/DEVELOPER-APIS.md`](docs/DEVELOPER-APIS.md) — hooks, filters, and plugin reference
- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — codebase structure and core services, for contributors
- [`docs/TROUBLESHOOTING.md`](docs/TROUBLESHOOTING.md) — common problems and fixes
- [`docs/CHANGELOG.md`](docs/CHANGELOG.md) / [`docs/HISTORY.md`](docs/HISTORY.md) — release history
