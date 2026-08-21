# Developer APIs

A reference for extending Lumora Press: the hook system, every hook and
filter core actually fires, plugin file structure and lifecycle, and
where to look for the service-layer classes a plugin's own code can call.

For the theme-author-facing template-tag API (rendering posts, pages,
media, comments, widgets, menus), see
[`THEME-DEVELOPMENT.md`](THEME-DEVELOPMENT.md) instead. Plugin authors
will likely need both documents — a plugin that adds theme-visible
behavior (an icon shortcode, an embed provider) still hooks into the same
`content_html`/`head_assets` conventions theme authors rely on.

**Keep this document current.** Whenever a hook/filter is added, changed,
or removed (any `do_action()`/`apply_filters()` call site), or the plugin
loading/lifecycle mechanism changes, update this file in the same
change — see `CLAUDE.md`'s "After Every Code Change" rule. This is
reference material, not a changelog: document the API as it exists right
now, not a history of how it got there.

## The hook system

Classic WordPress-style, implemented in
[`LumoraPress\Core\Hooks\HookManager`](../app/Core/Hooks/HookManager.php)
and exposed procedurally via four global functions
([`include/hooks.php`](../include/hooks.php)):

```php
add_action(string $hook, callable $callback, int $priority = 10): void;
do_action(string $hook, mixed ...$args): void;

add_filter(string $hook, callable $callback, int $priority = 10): void;
apply_filters(string $hook, mixed $value, mixed ...$args): mixed;
```

- **Actions** run every registered callback for `$hook`, in ascending
  priority order (lower runs first), then within a priority in
  registration order. No return value — an action can't short-circuit or
  transform anything.
- **Filters** thread a value through every registered callback:
  `$value = $callback($value, ...$args)`, in the same priority order.
  Each callback must return the (possibly unchanged) value. Calling
  `apply_filters()` with nothing registered is a safe no-op passthrough
  of `$value`.
- **There is no `remove_action()`/`remove_filter()`.** Once registered, a
  callback cannot be unregistered. Design accordingly — don't register a
  callback expecting to conditionally remove it later.
- **No introspection helpers exist** — no `current_filter()`,
  `did_action()`, or callback counts. If you need to know whether a hook
  already fired, track that yourself.

A single shared `HookManager` instance backs both the procedural
functions above and every core service's own internal
`$this->hooks?->doAction(...)` calls — the effect is identical either
way; the nullable `?HookManager` on some services just means hooks can
legitimately be absent in a context with no hook manager wired up (tests,
mainly), not that a plugin's registered callback is treated differently.

## Hooks & filters reference

Every hook/filter core actually fires, grouped by area. "Fires in"
points to the real call site so you can confirm current behavior rather
than trusting this table blindly if something seems off.

### Post lifecycle

| Name | Type | Args | Fires in |
|---|---|---|---|
| `post_saved` | action | `Post $post` | [`PostService`](../app/Services/PostService.php): `create()`, `update()`, `updateSeo()` (if changed), `setStatus()` (if changed). Fires on every save path, not just full create/update. |
| `post_deleted` | action | `int $id` | `PostService::trash()` (soft-delete) **and** `delete()` (permanent). Both fire the same event name — a listener can't tell "moved to Trash" from "permanently removed" from the hook alone. `restore()` fires nothing at all. |

### Page lifecycle

Mirrors Post lifecycle exactly, including the same ambiguity:

| Name | Type | Args | Fires in |
|---|---|---|---|
| `page_saved` | action | `Page $page` | [`PageService`](../app/Services/PageService.php): `create()`, `update()`, `updateSeo()` (if changed), `setStatus()` (if changed). |
| `page_deleted` | action | `int $id` | `PageService::trash()` **and** `delete()` (permanent) — same both-mean-the-same-hook caveat as posts. `restore()` fires nothing. |

### Comment lifecycle

| Name | Type | Args | Fires in |
|---|---|---|---|
| `comment_posted` | action | `Comment $comment` | [`SiteController`](../app/Controllers/SiteController.php)`::submitComment()` and `::submitPageComment()` — the two front-end comment-form handlers, after the comment is saved, before notification dispatch. **Not fired by the REST API's comment-creation endpoint** — a listener relying on this alone will miss comments created via the API. |
| `comment_status_changed` | action | `Comment $comment` (reloaded, new status) | `CommentService::updateStatus()`. |
| `comment_deleted` | action | `int $id` | `CommentService::delete()`. |
| `comment_is_spam` | filter | `bool $isSpam, string $guestName, ?string $guestEmail, ?string $guestUrl, string $content, ?string $ipAddress` | Applied identically in three places: `SiteController::submitComment()`, `::submitPageComment()`, and `ApiController`'s comment-creation handler — the intended extension point for a spam-detection plugin (Akismet integration uses this). Can only push a comment *toward* Spam — returning `true` sets Spam status; you cannot un-spam a comment another listener already flagged. |

### Category lifecycle

| Name | Type | Args | Fires in |
|---|---|---|---|
| `category_saved` | action | `Category $category` | [`CategoryService`](../app/Services/CategoryService.php): `create()`, `update()`. |
| `category_trashed` | action | `int $id` | `trash()`. |
| `category_restored` | action | `int $id` | `restore()` — unlike posts/pages, categories *do* fire on restore. |
| `category_deleted` | action | `int $id` | `delete()` (permanent). |
| `category_merged` | action | `int $sourceId, int $targetId` | `merge()`, after the merge transaction completes. |

### Tag lifecycle

| Name | Type | Args | Fires in |
|---|---|---|---|
| `tag_saved` | action | `Tag $tag` | [`TagService`](../app/Services/TagService.php): `create()`, `update()`. |
| `tag_deleted` | action | `int $id` | `delete()`. |

### Media lifecycle

| Name | Type | Args | Fires in |
|---|---|---|---|
| `media_saved` | action | `array $media` (the reloaded row as an **array**, not an object — unlike every other `*_saved` hook above) | [`MediaService`](../app/Services/MediaService.php): `upload()`, `registerExistingFile()`, `replace()` only. **Not fired** by `updateMetadata()`, `move()`, `setVideoAssets()`, or either bulk-update method, even though those mutate the record — don't assume this is a general "media record changed" hook. |
| `media_deleted` | action | `int $id` | `MediaService::delete()`. |
| `thumbnail_generated` | action | `int $mediaId, string $sizeName, string $path` | [`ThumbnailService`](../app/Services/ThumbnailService.php)`::generate()`, once per successfully generated size. |
| `thumbnail_generation_failed` | action | `int $mediaId, string $sizeName, string $errorMessage` | Same method's failure path. |
| `thumbnail_sizes` | filter | `array $sizes` (keyed by size name ⇒ `{width, height, mode, enabled}`) | `ThumbnailService::sizes()` — add or modify thumbnail size definitions. |
| `thumbnail_max_pixels` | filter | `int $configuredMaxPixels` | `ThumbnailService::generate()` — the decompression-bomb safeguard ceiling; raise or lower it. |
| `media_usage` | filter | `array $usages, int $mediaId` | [`MediaUsageChecker`](../app/Services/MediaUsageChecker.php) — add your own "in use by X" reasons (core already reports post/page featured-image, site logo, favicon, default OG image usage) so the Media Library's delete-confirmation dialog also warns about your plugin's own usage of an image. |

### Rendering / output

| Name | Type | Args | Fires in |
|---|---|---|---|
| `head_assets` | action | none | Theme convention, not core — `content/themes/default/header.php` calls `do_action('head_assets')` right before `</head>`; every theme is expected to do the same (mirrors WordPress's `wp_head`). The Font Awesome plugin listens here to print its `<link>` tag. |
| `footer_assets` | action | none | Same theme convention, fired right before `</body>`. **Core itself listens here** ([`FooterAssets`](../app/Core/Theme/FooterAssets.php), registered in `include/bootstrap.php`) to emit lightbox/embed script tags — a theme that skips this hook silently breaks `the_post_thumbnail_lightbox()` and auto-embeds. |
| `get_header` / `get_footer` / `get_sidebar` | action | none | Fired at the top of the corresponding [`get_header()`/`get_footer()`/`get_sidebar()`](../include/theme.php) template-tag function, before the partial is rendered. |
| `comments_template` | action | none | Fired inside `comments_template()`, before `comments.php` renders. |
| `content_html` | filter | `string $html, string $rawContent, ContentFormat $format` | [`ContentRenderer`](../app/Services/ContentRenderer.php)`::render()` — the *final* HTML filter, after format-specific rendering, sanitization, and lightbox-attribute injection. Font Awesome's `[icon]`-shortcode handling uses this at priority 20 specifically to run after other subscribers. |
| `markdown_html` | filter | `string $html, string $rawContent` | Same method, Markdown-only, applied to the parser's raw HTML output *before* sanitization. |
| `wysiwyg_html` | filter | `string $html` | Same method, HTML-format-only, before sanitization. |
| `embed_providers` | filter | `array $providers` (`{key, label, match, allow, allowfullscreen, aspect, type?}[]`) | [`EmbedService`](../app/Services/EmbedService.php)`::providers()` — register an auto-embed provider beyond the built-in ones. |
| `embed_html` | filter | `string $html, string $providerKey, string $src` | `EmbedService`, the final embed markup for one matched URL (two call sites: the default iframe path, and a `'blockquote'`-wrapped non-iframe path — see `embed_providers` above). |
| `csp_directives` | filter | `array $directives` | Applied last in [`include/bootstrap.php`](../include/bootstrap.php), after plugins and the theme's `functions.php` have loaded, right before the CSP header is sent. If your plugin/theme loads anything from an external origin, whitelist it here (see Font Awesome's `filterCsp()` for a real example). |
| `feed_channel` | filter | `array{title, description} $channel` | [`FeedService`](../app/Services/FeedService.php)`::channel()` — the site-wide RSS/Atom feed. |
| `feed_category_channel` | filter | `array{title, description} $channel, Category $category` | `FeedService::categoryChannel()` — per-category feed variant. |
| `feed_item` | filter | `array{post, authorName, description, content, thumbnailUrl, thumbnailType, thumbnailLength} $item, Post $post` | `FeedService::buildItem()` — applied to every feed `<item>`, both site-wide and per-category. |
| `feed_generated` | action | `string $format` (`'rss'`/`'atom'`) | `SiteController::feed()`, fired **after** the feed body has already been echoed — output/headers are already sent, so a listener can observe/log but not modify the response. |
| `gettext` | filter | `string $text, string $domain` | Inside `__()` ([`include/helpers.php`](../include/helpers.php)) — there's no built-in translation loader, this filter is the extension point for one. `_e()` calls `__()` internally, so it's covered too. |

### Theme Options & settings

| Name | Type | Args | Fires in |
|---|---|---|---|
| `register_theme_options` | action | `ThemeOptions $themeOptions` | Fired once at bootstrap, after the active theme's `functions.php` has loaded and after core's own eleven built-in fields are registered. See [`THEME-DEVELOPMENT.md`](THEME-DEVELOPMENT.md)'s Theme Options section for the registration pattern. |
| `option_changed` | action | `string $key, string\|null $serialized` | [`PressConfig`](../app/Core/PressConfig.php)`::setOption()` — fires on **every** option write anywhere in the app, including in-memory-only config with no database bound. The single most universal "something in settings changed" hook. |
| `general_settings_saved` | action | `string $section` (`'site_settings'` \| `'date_time_settings'` \| `'footer_settings'` \| `'seo_settings'`) | `admin/views/settings/general.php`, once per successfully-saved sub-form. **Not fired** by that same screen's Feed, Search, Revision, REST API, or Editor sub-forms — those save silently. Don't treat this as a complete "any setting changed" event; use `option_changed` for that instead. |
| `maintenance_mode_toggled` | action | `bool $enabled` | `admin/views/settings/maintenance-mode.php` — both the full settings form and the quick Dashboard toggle fire this. |
| `maintenance_mode_bypass` | filter | `bool $bypasses, ?User $user` | [`MaintenanceGate`](../app/Core/Http/MaintenanceGate.php)`::bypassesFor()` — add bypass rules beyond the built-in capability check. Guests (`$user === null`) still pass through with `$bypasses = false`. |

### Admin UI extension points

| Name | Type | Args | Fires in |
|---|---|---|---|
| `lp_plugin_details_panel` | action | `PluginInfo $info` | `admin/views/plugins.php`, inside a plugin's expanded details panel — render extra UI about a plugin here. |
| `lp_theme_details_panel` | action | `ThemeInfo $info` | `admin/views/appearance/themes.php`, same pattern on the Themes screen. |

**There is no hook or API for a plugin to add its own admin menu item or
page.** The shipped plugins work around this differently: Font Awesome
adds no admin UI at all; Dummy Content's admin section is wired directly
into an existing core view (Maintenance › Tools), not registered
dynamically; WordPress Importer (LPP-004) does the same into a different
existing, always-present menu slot (Maintenance › Import —
`admin/views/maintenance/import.php`), gated the identical way Dummy
Content's section is (an `{slug}Active` boolean computed once in
`admin/index.php` from the active-plugins option, shared into the view
via normal PHP `require`-scope). If your plugin needs admin UI and no
existing menu slot fits, follow Dummy Content's pattern (a gated section
on an existing screen) rather than assuming a registration hook exists.

### Editor

| Name | Type | Args | Fires in |
|---|---|---|---|
| `registered_editors` | filter | `array $builtIn` (`{value, label}[]`, one per `ContentFormat`) | [`EditorPreferenceService`](../app/Services/EditorPreferenceService.php)`::registeredEditors()` — advertise an additional authoring-UI choice. Content is still always *stored* as one of the three `ContentFormat` cases (Markdown/HTML/Plain); this only affects which editor UI is offered, not a new storage format. |

### Application lifecycle & updates

| Name | Type | Args | Fires in |
|---|---|---|---|
| `lumora_press_loaded` | action | none | The very last line of [`include/bootstrap.php`](../include/bootstrap.php), after routes are registered and the CSP header is set — the closest equivalent to WordPress's `init`/`wp_loaded`. The natural place for a plugin's own late setup that needs everything else already in place. |
| `lumora_press_before_update` | action | `string $fromVersion, string $toVersion` | [`UpdateService`](../app/Services/UpdateService.php)`::install()`, right after the maintenance lock is acquired, before backups start. |
| `lumora_press_after_update` | action | `string $fromVersion, string $toVersion, UpdateStatus $status` | Same method, fired on **both** the success path and the failure/rollback path — check `$status` to tell them apart. |

### REST API

| Name | Type | Args | Fires in |
|---|---|---|---|
| `rest_api_request` | action | `string $method, string $path, ?User $user` | The first line of every public [`ApiController`](../app/Controllers/ApiController.php) action — fires for **every** request regardless of whether it's ultimately allowed, so a plugin can observe real traffic. |
| `rest_api_enabled` | filter | `bool $enabled` | Same gate method, the global REST API on/off toggle. |
| `rest_api_resource_enabled` | filter | `bool $resourceEnabled, string $resource` | Same gate method, per-resource toggle. |

**The REST API's routes are fixed** — registered directly against the
router in `include/bootstrap.php`, with no hook or `PluginManager`
facility for a plugin to add a *new* endpoint. The three hooks above
(plus the shared `comment_is_spam` filter, for the comment-creation
endpoint) are the only plugin-facing extension surface on the REST
layer. If you need a genuinely custom endpoint, there's currently no
supported way to add one.

## Plugin structure & lifecycle

A plugin lives in its own directory under `content/plugins/{slug}/`, with
a main file that **must** be named `{slug}.php` — `PluginManager::load()`
requires exactly `content/plugins/{slug}/{slug}.php`.

### Plugin file header

Mirrors a theme's `style.css` header, as a plain block comment (not a
docblock) right after the file's own `@package`/`@subpackage`/etc.
docblock and `declare(strict_types=1);`:

```php
<?php

/**
 * The <Name> plugin's main file: plugin metadata header and bootstrap.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Your Name
 * @copyright Copyright (c) 2026 Your Name
 * @license GPL-3.0-or-later
 * @link https://example.com
 * @since 1.0.0
 */

declare(strict_types=1);

/*
 * Plugin Name: My Plugin
 * Plugin URI: https://example.com/my-plugin
 * Description: A short description of what this plugin does.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Tags: comma, separated, tags
 * Requires at least: 0.6.0
 * Requires PHP: 8.2
 * Requires Plugins: some-other-plugin-slug
 */

namespace LumoraPress\Plugins\MyPlugin;

require_once __DIR__ . '/src/MyPluginService.php';

add_action('head_assets', static function (): void {
    // ...
});
```

`Requires Plugins:` (a dependency slug list) is parsed by
[`PluginInfo`](../app/Core/Plugin/PluginInfo.php) but isn't used by
either shipped plugin. Also parsed but not header fields: `screenshots`/
`readmeFile`/`changelogFile` are detected automatically from files
present in the plugin directory (a `README.md`/`CHANGELOG.md` and any
`screenshot*`/`preview*` image), the same convention themes use.

### Discovery & loading

[`PluginManager::discover()`](../app/Core/PluginManager.php) globs every
subdirectory of `content/plugins/` — any directory is a candidate plugin
regardless of whether it's active. `PluginManager::load($slug)` then
`require_once`s that plugin's main file directly; `loadActive()` loops
this over the site's configured list of active plugin slugs. There's no
manifest file and no class-based entry point — the plugin file's
top-level (module-scope) code *is* its init logic, since PHP has no
separate "plugin activation" phase distinct from "the file was required."

**Plugin main files load before `Kernel` exists.** `include/bootstrap.php`
requires active plugins before it builds `$kernel` — so a plugin's
top-level code cannot reach `$kernel`'s services directly at load time.
It can only register hook callbacks (closures), which will run later once
services do exist, or defer real work into something that receives
`$kernel` from its own caller (Dummy Content's admin-view integration
does this — it's handed `$kernel` by the core view it's embedded in,
rather than fetching it itself). Font Awesome's approach — a plugin-owned
singleton service class (`FontAwesomeService::instance()`) that hook
callbacks delegate to — is the more common pattern for a plugin with its
own real logic.

A `content_html`-filter callback (or any other hook that fires during a
real public request, well after `$kernel` exists) still can't reach
`$kernel`'s services just by virtue of running later — the closure was
registered at load time, before `$kernel` existed, so it has no
reference to it. For hook logic that genuinely needs Kernel-only
services (a real DB-backed service like `FolderService`/`MediaService`,
not config — Font Awesome's own hooks need no DB access, hence the
singleton pattern above being enough for it), open a second,
independent `Database::connect()` from `config/config.php`'s own
credentials instead — the same "a plugin can talk to a database Kernel
doesn't already expose" approach `WordPressSource` (LPP-004) uses for
its *source* WordPress database, just pointed at this site's own
database. `DownloadsShortcode` (LPP-007, `wordpress-importer` plugin's
`[sdm_show_dl_from_category]`) does exactly this on `content_html`,
gated behind a cheap `str_contains()` check first so the connection is
only ever opened on the rare page that actually needs it. The Downloads
plugin's own `[lumora_downloads]` shortcode (LPP-008, a distinct class
of the same name in a different namespace) follows the identical
pattern for its own `content_html` registration.

**There is no activation/deactivation hook.** Toggling a plugin active/
inactive (Plugins admin screen) just adds or removes its slug from the
active-plugins option list; nothing fires a lifecycle event a plugin can
hook to run setup/teardown code. If your plugin needs first-load setup
(creating its own database table, say), do it defensively on every load
rather than expecting an activation hook to exist.

## Service layer

Every piece of business logic lives in a focused service class,
constructed once in [`include/bootstrap.php`](../include/bootstrap.php)
and exposed on `LumoraPress\Core\Kernel` (`$kernel->posts`,
`$kernel->pages`, `$kernel->categories`, `$kernel->tags`,
`$kernel->comments`, `$kernel->media`, `$kernel->users`, and so on — see
the [README](../README.md)'s Architecture section for the full list and
what each one owns).

A plugin only reaches `$kernel` from a context that already has it —
inside a hook callback fired from code that was itself given `$kernel`
(most core hooks fire from service methods or controllers that don't
pass `$kernel` through to the callback, so most hook callbacks work with
whatever arguments the hook itself provides, not a fresh `$kernel`
lookup), or from an admin view your plugin is embedded into. There is no
global `$kernel` accessor a plugin can call from anywhere — this is
deliberate, matching the project's "no global state" architecture
standard (see `CLAUDE.md`'s PHP Development Standards).

## Also see

- [`THEME-DEVELOPMENT.md`](THEME-DEVELOPMENT.md) — template-tag API,
  template hierarchy, widget/menu/Theme-Options registration.
- [`THIRD-PARTY.md`](THIRD-PARTY.md) — every third-party JS asset bundled
  or referenced, and the CSP implications of adding another one.
- The README's [Architecture](../README.md) section — the core service
  classes and what each one is responsible for.
