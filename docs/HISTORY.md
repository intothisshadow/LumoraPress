# History

Completed work is moved here from `TODO.md` at release time.

---

## 0.1.0 — Foundation (2026-07-21)

### PHP Test Suite

- [x] Plan and implement a PHP test suite for Lumora Press, using the FanUpdate Redux test suite as a reference for structure, configuration, and conventions: `/mnt/Winterfell/Coding/Github/Scripts/FanUpdateRedux/PHP Test Suite`. (`/mnt/Winterfell/Coding/Github/Scripts/Lumora/PHP Test Suite` was named as a reference too but was outside this session's accessible directories — FanUpdate Redux's suite, explicitly named as a reference in the line above, was used instead.)
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

Create a modern installer similar in quality to Lumora Gallery `/mnt/Winterfell/Coding/Github/Scripts/Lumora`.

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
