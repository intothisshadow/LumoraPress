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
| `single_post_viewed` | action | `Post $post, bool $isGuest` | `SiteController::singlePost()`, once per request, after the post is resolved and visibility-checked, before rendering. A no-op unless something listens — core carries no view-tracking logic of its own. `$isGuest` is `!$this->auth->check()`, the same idiom `markCacheableForGuests()` uses. The Visitor & Post View Statistics plugin (LPP-014) is this hook's first listener. |

### Page lifecycle

Mirrors Post lifecycle exactly, including the same ambiguity:

| Name | Type | Args | Fires in |
|---|---|---|---|
| `page_saved` | action | `Page $page` | [`PageService`](../app/Services/PageService.php): `create()`, `update()`, `updateSeo()` (if changed), `setStatus()` (if changed). |
| `page_deleted` | action | `int $id` | `PageService::trash()` **and** `delete()` (permanent) — same both-mean-the-same-hook caveat as posts. `restore()` fires nothing. |

### Comment lifecycle

| Name | Type | Args | Fires in |
|---|---|---|---|
| `comment_posted` | action | `Comment $comment` | [`SiteController`](../app/Controllers/SiteController.php)`::submitComment()` and `::submitPageComment()` — the two front-end comment-form handlers, after the comment is saved, before notification dispatch. |
| `comment_status_changed` | action | `Comment $comment` (reloaded, new status) | `CommentService::updateStatus()`. |
| `comment_deleted` | action | `int $id` | `CommentService::delete()`. |
| `comment_is_spam` | filter | `bool $isSpam, string $guestName, ?string $guestEmail, ?string $guestUrl, string $content, ?string $ipAddress` | Applied identically in two places: `SiteController::submitComment()` and `::submitPageComment()` — the intended extension point for a spam-detection plugin. (Core's own Akismet integration is called inline at each of those same call sites, *not* through this filter.) The Lumora Shield plugin's Comment Analysis module is this filter's first real listener, pushing toward Spam based on content/behavioral heuristics (see `LumoraShieldService::commentIsSpam()`). Can only push a comment *toward* Spam — returning `true` sets Spam status; you cannot un-spam a comment another listener already flagged. |
| `contact_form_is_spam` | filter | `bool $isSpam, array<string, string> $data, string $ipAddress` | `ContactFormSubmissionHandler::handle()` (Contact Forms plugin, LPP-003) — applied after that plugin's own CSRF/honeypot/FormTiming/rate-limit/CAPTCHA/Akismet checks, right before persisting the submission. `$data` is every submitted field, keyed by field key (an arbitrary, per-form set — Name/Email/Subject/Message/etc.). Same can-only-push-toward-Spam contract as `comment_is_spam`. The Lumora Shield plugin's Contact Form Protection module is this filter's first listener, reusing `CommentAnalyzer::contentReasons()` against every field value joined together (see `LumoraShieldService::contactFormIsSpam()`). |

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
| `tag_merged` | action | `int $sourceId, int $targetId` | `merge()`, after the merge transaction completes. |

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
| `head_assets` | action | none | Theme convention, not core — `content/themes/lumora-classic/header.php` calls `do_action('head_assets')` right before `</head>`; every theme is expected to do the same (mirrors WordPress's `wp_head`). The Font Awesome plugin listens here to print its `<link>` tag. |
| `footer_assets` | action | none | Same theme convention, fired right before `</body>`. **Core itself listens here** ([`FooterAssets`](../app/Core/Theme/FooterAssets.php), registered in `include/bootstrap.php`) to emit lightbox/player/embed script tags — a theme that skips this hook silently breaks `the_post_thumbnail_lightbox()`, `[lumora_audio]`/`[lumora_video]`, and auto-embeds. |
| `get_header` / `get_footer` / `get_sidebar` | action | none | Fired at the top of the corresponding [`get_header()`/`get_footer()`/`get_sidebar()`](../include/theme.php) template-tag function, before the partial is rendered. |
| `comments_template` | action | none | Fired inside `comments_template()`, before `comments.php` renders. |
| `content_html` | filter | `string $html, string $rawContent, ContentFormat $format` | [`ContentRenderer`](../app/Services/ContentRenderer.php)`::render()` — the *final* HTML filter, after format-specific rendering, sanitization, and lightbox-attribute injection. Font Awesome's `[icon]`-shortcode handling uses this at priority 20 specifically to run after other subscribers. Core's own `[lumora_folder_gallery]` shortcode ([`FolderGalleryShortcode`](../app/Services/FolderGalleryShortcode.php)) and `[lumora_audio]`/`[lumora_video]` ([`MediaPlayerShortcode`](../app/Services/MediaPlayerShortcode.php)) are also registered here, at the default priority — see Shortcodes below. Every callback runs in one single pass, in priority order, each one seeing the *previous* callback's output text — not a separate re-scan per callback. The WordPress Importer plugin's `NextGenGalleryShortcode` relies on this: it rewrites NextGEN Gallery shortcode text into `[lumora_folder_gallery ...]` syntax at priority **5**, specifically so `FolderGalleryShortcode`'s own priority-10 callback still sees and renders that rewritten text within the same pass — a plugin that rewrites one shortcode into another must always register at a lower priority than the shortcode it's rewriting into. |
| `markdown_html` | filter | `string $html, string $rawContent` | Same method, Markdown-only, applied to the parser's raw HTML output *before* sanitization. |
| `wysiwyg_html` | filter | `string $html` | Same method, HTML-format-only, before sanitization. |
| `embed_providers` | filter | `array $providers` (`{key, label, match, allow, allowfullscreen, aspect, type?}[]`) | [`EmbedService`](../app/Services/EmbedService.php)`::providers()` — register an auto-embed provider beyond the built-in ones. |
| `embed_html` | filter | `string $html, string $providerKey, string $src` | `EmbedService`, the final embed markup for one matched URL (two call sites: the default iframe path, and a `'blockquote'`-wrapped non-iframe path — see `embed_providers` above). |
| `csp_directives` | filter | `array $directives` | Applied last in [`include/bootstrap.php`](../include/bootstrap.php), after plugins and the theme's `functions.php` have loaded, right before the CSP header is sent. If your plugin/theme loads anything from an external origin, whitelist it here (see Font Awesome's `filterCsp()` for a real example). |
| `feed_channel` | filter | `array{title, description} $channel` | [`FeedService`](../app/Services/FeedService.php)`::channel()` — the site-wide RSS/Atom feed. |
| `feed_category_channel` | filter | `array{title, description} $channel, Category $category` | `FeedService::categoryChannel()` — per-category feed variant. |
| `feed_tag_channel` | filter | `array{title, description} $channel, Tag $tag` | `FeedService::tagChannel()` — per-tag feed variant. |
| `feed_author_channel` | filter | `array{title, description} $channel, User $author` | `FeedService::authorChannel()` — per-author feed variant. |
| `feed_pages_channel` | filter | `array{title, description} $channel` | `FeedService::pagesChannel()` — the site-wide Pages feed (`/pages/feed`). |
| `feed_comments_channel` | filter | `array{title, description} $channel` | `FeedService::commentsChannel()` — the site-wide comments feed (`/comments/feed`). |
| `feed_post_comments_channel` | filter | `array{title, description} $channel, Post $post` | `FeedService::postCommentsChannel()` — a single post's comment-thread feed. |
| `feed_item` | filter | `array{post, authorName, description, content, thumbnailUrl, thumbnailType, thumbnailLength, categoryNames, tagNames} $item, Post $post` | `FeedService::buildItem()` — applied to every feed `<item>`, across the site-wide, per-category, per-tag, and per-author feeds. `categoryNames`/`tagNames` are plain `string[]` — the post's assigned category/tag names, rendered as RSS2 `<category>`/Atom `<category term>`/JSON Feed `tags`. |
| `feed_page_item` | filter | `array{page, authorName, description, content, thumbnailUrl, thumbnailType, thumbnailLength} $item, Page $page` | `FeedService::buildPageItem()` — the Pages feed's counterpart of `feed_item`, applied to every `<item>` in `/pages/feed`. |
| `feed_comment_item` | filter | `array{comment, description, content} $item, Comment $comment` | `FeedService::buildCommentItem()` — applied to every comment feed's `<item>`, across the site-wide comments feed and every post's own comment-thread feed. `SiteController` adds `link`/`title` after this filter runs, so a listener only ever sees `description`/`content`. |
| `feed_formats` | filter | `array<string, callable> $formats` | `SiteController::resolveFeedFormat()`/`renderCustomFormat()` — registers a custom feed format beyond the built-in `rss`/`atom`/`json`. Add `$formats['podcast'] = $renderer` where `$renderer` is `callable(string $format, array $channel, array $items, string $channelLink, string $selfLink, string $family): string`, `$family` being `'posts'`/`'pages'`/`'comments'` (which item shape `$items` carries — see `FeedService`'s own item-shape docblocks per family). Once registered, `{feed-url}/podcast` renders it on every feed family (site-wide/category/tag/author/Pages/comments/a plugin's own registered type). |
| `feed_content_type` | filter | `string $contentType, string $format` | `SiteController::feedContentType()` — supplies the `Content-Type` header for a custom format registered via `feed_formats`; called only when `$format` isn't `rss`/`atom`/`json`. |
| `feed_types` | filter | `array<string, array{channel: callable, items: callable, shape?: string, link?: string}> $types` | `SiteController::customTypeFeed()`/`registeredFeedTypes()` — registers an entirely new feed reachable at `/feed/x/{type}` (and `/feed/x/{type}/{format}`), not scoped to any existing category/tag/author/post. `channel`/`items` are zero-argument callables returning the same shapes `FeedService::channel()`/`items()` (or the `pages`/`comments` equivalents) return; `shape` picks which `emit*Feed()` family renders them (default `'posts'`); `link` overrides the channel's home-page link (defaults to `home_url()`). |
| `feed_generated` | action | `string $format` (`'rss'`/`'atom'`/`'json'`, or a `feed_formats`-registered custom format) | `SiteController::feed()`/every other feed handler, fired **after** the feed body has already been echoed — output/headers are already sent, so a listener can observe/log but not modify the response. |
| `lumora_shield_author_archive_visible` | filter | `bool $visible, User $author, int $publishedPostCount` | `SiteController::author()` — decides whether a resolved `/author/{slug}` request actually renders, called *after* core has already 404'd a zero-published-post author unconditionally (no downside to that half — see the method's own docblock), so `$visible` always arrives `true` here. `SiteController::authorFeed()` fires the same filter for `/author/{slug}/feed`, so a plugin only has to handle this filter once to cover both. The Lumora Shield plugin's Stop User Enumeration module uses this filter for its one remaining optional tradeoff: hiding every author archive outright, including real ones with published posts. |
| `lumora_shield_enumeration_blocked` | action | `string $slug, string $reason, string $ipAddress` | `SiteController::author()`/`authorFeed()` — fired on every blocked `/author/{slug}` or `/author/{slug}/feed` request (`$reason` is one of `'unknown_user'`/`'zero_posts'`/`'hidden_by_setting'`), a no-op unless something listens. The Lumora Shield plugin's Monitoring sub-module listens here to log the attempt. |
| `gettext` | filter | `string $text, string $domain` | Inside `__()` ([`include/helpers.php`](../include/helpers.php)) — there's no built-in translation loader, this filter is the extension point for one. `_e()` calls `__()` internally, so it's covered too. |

### Shortcodes

See [`SHORTCODES.md`](SHORTCODES.md) for the user-facing reference (every currently-registered shortcode's attributes and copy-paste examples). This section covers building your own.

A shortcode's actual *rendering* is still just a plugin or core class
that hooks the `content_html` filter above and does its own
`preg_replace_callback()` over a fixed pattern — see
`content/plugins/downloads/src/DownloadsShortcode.php` for the
reference plugin implementation, or `FolderGalleryShortcode` below for
the core equivalent. Registering a shortcode with `register_shortcode()`
(LP-110) is a separate, optional step on top of that: it adds the
shortcode to the editor toolbar's "Insert Shortcode" picker
(`admin/assets/js/content-editor.js`'s `openShortcodePicker()`), a real
form built from the attribute fields you describe, instead of the admin
typing `[shortcode attr="value"]` by hand. It changes nothing about how
the shortcode itself renders.

```php
use LumoraPress\Core\Shortcodes\ShortcodeField;
use LumoraPress\Core\Shortcodes\ShortcodeFieldType;

register_shortcode('my_shortcode', 'My Shortcode', [
    new ShortcodeField('category_id', 'Category', ShortcodeFieldType::Select, required: true, choices: ['1' => 'Screenshots']),
    new ShortcodeField('show_size', 'Show file size', ShortcodeFieldType::Checkbox, default: '0'),
]);
```

`ShortcodeField`'s `$type` is one of `Text`, `Number`, `Checkbox`,
`Select` (needs `$choices`, value => label), or `Icon` (opens Font
Awesome's own icon-browser dialog instead of a plain control — see
`font-awesome.php`'s own registration for the pattern, including how an
Icon field auto-fills a sibling field literally named `style`). A field
left at its `$default` value is omitted from the inserted shortcode
entirely, the same minimal text typing it by hand would produce.

A plugin whose fields need database-backed choices (a live list of
Folders or Download categories, say) can't call `register_shortcode()`
directly from its own top-level file — plugin main files load before
Kernel exists (see "Discovery & loading" below). Hook the
`register_shortcodes` action instead:

| Name | Type | Args | Fires in |
|---|---|---|---|
| `register_shortcodes` | action | `ShortcodeManager $registry, Kernel $kernel` | The end of `include/bootstrap.php`, right after `$kernel` is built. `wordpress-importer.php`, `downloads.php`, and `contact-forms.php` all use this to build their own choices from `$kernel`'s services; `font-awesome.php` uses it too, for consistency, even though its fields need no database access. `lumora-gallery-shortcodes.php` also hooks it, but queries its own separately-configured Gallery connection directly rather than through `$kernel` — its data has nothing to do with this site's own database. |

| Shortcode | Registered by | Syntax |
|---|---|---|
| `[icon]` | Font Awesome plugin (LPP-002) — `FontAwesomeService`, wired in `font-awesome.php` | `[icon name="star" style="solid"]` — see `FontAwesomeService::icon()`'s own docblock for the full attribute list (`color`, `class`, `label`, `animation` too — only `name`/`style`/`label` are registered for the picker). |
| `[lumora_folder_gallery]` | Core — [`FolderGalleryShortcode`](../app/Services/FolderGalleryShortcode.php), wired in `include/bootstrap.php` | `[lumora_folder_gallery folder_id="12" link="full"]` — renders every image in Media folder `folder_id` as a row of thumbnails. `link` is `none` (default) or `full` (wraps each thumbnail in a link to the full-size image, joining the post's PhotoSwipe lightbox gallery the same way an Insert Image "Link To: Media File" image does). A missing/deleted folder, or a folder with no images, renders nothing. Inserted via the content editor's own dedicated "Insert Folder" toolbar button, not the generic shortcode picker — not registered with `register_shortcode()`. |
| `[lumora_audio]`/`[lumora_video]` | Core — [`MediaPlayerShortcode`](../app/Services/MediaPlayerShortcode.php), wired in `include/bootstrap.php` | `[lumora_audio id="12"]`/`[lumora_video id="12"]` — renders the given Media item as a native `<audio>`/`<video>` player, Plyr-enhanced client-side (see "Also see" below). `id` must resolve to a Media item whose mime type actually matches (`audio/*`/`video/*`); a missing `id`, unresolvable id, or mime-type mismatch (e.g. `[lumora_video]` pointed at an MP3) renders nothing. A video's optional poster image/WebVTT caption track (set on the Media item's own edit screen) render as the `poster` attribute/a `<track>` child automatically. Both are inserted via the content editor's dedicated "Insert Audio"/"Insert Video" toolbar buttons, not the generic shortcode picker — not registered with `register_shortcode()`. |
| `[sdm_show_dl_from_category]` | WordPress Importer plugin (LPP-004/LPP-007) — `DownloadsShortcode`, wired in `wordpress-importer.php` | `[sdm_show_dl_from_category category_slug="game-of-thrones" show_size="1"]` — every download filed under the Media folder whose slugified name matches `category_slug`. That file's own `[sdm_download]`/`[sdm_latest_downloads]` shortcodes are unregistered — still typeable by hand. |
| `[lumora_downloads]` | Downloads plugin (LPP-008) — `DownloadsShortcode`, wired in `downloads.php` | `[lumora_downloads category_id="3" show_size="1"]` — every download in the given Download category (a wholly separate table/concept from Media Folders — see `DownloadCategory`'s own docblock). `download_id`/`count`, the single-download and "newest N" variants, are unregistered — still typeable by hand. |
| `[contact_form]` | Contact Forms plugin (LPP-003) — `ContactFormShortcode` (`content/plugins/contact-forms/src/`) | `[contact_form id="1"]` — renders the given form (see Contact Forms &rsaquo; All Forms for each form's id/shortcode). GET-time rendering only; the actual submission POSTs to a dedicated route (see "no hook to register a public route," above), not this shortcode. Registered (LPP-017) — picker's `id` field lists every form by title, via `ContactFormService::listAll()`. |
| `[nggallery]`/`[album]`/`[ngg_images]`/`[ngg]` | WordPress Importer plugin (LPP-016) — `NextGenGalleryShortcode` (`content/plugins/wordpress-importer/src/`), wired in `wordpress-importer.php` at priority 5 | Not rendered directly — recognizes migrated NextGEN Gallery shortcode text (`[nggallery id="X"]`/`ids="1,2"`, `[album id="X"]`, `[ngg_images source="galleries"/"albums" container_ids="X"]`, `[ngg src="galleries"/"albums" ids="X" ...]`) and rewrites it into `[lumora_folder_gallery folder_id="Y" link="full"]`, resolving each NextGEN gallery/album id to its imported Folder via `ContentImportRegistry::existingLocalId('wordpress_import', 'folder', $externalId)` — the same `'folder'` provenance `WordPressImportService::createOrUpdateFolder()` already records. `[nggtags]`/`[ngg_slideshow]` are left unrewritten (no static-grid equivalent). Unregistered — no shortcode picker entry, since it's a rewrite of existing migrated content, not something an author types by hand. |
| `[lumora_gallery_album]`/`[lumora_gallery_newest]` | Lumora Gallery Shortcodes plugin (LPP-015) — `GalleryShortcode` (`content/plugins/lumora-gallery-shortcodes/src/`), wired in `lumora-gallery-shortcodes.php` | `[lumora_gallery_album album_id="12"]` (or `folder="..."`, or `count="N"` for the newest N, or `image_id="4,9,12"` for specific images — `album_id`/`folder` is optional with `image_id`, since an image id is already globally unique) and `[lumora_gallery_newest count="10"]` (newest N across every public album). Reads a *separately-configured Lumora Gallery site's own database* directly (`GallerySettingsService`, Settings screen credentials) — a different application's data, not anything in this site's own database. Partially registered (LPP-017): `[lumora_gallery_album]`'s picker form covers only the "whole album" variant (`album_id`, listed via the new `GalleryQueryService::listAlbums()`) — `count`/`image_id` stay typeable by hand. `[lumora_gallery_newest]`'s `count` is registered in full. An unconfigured or unreachable Gallery connection yields an empty `album_id` choice list rather than an error. |

### Theme Options & settings

| Name | Type | Args | Fires in |
|---|---|---|---|
| `register_theme_options` | action | `ThemeOptions $themeOptions` | Fired once at bootstrap, after the active theme's `functions.php` has loaded and after core's own sixteen built-in fields are registered. Values are scoped per active theme (LP-123) — see [`THEME-DEVELOPMENT.md`](THEME-DEVELOPMENT.md)'s Theme Options section for the registration pattern and the Appearance &rsaquo; Customize screen's template tags. |
| `option_changed` | action | `string $key, string\|null $serialized` | [`PressConfig`](../app/Core/PressConfig.php)`::setOption()` — fires on **every** option write anywhere in the app, including in-memory-only config with no database bound. The single most universal "something in settings changed" hook. |
| `general_settings_saved` | action | `string $section` (`'site_settings'` \| `'date_time_settings'` \| `'footer_settings'` \| `'seo_settings'`) | `admin/views/settings/general.php`, once per successfully-saved sub-form. **Not fired** by that same screen's Feed, Search, Revision, or Editor sub-forms — those save silently. Don't treat this as a complete "any setting changed" event; use `option_changed` for that instead. |
| `maintenance_mode_toggled` | action | `bool $enabled` | `admin/views/settings/maintenance-mode.php` — both the full settings form and the quick Dashboard toggle fire this. |
| `maintenance_mode_bypass` | filter | `bool $bypasses, ?User $user` | [`MaintenanceGate`](../app/Core/Http/MaintenanceGate.php)`::bypassesFor()` — add bypass rules beyond the built-in capability check. Guests (`$user === null`) still pass through with `$bypasses = false`. |

### Admin UI extension points

| Name | Type | Args | Fires in |
|---|---|---|---|
| `lp_plugin_details_panel` | action | `PluginInfo $info` | `admin/views/plugins.php`, inside a plugin's expanded details panel — render extra UI about a plugin here. |
| `lp_theme_details_panel` | action | `ThemeInfo $info` | `admin/views/appearance/themes.php`, same pattern on the Themes screen. |
| `dashboard_widgets` | action | `User $currentUser` | `admin/views/dashboard.php` — a listener `echo`s one complete `<section class="lp-admin__widget">...</section>` block, matching every built-in panel's shape. Added for the Visitor & Post View Statistics plugin (LPP-014), but generic — a no-op unless something listens, and any plugin can use it to add a Dashboard panel without a core code change. Unlike `lp_plugin_details_panel`/`lp_theme_details_panel` above, this is the *only* Dashboard extension point — there is no equivalent hook for any other admin screen (see the menu/page note below). |
| `dashboard_widget_ids` | filter | `array<int, string> $ids, User $currentUser` | `admin/views/dashboard.php`, evaluated before `dashboard_widgets` fires. Since LP-134 (reorderable Dashboard widgets), a plugin that wants its `dashboard_widgets` panel to be individually drag-repositionable among the built-in widgets — not permanently pinned last — must **both** register its widget's stable id here (append it to `$ids` and return it, gated by the exact same condition the `dashboard_widgets` listener itself uses, so a widget that won't render this request doesn't reserve a slot in the saved order either) **and** give its own `<section class="lp-admin__widget">` in the `dashboard_widgets` listener the matching `data-lp-sortable-item data-lp-sortable-id="your_id"` attribute plus a `data-lp-drag-handle` element inside its `<h2>` — see `content/plugins/visitor-stats/views/dashboard-widget.php` for the exact markup shape to copy. A plugin that skips this still works exactly as before (its panel just always renders last, un-draggable). If more than one plugin registers an id here at the same time, their `dashboard_widgets` output still all arrives in one combined block (there's no way to fire the action for a single registered id in isolation), so it renders as one contiguous group positioned at whichever of those plugins' ids sorts earliest in the saved order — true independent reordering between two different plugins' widgets isn't supported yet. |

**There is no hook or API for a plugin to add its own admin menu item or
page** (the `dashboard_widgets` hook above only lets a plugin add content
*inside* the existing Dashboard screen, not register a new one). The
shipped plugins work around this three different ways, all variations on
the same `{slug}Active` boolean computed once in `admin/index.php` from
the active-plugins option and shared into the target view via normal PHP
`require`-scope: Dummy Content's admin section is wired directly into an
existing core view (Maintenance › Tools), not registered dynamically;
WordPress Importer (LPP-004) does the same into a different existing,
always-present menu slot (Maintenance › Import —
`admin/views/maintenance/import.php`). Font Awesome (LPP-002) and Emoji
Picker (LPP-006) instead each get a real settings screen of their own by
hand-adding one nested child under an *existing* parent that already
supports children (`Appearance`/`Settings` respectively) — a plain
`...($xActive ? ['slug' => ['label' => ..., 'icon' => ..., 'capability'
=> ...]] : [])` spread into that parent's `children` array in
`admin/index.php`'s `$menu`, resolved to `admin/views/{parent}/{slug}.php`
by the normal `views/{page}/{subpage}.php` routing — Emoji Picker's
`Settings › Writing` entry didn't exist as a parent before this plugin
added it (a single flat child is fine; only add nested grandchildren of
your own once a second sibling actually needs one). Downloads (LPP-008)
and Contact Forms (LPP-003) instead get a real *top-level* menu entry of
their own, since their admin screens are their entire reason to exist —
the same `{slug}Active` gate, just wrapping a whole new top-level `$menu`
array entry instead of a nested child. If your plugin needs admin UI and
no existing menu slot fits, pick whichever of these three shapes matches
how central the admin UI is to the plugin's purpose, rather than assuming
a registration hook exists.

**There is likewise no hook or API for a plugin to register its own
public-facing route.** Every route is hardcoded in `include/bootstrap.php`
and dispatched before any plugin's own request-time code runs again.
This matters specifically for a plugin whose shortcode needs to *process*
a form submission (not just render one): a `content_html`-filter
callback runs too late in the response to safely `header()` redirect
from inside it — classic PHP theme templates in this codebase echo
directly rather than buffering the whole page, so by the time a
shortcode callback executes, earlier template output is often already
flushed. Comment submission avoids this by getting its own dedicated
core-registered route (`$postRoutePattern . '/comment'`); Contact Forms
(LPP-003) follows the identical shape for a plugin, gated the same way
its admin menu entry is: `include/bootstrap.php` registers
`/contact-form/{id}/submit` only `if (in_array('contact-forms',
$activePlugins, true))`, constructing `ContactFormSubmissionHandler`
straight from `$kernel`'s own already-existing components (it exists by
this point in the file). If your plugin needs to process a public POST
with a redirect afterward, follow this pattern rather than assuming a
route-registration hook exists.

### Editor

| Name | Type | Args | Fires in |
|---|---|---|---|
| `registered_editors` | filter | `array $builtIn` (`{value, label}[]`, one per `ContentFormat`) | [`EditorPreferenceService`](../app/Services/EditorPreferenceService.php)`::registeredEditors()` — advertise an additional authoring-UI choice. Content is still always *stored* as one of the three `ContentFormat` cases (Markdown/HTML/Plain); this only affects which editor UI is offered, not a new storage format. |
| `lp_emoji_dataset` | filter | `array $dataset` (`{emoji, name, category, keywords}[]`) | Emoji Picker plugin (LPP-006) — `EmojiPickerService::dataset()`, applied to the bundled curated CLDR subset (`content/plugins/emoji-picker/data/emoji.php`) before it's embedded in the editor container's `data-emoji-dataset` attribute. Extend or replace it entirely — e.g. to load the full CLDR set, add another language's keywords, or add custom entries. |
| `lp_emoji_picker_enabled` / `lp_emoji_picker_editor_enabled` / `lp_emoji_picker_data` | filter | `bool $enabled` / `bool $enabled, string $editor` (`'wysiwyg'`\|`'markdown'`) / `array $default` (`{dataset, defaultCategory, recentLimit}`) | Same plugin — decoupling facades the editor-hosting admin views (`posts/new.php`, `pages/new.php`, `downloads/add-new.php`) call through `lp_emoji_picker_enabled()`/`lp_emoji_picker_editor_enabled()`/`lp_emoji_picker_data()` ([`include/helpers.php`](../include/helpers.php)) instead of a direct `EmojiPickerService` reference, mirroring `lp_fontawesome_enabled`/`lp_fontawesome_css_urls`'s identical reasoning. |
| `lp_emoji_picker_trigger_html` | filter | `string $html, array $args` (`label?`, `target?`) | Same plugin — backs the `lp_emoji_picker_button(array $args = [])` template tag, for rendering a self-contained trigger button outside the default editor toolbars (e.g. a theme's comment form). Returns `''` when the plugin is inactive/disabled, always safe to call unconditionally. |
| `lp_emoji_inserted` | action | `string $emoji, int $userId` | Same plugin — fired from the `emoji_picker_record_recent` AJAX sub-action (core, in each editor-hosting admin view — not the plugin itself, since "recently used" persistence is `UserService::addRecentEmoji()`'s job) whenever an insert is recorded to that user's recently-used list. |

### Icons

| Name | Type | Args | Fires in |
|---|---|---|---|
| `lp_fontawesome_enabled` | filter | `bool $enabled` | Font Awesome plugin — backs the `lp_fontawesome_enabled()` template tag ([`include/helpers.php`](../include/helpers.php)). Falls through to `false` when the plugin is inactive/disabled, so a theme can call it unconditionally with no `function_exists()`/"is this plugin active" guard of its own. |
| `lp_fontawesome_enqueue` | action | none | Font Awesome plugin — backs the `lp_fontawesome_enqueue()` template tag, called from a theme's `header.php` (or anywhere before it) when the theme's own markup uses `lp_icon()`/hand-written `fa-*` classes outside the `[icon]` shortcode, so the plugin's own per-request diagnostics can attribute the load to it. Doesn't itself control whether the stylesheet loads — that's gated purely on the plugin's "enabled" setting. |
| `lp_icon` | filter | `string $html, string $name, array<string, mixed> $args` | Font Awesome plugin — backs the `lp_icon(string $name, array $args = [])` template tag, rendering one icon's `<i>` markup directly from PHP (e.g. `lp_icon('camera', ['label' => 'Camera'])`). `$args` accepts `style`, `size`, `rotate`, `flip`, `animation`, `color`, `class`, `label` — see `FontAwesomeService::icon()`. Returns `''` when no icon plugin is active/enabled, always safe to call unconditionally from theme markup. |
| `lp_register_icon_pack` | action | `string $key, array<string, mixed> $config` | Font Awesome plugin — backs the `lp_register_icon_pack(string $key, array $config)` template tag, for registering an additional icon pack (e.g. a custom SVG set) alongside Font Awesome's own `fa` pack. |

### Application lifecycle & updates

| Name | Type | Args | Fires in |
|---|---|---|---|
| `lumora_press_loaded` | action | none | The very last line of [`include/bootstrap.php`](../include/bootstrap.php), after routes are registered and the CSP header is set — the closest equivalent to WordPress's `init`/`wp_loaded`. The natural place for a plugin's own late setup that needs everything else already in place. |
| `lumora_press_before_update` | action | `string $fromVersion, string $toVersion` | [`UpdateService`](../app/Services/UpdateService.php)`::install()`, right after the maintenance lock is acquired, before backups start. |
| `lumora_press_after_update` | action | `string $fromVersion, string $toVersion, UpdateStatus $status` | Same method, fired on **both** the success path and the failure/rollback path — check `$status` to tell them apart. |

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
of the same name in a different namespace) and Contact Forms'
`ContactFormShortcode` (LPP-003) both follow the identical pattern for
their own `content_html` registration.

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
