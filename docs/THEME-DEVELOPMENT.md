# Theme Development

A reference for building a Lumora Press theme: required files, the template hierarchy, the template-tag API available inside a template, and how to register widget areas, nav menus, and Theme Options fields.

Themes are traditional PHP templates in the classic WordPress style — no block editor, no `theme.json`, no Full Site Editing. If you can write `header.php`/`footer.php`/`single.php` for classic WordPress, you already know the shape of a Lumora Press theme.

**Keep this document current.** Whenever a template-tag function, the template hierarchy, a widget/menu/Theme-Options registration pattern, or the dark-mode convention changes, update this file in the same change — see `CLAUDE.md`'s "After Every Code Change" rule. This is reference material, not a changelog: it should describe the API as it exists right now, not a history of how it got there.

For the hook/filter system, plugin structure, and service-layer APIs, see [`DEVELOPER-APIS.md`](DEVELOPER-APIS.md) instead — this document is scoped to what a *theme* author needs.

## Getting started

A theme lives in its own directory under `content/themes/{slug}/`. The only required file is `style.css`, with a header comment block Lumora Press reads to identify the theme:

```css
/*
Theme Name: My Theme
Description: A short description of the theme.
Version: 1.0.0
Author: Your Name
Author URI: https://example.com
Theme URI: https://example.com/my-theme
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Tags: blog, minimal
Requires at least: 0.6.0
Requires PHP: 8.2
*/
```

`Version:` also drives updates for a theme bundled with Lumora Press: a release can carry a newer package, and Appearance &rsaquo; Themes offers it only when its version is higher than the installed `style.css`'s. `Requires at least:` and `Requires PHP:` are enforced as blocking checks at that point, so an update that needs a newer Lumora Press (or PHP) than the site runs is refused. Themes you install yourself are never offered updates this way, but both fields still show in the theme's Details panel, and they are checked everywhere else too: a ZIP whose theme needs a newer Lumora Press or PHP than the site runs, or that lacks any of the required templates listed below, is refused before anything is extracted (install and update alike); on Appearance &rsaquo; Themes a theme in that state shows a "Not compatible" badge with the reasons, and its Preview and Activate buttons are disabled (the server refuses both as well). A value that doesn't look like a version number ("soon") is ignored rather than treated as a failure. Only the theme's folder is replaced; Theme Options live in the database and survive.

If the active theme's folder or its `style.css` is missing (deleted by hand, for example), the site falls back to the bundled Lumora Classic theme rather than failing, until another theme is activated.

Every field is optional (a theme with no `Theme Name:` still shows up, titled from its directory slug) and only the first 8192 bytes of `style.css` are read for the header, mirroring classic WordPress's own `style.css` convention. Add a `preview.jpg`/`screenshot.jpg` (or numbered `screenshot-2.png`, etc.) in the theme directory for the Appearance › Themes admin screen's thumbnail/gallery.

Beyond `style.css`, the templates below are what `SiteController` and `ThemeRenderer` actually request. The bundled Lumora Classic theme (`content/themes/lumora-classic/`) is a complete, working reference implementation — copy it as a starting point rather than building from this document alone.

## Directory structure & template files

```
content/themes/{slug}/
├── style.css        (required — theme header + all styling, see the
│                      Public-Facing CSS Rule in CLAUDE.md: every visual
│                      component's styling belongs here, not inline)
├── functions.php     (optional — theme setup: widget areas, nav menu
│                      locations, Theme Options registration)
├── header.php        (required)
├── footer.php        (required)
├── sidebar.php        (optional — no-ops gracefully if absent)
├── index.php          (required — homepage / blog listing)
├── single.php         (required — one post)
├── page.php            (required — one static page)
├── archive.php         (required — category/tag/author/date archives,
│                        all reuse this one file)
├── search.php          (required — search results)
├── comments.php        (optional — no-ops gracefully if absent)
├── 404.php             (required)
├── maintenance.php     (required — shown while Maintenance Mode is on)
└── preview.jpg          (optional — admin screenshot)
```

`index.php` through `maintenance.php` above are genuinely required: `ThemeRenderer::render()` throws if the requested file is missing. The four partials (`sidebar.php`/`comments.php`, plus `header.php`/ `footer.php` which are *conventionally* always present) are located via `renderPartial()`, which no-ops silently if the file doesn't exist — useful if your theme has no sidebar at all.

## Template hierarchy

Lumora Press does **not** implement a deep cascading template hierarchy the way classic WordPress does (no `single-{post-type}-{slug}.php` fallback chain, no per-category/per-slug overrides). `SiteController` hardcodes which literal filename to render for each route, with one narrow exception:

| Route | Template |
|---|---|
| Homepage / static "Posts page" | `index.php` |
| Single post (and post preview) | `single.php` |
| Static page | `page.php` |
| Tag archive | `tag.php`, falling back to `archive.php` if the theme doesn't provide one |
| Author, category, and date archives | `archive.php` — distinguished only by an `$archive_type` variable: `'author'`/`'category'`/`'date'` (`'tag'` also still applies when a theme has no `tag.php`) |
| Search results | `search.php` |
| Unmatched route / explicit 404 | `404.php` |
| Maintenance Mode | `maintenance.php` |

Partials (`header.php`, `footer.php`, `sidebar.php`, `comments.php`) are requested by name from inside the templates above via `get_header()`/ `get_footer()`/`get_sidebar()`/`comments_template()` — see the Template Tags reference below. `functions.php` is loaded once per request, separately from rendering, before any template or Theme Options registration happens.

`tag.php` is entirely optional — a theme with no `tag.php` file behaves exactly as before, tag archives rendering through `archive.php` like every other archive type. Providing `tag.php` gets the identical set of variables `archive.php` receives for a tag archive (`$archive_type` is still `'tag'`), it's simply a separate file a theme can style differently from its other archives. This is the only template in the table above with a fallback; there is no equivalent override for categories, authors, or dates, and no way to add a per-slug or per-category template beyond what's listed (a `single-my-slug.php` file, for example, is simply never looked for).

`maintenance.php` receives `$page_title`, `$message` (already rendered, sanitized HTML, since the administrator writes it in the Visual, Markdown, or HTML editor, so print it as-is inside a `<div class="lp-maintenance__message">` rather than escaping it or wrapping it in a `<p>`), `$has_background_image` (true when the administrator chose a background image; add a class such as `lp-maintenance--has-background` to the container so the theme can give the message a semi-transparent panel that keeps the text readable), `$return_at` (the scheduled end as a `DateTimeImmutable`, or `null`), and `$countdown_to` (the same moment, but only when the administrator turned the live countdown on and the end is still ahead, otherwise `null`). To show the countdown, print an empty element with a `data-lp-countdown-to` attribute holding `$countdown_to->format(DATE_ATOM)` and the `hidden` attribute, then style it with `.lp-maintenance__countdown`; core loads the script that fills it in, and a visitor without JavaScript just sees the static return time. Wrap the site header, navigation, and welcome message in `header.php` in `if (!is_maintenance_page())` so the maintenance page shows just its message. Fire `do_action('maintenance_page_content')` inside the page's container so plugins can add their own status information. The administrator's chosen background image, colors, and custom CSS are printed into `<head>` by core through the usual `head_assets` hook, so the template needs no markup for them; style the page with the `.lp-maintenance`, `.lp-maintenance__message`, `.lp-maintenance__return-at`, and `.lp-maintenance__countdown` classes.

## The "loop"

There is no global `$post`/`have_posts()`/`the_post()` state to manage. `SiteController` hands each template exactly the data it needs as plain local variables:

- `index.php` and `archive.php` receive `$posts` (a plain array of `Post` objects) — iterate it with an ordinary `foreach`:

  ```php
  <?php foreach ($posts as $post): ?>
      <h2><a href="<?= esc_url(post_permalink($post)) ?>"><?= esc_html($post->title) ?></a></h2>
      <?= get_the_excerpt($post) ?>
  <?php endforeach; ?>
  ```

- `single.php` and `page.php` receive a single `$post`/`$page` variable directly — no loop needed.
- `search.php` receives `$results` (an array of `SearchResult` objects), iterated the same way, plus `$search`: the whole search as one bundle (query, criteria, pagination, filter choices, and empty-state suggestions). Don't pick it apart — hand it straight to `search_filters_form($search)`, `search_did_you_mean($search)`, and `search_empty_state($search)`, so a filter added in a later version reaches your theme without a template change.
- `comments.php` receives `$comment_tree` (a nested array), rendered via the `comment_list()` template tag rather than hand-written iteration.

## Template Tags

Every function below is declared with a `function_exists()` guard, so a theme (or plugin) may safely redefine one if it truly needs to — though that's rarely necessary. Functions that echo output are prefixed `the_` (matching WordPress convention); functions that return a value for you to handle yourself are prefixed `get_` or named directly after what they return.

### Post & content

| Function | Purpose |
|---|---|
| `get_the_content(Post $post): string` | Full rendered content, More-tag marker stripped. Always the complete post. |
| `the_content(Post $post): void` | Echoes `get_the_content()`. Already-safe HTML — don't `esc_html()` it. |
| `render_content(string $content, ContentFormat $format): string` | Renders *raw* stored content (e.g. `$post->content`, `$post->contentFormat`) to safe HTML. Use this on a singular template when you need full control over the wrapping markup; `the_content()` is the simpler choice for the common case. |
| `get_the_excerpt(Post $post, ?int $wordLimit = null): string` | Preview text: manual Excerpt field, else content up to a More tag, else an auto-generated excerpt trimmed to `$wordLimit` (defaults to the "Excerpt length" Theme Option). Unescaped plain text. |
| `the_excerpt(Post $post): void` | Echoes `esc_html(get_the_excerpt($post))`. |
| `post_has_more_tag(Post $post): bool` | Whether the post's content contains a More-tag marker. |
| `get_the_content_up_to_more_tag(Post $post): ?string` | Rendered content up to (not including) the More tag; `null` if there isn't one. A post's own More tag always takes priority over the "Post Display" Theme Option on listing pages. |
| `content_plain_text(string $content, ContentFormat $format): string` | Plain-text rendering of raw content (for meta descriptions, OG tags). Handles Markdown correctly, not just `strip_tags()`. |
| `content_has_more_tag(string $content): bool` / `content_split_at_more_tag(string $content): array` | Lower-level helpers `render_content()`/`get_the_content_up_to_more_tag()` are built on. |
| `make_excerpt(string $content, int $wordCount = 55): string` | Strips tags and truncates plain/HTML content to `$wordCount` words, appending `…`. |
| `highlight_terms(string $escapedText, string $query): string` | Wraps matches of each query word (≥2 chars) and each `"quoted phrase"` in `<mark class="lp-search-highlight">`; `-excluded` words are never marked. Operates on **already-escaped** text — see `search.php`. |
| `search_filters_form(array $search): void` | Echoes the "Refine search" panel for `search.php`: a `<details class="lp-search-filters">` holding the query, content type, category, tag, author, date range, and sort fields, open while any is in use, with a "Clear filters" link. Only offers choices that can match (e.g. no Author select on a single-author site). Classes: `.lp-search-filters__toggle`, `__form`, `__grid`, `__field` (`--query`, `--date`), `__label`, `__input`, `__select`, `__hint`, `__actions`, `__button`, `__reset`. Plugins can add fields inside it. |
| `search_did_you_mean(array $search): void` | Echoes `<p class="lp-search-did-you-mean">Did you mean <a class="lp-search-did-you-mean__link">…</a>?</p>` when the search found little and a better spelling finds more (keeping the visitor's filters); nothing otherwise. Call it right after `search_filters_form()`, outside the results/empty-state branch. |
| `search_empty_state(array $search): void` | Echoes what to show when `$results` is empty: `.lp-empty-state` ("No results found."), a `.lp-search-empty__tips` list (with a "Search again without filters" link when filters were used), then "Recent posts" and "Popular categories" `.lp-search-empty__section` lists. |
| `search_result_type_label(SearchResult $result): string` | "Post", "Page", "Category", "Tag", "Author", or a plugin's own label for a content type it made searchable. Use it instead of a hard-coded `match` on `$result->type`. |

**Search page and search box behavior.** The core search script (loaded on `footer_assets`) needs nothing from a theme beyond the markup above, but relies on two conventions: `search.php`'s content sits in `<main class="lp-main">` (the search page's results are swapped in place inside it; a theme without it simply reloads the page instead), and every search box is a `<form role="search">` with an `<input name="q">`. When live search or recent/popular searches are switched on, it adds a panel to `<body>` for style: `.lp-search-live` (the panel), `__list`, `__heading` and `__clear` (group titles and the Clear button), `__option` (with `.is-active` for the keyboard-highlighted entry, and `--result`, `--query`, `--all` variants), `__thumb`, `__text`, `__title`, `__meta`, and `__empty`. While in-place results load, `main.lp-main` carries `aria-busy="true"`. Popular searches on the empty search page use `.lp-search-empty__list--searches`. After swapping in new results the script dispatches a bubbling `lp:content-updated` event on `main.lp-main`; the lightbox listens for it, and a theme script that enhances result markup should too.
| `render_pagination(array $pagination, string $label = 'Posts pagination', string $pageParam = 'paged'): void` | Echoes a pagination `<nav>` for `array{page, totalPages}` (page links swap only the `$pageParam` query parameter, so a second paged list on a page can use its own): First/Prev/Next/Last controls plus numbered pages truncated with `…` ellipses around the current page and the range's edges; no-op if there's only one page. Styling classes: `.lp-pagination__item--nav` (First/Prev/Next/Last, `.is-disabled` at either end of the range) and `.lp-pagination__item--ellipsis`. |

### Author

| Function | Purpose |
|---|---|
| `author_name(Post $post): string` | Author's display name (`'Unknown'` if the account was deleted). |
| `author_url(Post $post): ?string` | Author's public archive URL, or `null` if the account no longer exists. |
| `the_author_link(Post $post): void` | Echoes the author's name linked to their archive, falling back to plain text. |

### Pages, Categories / breadcrumbs

| Function | Purpose |
|---|---|
| `get_page_breadcrumbs(array $ancestors): array` | Maps a root-first `Page[]` ancestor chain (not including the current page) to `{title, url}` pairs. |
| `the_page_breadcrumbs(Page $page, array $ancestors): void` | Echoes a `<nav>`/`<ol>` breadcrumb trail. Outputs nothing for a top-level page. |
| `get_category_breadcrumbs(array $ancestors): array` | Same shape as `get_page_breadcrumbs()`, for a root-first `Category[]` ancestor chain. |
| `the_category_breadcrumbs(Category $category, array $ancestors): void` | Same shape as `the_page_breadcrumbs()`, reusing the identical `.lp-breadcrumbs` markup/CSS classes. Outputs nothing for a top-level category. `archive.php` calls this when `$archive_category` is set (a category archive only — tag/date/author archives have no hierarchy). |

### URLs & permalinks

| Function | Purpose |
|---|---|
| `post_permalink(Post $post): string` | A post's public URL — the single choke point that honors the site's configured permalink structure (see Settings › Permalinks). Always use this, never hand-build `'post/' . $post->slug`. |
| `page_permalink(Page $page): string` | A page's URL, reflecting its position in the parent/child hierarchy (e.g. `/about/team`) — pages have no *configurable* structure (unlike posts), but their URL is never flat. Always use this, never hand-build `'page/' . $page->slug` — that old flat form still resolves (a permanent redirect to the real URL), but only for back-compat with pre-existing links. |
| `category_permalink(Category $category): string` / `tag_permalink(Tag $tag): string` | Category/tag archive URLs, honoring the configured base prefix. |
| `search_result_permalink(SearchResult $result): string` | Dispatches by the result's type to the right permalink builder above, or returns the result's own `url` when a plugin's search provider set one. |
| `privacy_policy_url(): ?string` | The URL of the Page named on Settings › Privacy, or `null` if none is set or the configured page no longer exists/isn't publicly visible. Whether and where to link it is up to the theme — Lumora Classic's `footer.php` calls this and renders a "Privacy Policy" link (`.lp-site-footer__privacy-link`) only when it returns non-`null`, but nothing forces a theme to do the same. |
| `post_categories(Post $post): array` | The Categories `$post` belongs to (alphabetical). Empty array for an uncategorized post, never `null`. |
| `the_post_categories(Post $post, string $separator = ', ')` | `post_categories()`, echoed as a `$separator`-joined list of links. Outputs nothing at all for an uncategorized post, so it's safe to call unconditionally. |
| `has_category_image(Category $category): bool` | Whether `$category` has an image assigned (Categories admin's "Category Image" field). |
| `category_image_url(Category $category, string $size = 'medium'): ?string` | The image's URL at `$size`, falling back to the original if no thumbnail of that size was generated, or `null` if `$category` has no image. No manual-crop support, unlike a post/page featured image. |
| `category_archive_display_mode(Category $category): string` | The effective `'excerpt'`/`'full'` display mode for `$category`'s own archive page — its "Archive display" field if the admin set one, otherwise the site-wide "Post display" Theme Option. `archive.php` calls this instead of `theme_option('post_display_mode')` directly whenever `$archive_category` is set. |
| `get_the_tags(Post\|int $post): array` | The Tags `$post` is assigned to (alphabetical). Empty array for a tagless post, never `null`. Accepts a bare post id as well as a `Post` object — useful on `search.php`, whose result cards are `SearchResult`s rather than `Post`s (only ever call it for a `'post'`-type result, since that's the only type with tags). |
| `post_has_tags(Post\|int $post): bool` | Whether `$post` has any tags at all. |
| `the_tags(Post\|int $post, string $before = '', string $sep = ', ', string $after = '')` | `get_the_tags()`, echoed as a `$sep`-joined list of links wrapped in `$before`/`$after`. Outputs nothing at all for a tagless post, so it's safe to call unconditionally. |
| `get_related_posts(Post $post, int $limit = 5): array` | Up to `$limit` other publicly visible posts sharing at least one tag with `$post`, most-shared-tags-first then most recent. Empty array for a tagless post. |
| `the_related_posts(Post $post, int $limit = 5, string $title = 'Related Posts')` | `get_related_posts()`, echoed as a titled `.lp-related-posts` list of linked titles (with a thumbnail when one exists and Theme Options' "Show featured image in listings" is on). Outputs nothing at all when there are no related posts, so it's safe to call unconditionally. |
| `edit_post_link(Post $post): ?string` / `edit_page_link(Page $page): ?string` | The admin edit-screen URL for `$post`/`$page`, or `null` when nobody is signed in or the signed-in user isn't allowed to edit that specific item — the same permission check the admin list screens themselves use (an Administrator/Editor, or the item's own author). The capability check happens in core; a theme only ever asks whether it got a URL back. |
| `site_url(string $path = ''): string` | Root-relative URL under the site's base path. Use for any internal link. |
| `home_url(string $path = ''): string` | Absolute URL (scheme + host + base path) — needed for RSS, outbound email, and canonical/OG tags, where a relative URL won't do. |
| `admin_url(string $page = ''): string` | URL into `/admin`. |
| `theme_url(string $path = ''): string` | URL to a file inside the *active* theme's own directory, cache-busted by file mtime — e.g. `theme_url('style.css')`. Moves to the CDN address when the administrator sets one in Settings › Performance, so always build asset URLs with this rather than concatenating them. |
| `core_asset_url(string $path = ''): string` | URL to a static file under the top-level `assets/` directory (framework-owned JS/CSS a theme may depend on). Follows the CDN address the same way as `theme_url()`. |
| `registration_enabled(): bool` | Whether visitors can sign up (Settings › Registration, off by default). Check it before showing a "Sign up" link, so the theme never advertises a form that answers "not found". |
| `register_url(): string` | The address of the sign-up form. The core Meta widget already adds a "Sign up" link next to "Log in" while registration is on. |
| `the_font_stylesheets(?array $urls = null): void` | Prints the `<link>` tags for Google Fonts stylesheets, in `header.php` in place of hand-written `preconnect`/`stylesheet` tags. It serves the locally hosted copy when the administrator has downloaded one (adding a `preload` for the main fonts and no Google `preconnect`), and applies the font-display choice from Settings › Performance. With no argument it prints the addresses collected by the `font_stylesheet_urls` filter; pass an array of `https://fonts.googleapis.com/css2?...` URLs to name them directly. Anything that isn't a Google Fonts stylesheet address is skipped. |
| `font_stylesheet_urls(): array` | The addresses the `font_stylesheet_urls` filter returns. A theme hooks that filter in `functions.php` to name the fonts it loads, which also lets Settings › Performance offer them for local hosting. |
| `enqueue_script(string $handle, string $src, array $options = []): void` | Queues an external script. It prints on `footer_assets` (or `head_assets` with `'in_footer' => false`) after core's own scripts. Options: `deps` (handles that print first), `in_footer`, `defer`, `async`, `module` (each defaulting to the Settings › Performance choice, except `async`/`module`, which default to `false`), and `attributes` (extra attributes such as `crossorigin`). A queued handle is only printed once, a head script that depends on a footer script moves to the footer, and a module or async script never gets `defer`. Only external files are supported because the Content-Security-Policy blocks inline scripts. Requires the theme to fire `do_action('head_assets')` and `do_action('footer_assets')`. |
| `canonical_url(): string` | The current request's self-referencing canonical URL, keeping only `?paged=`/`?q=` and dropping every other query param. |

### Media & images

All of these accept `Post|Page|SearchResult $item` — anything with a `featuredImageId`.

| Function | Purpose |
|---|---|
| `has_post_thumbnail($item): bool` | Whether a featured image resolves for `$item` (including the site-wide default featured image fallback; a Page gets no fallback when an administrator turned off "Use the default featured image on pages"). |
| `the_post_thumbnail($item, string $size = 'medium', array $attrs = []): void` | Echoes an `<img>` with a real `srcset`/`sizes`, or a fixed-size `<img>` if a manual crop applies. No-op if there's no image. |
| `the_post_thumbnail_lightbox($item, string $size = 'medium', ?string $largeSize = null, array $attrs = []): void` | Same as above, wrapped in a PhotoSwipe-lightbox `<a>`. **Prefer this over `the_post_thumbnail()`** on any listing/single template — it's what marks the page as needing the lightbox JS/CSS (via `footer_assets`), so using the plain version means clicking the image does nothing. |
| `post_thumbnail_url($item, string $size = 'medium', bool $absolute = false): ?string` | Just the URL, for OG tags/RSS/manual `<img>` construction. |
| `post_thumbnail_caption($item): ?string` | The image's caption, or `null`. |
| `the_post_thumbnail_caption(Post\|Page $item): void` | Echoes the featured image's caption as `<p class="lp-post__thumbnail-caption">`, or nothing when it has none. Call it right after `the_post_thumbnail_lightbox()` in `single.php`/`page.php`. |
| `post_thumbnail_media($item): ?array` | The raw media row (advanced use — `file_size`, `mime_type`, etc.). |

**Images inside post content.** Both editors insert images with `alignleft`/`aligncenter`/`alignright` and `size-{name}` classes, and a captioned image as `<figure class="lp-caption {alignment}"><img …><figcaption>…</figcaption></figure>` (alignment on the `<figure>`, not the `<img>`, so image and caption move together; the WordPress Importer produces the same markup). Style `.lp-post__content img.alignleft` and friends, plus `.lp-post__content figure.lp-caption` and its `figcaption`; the bundled theme's `style.css` has a complete set to copy.

### Comments

| Function | Purpose |
|---|---|
| `comment_form(Post\|Page $content, ?User $currentUser, array $guestFieldOptions = [], ?int $parentId = null, string $submitLabel = 'Post Comment'): void` | Renders one CSRF-protected form. Pass `$parentId` for a reply form. Fields added through the `comment_form_fields_after` filter (such as core's "Notify me of new comments" checkbox, `.lp-comment-form__subscribe`) appear just before the submit button with no theme changes needed. |
| `comment_list(array $tree, Post\|Page $content, ?User $currentUser, array $guestFieldOptions = [], int $depth = 0, int $maxDepth = 5, bool $avatarsEnabled = true, string $avatarRating = 'g', string $avatarDefault = 'mp', int $collapseThreshold = 10, ?bool $commentsOpen = null): void` | Renders the full nested comment thread, each reply's form embedded via `comment_form()` (left out when `$commentsOpen` is `false`; pass the template's `$comments_open`, which also covers the site-wide switch and auto-close — `null` falls back to the post or page's own comments-open flag). A sub-thread with more than `$collapseThreshold` total replies renders inside a collapsed-by-default `<details class="lp-comment__thread">` instead of always-expanded. Each comment also gets, with no theme changes needed: a role badge in its meta line (`.lp-comment__role`, with `--post-author` or `--{role}` modifiers, and `.lp-comment--by-post-author` on the `<li>`), an "(edited)" marker (`.lp-comment__edited`), and an actions row (`.lp-comment__actions`) holding the reaction buttons (`.lp-comment-reactions`, `.lp-comment-reactions__button`/`__item`/`__emoji`/`__label`/`__count`, `.is-selected` for the visitor's own), a Quote button (`.lp-comment__quote-button`, revealed by script), and a Report control (`<details class="lp-comment-report">`, `.lp-comment-report__form`, `.lp-comment-report__thanks`). Reactions and reporting only appear when switched on in Settings › Discussion. Core loads the small script behind them through `footer_assets`. Plugins may add markup inside `.lp-comment__content` through the `comment_content_html` filter, so style that element's children generically. |
| `comments_template(array $vars = []): void` | Fires the `comments_template` action (passing `$vars` along), then renders the theme's own `comments.php` (no-op if it doesn't exist). Call this from `single.php`/`page.php` rather than writing comment markup by hand. When comment subscriptions are on, core uses that action to print the subscription panel (confirm/unsubscribe notices and the "Watch this thread" button) just before your `comments.php` — style it with `.lp-comment-subscription`, `.lp-comment-subscription__manage`, `.lp-comment-subscription__watch`, `.lp-comment-subscription__text`, and `.lp-comment-subscription__actions`; it reuses the shared `.lp-alert`/`.lp-button` classes for notices and buttons. Its buttons, like the comment Report form's, are `<button class="lp-button lp-button--secondary">` elements, so a theme's `.lp-button` rule should reset the native `border` and `cursor`, and the theme should style `.lp-button--secondary` too. A plugin can also replace the whole comment section through the `comments_template_html` filter (see `docs/DEVELOPER-APIS.md`), in which case neither the panel nor your `comments.php` renders. |
| `comment_avatar_url(string $email, string $rating = 'g', string $default = 'mp', int $size = 48): string` | A Gravatar URL — used internally by `comment_list()`, exposed if you need it directly. |
| `format_comment_content(string $raw): string` | Escapes raw comment text, auto-links bare URLs, wraps @mentions in `.lp-comment__mention`, and turns runs of lines starting with `>` into `<blockquote class="lp-comment__quote">` (text around a quote is then wrapped in `<p>`; a comment with no quote keeps plain `<br>` line breaks). |
| `comment_mentions(string $raw): array` | The lower-cased @handles in raw comment text. Handles are users' public author slugs, the same ones their author archive URL uses. |
| `comments_link(Post $post, int $commentCount): void` | The classic "X Comments" link for a post-listing card, linking to `post_permalink($post) . '#comments'`. Renders "No Comments"/"1 Comment"/"N Comments", or plain "Comments Closed" text with no link when the post has comments closed and zero comments. `index.php`/`archive.php` receive a `$comment_counts` array (`[postId => count]`, already batch-fetched — see missing ids as 0) to pass in as `$commentCount`. |

### Theme layout (`header.php`/`footer.php`/`sidebar.php`)

| Function | Purpose |
|---|---|
| `get_header(array $vars = []): void` | Fires the `get_header` action, then renders `header.php`, extracting `$vars` as local variables inside it (e.g. `get_header(['post' => $post])` from `single.php`). |
| `get_footer(): void` | Fires `get_footer`, then renders `footer.php`. |
| `get_sidebar(): void` | Fires `get_sidebar`, then renders `sidebar.php` (silent no-op if the theme has none, or if the administrator chose "No sidebar" under Sidebar position). |
| `sidebar_position(): string` | `'right'` (default), `'left'` or `'none'`, from the Sidebar position Theme Option. |
| `body_class(string $classes = ''): string` | `$classes` plus the layout classes core controls: `lp-sidebar-left` or `lp-sidebar-none` (nothing for the default right), `lp-footer-columns-1` to `-4` (nothing for Automatic), and `lp-custom-image-radius`, `lp-custom-image-shadow`, `lp-custom-gallery-gap` and `lp-custom-heading-font` once an administrator has chosen a value under Images or Typography (core also emits `--lp-image-radius`, `--lp-image-shadow`, `--lp-gallery-gap` and `--lp-font-heading` then). Add one override rule per class, e.g. `body.lp-custom-image-radius :is(.lp-post__content img, ...) { border-radius: var(--lp-image-radius) !important; }`; the `!important` is deliberate because the administrator's choice is meant to beat the theme's own per-component rules. Unescaped; use it as `<body class="<?= esc_attr(body_class('lp-site')) ?>">`. A theme that skips it ignores the Sidebar position option. Style them in your stylesheet: for left, mirror the layout grid; for none, make `.lp-layout` one column; for footer columns, make `.lp-site-footer__widgets` a grid of that many columns (one column on small screens). Wrap the footer widget area in `is_active_sidebar('footer')` so it stays optional. |

Your `header.php` and `footer.php` are also where you fire the two theme-convention hooks other code (and plugins) expect: `do_action('head_assets')` right before `</head>` and `do_action('footer_assets')` right before `</body>`. Core itself listens on `footer_assets` (to emit lightbox/embed script tags when a template used `the_post_thumbnail_lightbox()` or an auto-embedded URL, and the site search script, which attaches to any `<form role="search">` with an `<input name="q">`), so skipping these hooks will silently break those features. See [`DEVELOPER-APIS.md`](DEVELOPER-APIS.md) for the full hook reference.

### Site info & meta

| Function | Purpose |
|---|---|
| `site_name(): string` | Site display name (Settings › General). |
| `site_tagline(): string` | Site tagline/description. |
| `site_logo_url(): ?string` / `favicon_url(): ?string` | Configured logo/favicon URL, or `null`. |
| `meta_description(): string` | Site-wide fallback meta description. |
| `default_og_image_url(): ?string` | Site-wide fallback Open Graph/Twitter Card image. |
| `powered_by_html(): string` | Fixed "Powered by Lumora Press" attribution HTML, linked to the project homepage — not site-configurable. Returns raw HTML, safe to echo directly. |
| `is_maintenance_page(): bool` | True only while the maintenance page itself is being rendered (a blocked visitor, or an administrator's preview). Use it in `header.php` to leave out the site header on that page. |
| `is_maintenance_mode(): bool` | Whether maintenance mode is in effect (switched on by hand or by its schedule). True even for a visitor who bypasses it, so a theme can show signed-in staff a reminder. |
| `search_engines_discouraged(): bool` | Whether Settings › Reading has asked search engines not to index the site — print `<meta name="robots" content="noindex,nofollow">` when true. |
| `the_date(\DateTimeInterface $date): string` / `the_time(\DateTimeInterface $date): string` | Formats a date/time using the admin-configured format (Settings › General). Always use these instead of calling `->format()` directly, so admin-configured date/time formats are respected everywhere. |

### Escaping

| Function | Purpose |
|---|---|
| `esc_html(string $value): string` | For HTML text nodes. |
| `esc_attr(string $value): string` | For HTML attribute values. |
| `esc_url(string $url): string` | For URL attribute values. |

Escape at output time, right where you echo — never store pre-escaped data. Content returned by `the_content()`/`render_content()`/ `get_the_excerpt()` is already safe HTML/plain-text by the time it reaches you; everything else (titles, slugs, user-entered fields) needs explicit escaping.

### Internationalization

| Function | Purpose |
|---|---|
| `__(string $text, string $domain = 'lumora-press'): string` | Runs `$text` through the `gettext` filter (falls back to the original string — there's no built-in translation loader yet, this is the extension point for one). |
| `_e(string $text, string $domain = 'lumora-press'): void` | Echoes `esc_html(__($text, $domain))`. |

## Widget areas

Register in `functions.php`:

```php
register_sidebar('primary', 'Primary Sidebar', 'Appears alongside posts and pages.');
```

Render in a template:

```php
<?php if (is_active_sidebar('primary')): ?>
    <aside class="lp-sidebar">
        <?php dynamic_sidebar('primary'); ?>
    </aside>
<?php endif; ?>
```

`is_active_sidebar()` is a guard, not required — `dynamic_sidebar()` itself no-ops if the area has no widgets, but wrapping the outer markup in the guard avoids an empty `<aside>` shell.

**Homepage widget area.** Register a `homepage` area and call `the_homepage_widgets($page_title, $pagination ?? [])` in `index.php`, just above the post list:

```php
register_sidebar('homepage', 'Homepage Widget Area', 'Appears above the post list on the first page of the homepage only.');
```

```php
<?php the_homepage_widgets($page_title, $pagination ?? []); ?>
```

It prints `<section class="lp-homepage-widgets" aria-label="Homepage widgets">` only on the genuine homepage (a null `$page_title`, so not the Posts page or an archive) and only on page 1, and nothing at all when the area is empty or was never registered. Style `.lp-homepage-widgets` in your stylesheet (Lumora Classic uses an auto-fit grid of widget cards).

## Navigation menus

Register in `functions.php`:

```php
register_nav_menu('primary', 'Primary Menu');
```

Render in a template:

```php
<?php nav_menu('primary'); ?>
```

`nav_menu()` renders the assigned menu as a nested `<ul class="lp-nav-menu">` and self-guards (no-op if nothing is assigned to that location) — no `has_nav_menu()` check is required around it, though `has_nav_menu(string $location): bool` is available if you need a conditional wrapper around surrounding markup (a `<nav>` tag, say).

Menu items an administrator marked "Hide on the site" on Appearance › Menus are left out of `nav_menu()`'s output along with everything nested under them, and `has_nav_menu()` returns `false` when every item in the location is hidden. Each item's `<li>` carries `lp-nav-menu__item`, any CSS classes set on the item, and `lp-nav-menu__item--has-children` when it has a submenu (`<ul class="lp-nav-menu__submenu">`). To change the items, classes, or finished markup from `functions.php`, use the `nav_menu_tree`, `nav_menu_item_classes`, and `nav_menu_html` filters (see "Navigation menus" in `docs/DEVELOPER-APIS.md`).

## Theme Options

A theme (or plugin) registers its own Theme Options fields by hooking `register_theme_options` from `functions.php` — this hook fires *after* your theme's `functions.php` has already loaded and *after* core's own built-in fields (Colors, Typography, Layout, Post Display, Featured Image, Header, Welcome Message, Footer) are already registered:

```php
add_action('register_theme_options', function (\LumoraPress\Core\Theme\ThemeOptions $options): void {
    $options->registerSection('my_theme', 'My Theme', 'Options specific to this theme.');

    $options->registerField(new \LumoraPress\Core\Theme\ThemeOptionField(
        key: 'my_theme_hero_text',
        section: 'my_theme',
        type: \LumoraPress\Core\Theme\ThemeOptionType::Text,
        label: 'Homepage hero text',
        default: 'Welcome!',
    ));
});
```

Read the value in a template with `theme_option('my_theme_hero_text')` — this always returns a string, falling back to the field's own `default` if unset, and never throws for an unknown key.

Values are scoped per active theme (LP-123): each theme's saved values are independent — switching the active theme via Appearance > Themes switches which set of values is shown/editable and rendered on the front end. A custom section your theme registers (like `my_theme` above) only ever shows up on the admin's Appearance > Customize screen while your theme is active, since that screen only ever lists the current `ThemeOptions` instance's registered sections.

`ThemeOptionType` cases: `Text`, `Textarea`, `Number`, `Checkbox`, `Select`, `Color`, `Url`, `Html` (LP-123 — rich Markdown/HTML/Plain content, reusing the same editor as post/page content; see below).

`ThemeOptionField` constructor parameters: `key`, `section`, `type`, `label`, `default = ''`, `cssVariable = null`, `help = ''`, `choices = []` (Select: value ⇒ label), `min = null`/`max = null` (Number), `cssUnit = ''` (Number, e.g. `'px'`), `cssValueMap = []` (Select: stored value ⇒ actual CSS value), `allowEmpty = false`, `previewDefault = null` (Color: swatch shown when the value is empty), `allowedHosts = []` (Url: restrict the submitted URL's host).

### The `Html` field type

An `Html`-type field stores raw, unsanitized Markdown/HTML/Plain content (sanitized only at render time, the same posture as post/page `content` and `custom_css()` — never double-sanitized at storage time), rendered through `render_content()`. It's always paired with a companion `Select`-type field carrying the `\LumoraPress\Models\ContentFormat` choice (`'html'`/`'markdown'`/`'plain'`), by convention named `{key}_format` — see `welcome_message`/`welcome_message_format` and `footer_html`/`footer_html_format` in `ThemeOptions::registerStandardOptions()` for the exact pattern to follow for your own rich-content field.

### Declaring which Customize options your theme implements

Some Theme Options only do something when the theme cooperates (a body class, a template check, a CSS rule). Declare the ones your theme implements from `functions.php`; Customize hides any it hasn't declared, with a note, rather than offering a setting that does nothing:

```php
add_theme_support(
    'sidebar-position',  // body_class() + .lp-sidebar-left / .lp-sidebar-none rules
    'footer-columns',    // body_class() + .lp-footer-columns-N rules
    'image-styling',     // body_class() + the .lp-custom-image-* / gallery-gap rules
    'back-to-top',       // .lp-back-to-top styling
    'post-details',      // show_post_meta() around the byline parts, the_reading_time()
    'tagline',           // show_site_tagline()
    'search-bar',        // show_search_bar()
    'page-titles',       // show_page_titles()
    'heading-font',      // body_class() + the .lp-custom-heading-font rule using --lp-font-heading
    'dark-mode',         // a dark appearance via the usual tokens, switched by [data-theme="dark"] or prefers-color-scheme
);
current_theme_supports(string $feature): bool
```

A theme that declares nothing still gets every option that needs no cooperation (colors, typography, content width, featured image position, and so on). A plugin that registers a Theme Option can name the feature it needs with `ThemeOptionField`'s `requiresSupport` argument.

### Appearance > Customize screen sections

The admin Appearance > Customize screen (LP-123, replacing the old flat Theme Options page) groups sections into six tabs: **Header**, **Welcome Message**, **Body**, **Menu**, **Widgets**, **Footer**, plus a theme tab after them when the theme registers sections of its own. Menu and Widgets are link-outs to the existing Menus/Widgets screens, not option sections. **Body groups the pre-existing `colors`/`typography`/`layout`/`post_display`/`post_meta`/`page_elements`/`featured_image`/`images` sections under one tab — it is not itself a registered `ThemeOptions` section** (`ThemeOptions::sections()` still returns those five section keys unchanged; the tab grouping is purely an admin-view concern). Every custom section your theme/plugin registers (like `my_theme` above) is shown in one more tab after Footer, named for the active theme; the tab only appears when at least one custom section exists. See `admin/views/appearance/customize.php`'s `$tabSections` if you need to place a custom section somewhere specific.

Core's `header`/`welcome_message`/`footer` sections come with matching template tags a theme calls directly — no hook required for the common case:

```php
// Header section
show_site_title(): bool                 // whether to render your title/logo block
has_header_image(): bool
header_image_url(): ?string             // the one header image chosen for this page view: the site can
                                         // hold several and rotates them in order or at random, so
                                         // call it once per page and don't cache the result
header_height(): string                 // bare value, '' when the admin hasn't set an
                                         // override (so your theme's own default height
                                         // wins); also flows through theme_options_css()
                                         // as --lp-header-image-height

show_site_tagline(): bool               // whether to render the site tagline (Header > Show tagline)
show_search_bar(): bool                 // whether to render your header search form (Header > Show search bar)

// Page Elements section (Body tab)
show_page_titles(): bool                // output a static page's <h1> either way; when false add the
                                         // lp-visually-hidden class so it stays for screen readers
show_back_to_top(): bool                // core adds the button itself on footer_assets (a <button class="lp-back-to-top"
                                         // hidden> plus assets/js/back-to-top.js); a theme only styles
                                         // .lp-back-to-top and must fire do_action('footer_assets')
show_breadcrumbs(): bool                // the_page_breadcrumbs()/the_category_breadcrumbs() already
                                         // check it, so a theme needs no condition of its own

// Post Details section (Body tab) — gate each part of a post's byline with it
show_post_meta(string $part): bool      // $part: 'author', 'date', 'categories', 'tags' or
                                         // 'reading_time'; the first four default on, reading_time off
get_reading_time(Post $post): int       // minutes at 200 words/minute, rounded up; 0 for a post with no text
the_reading_time(Post $post): void      // echoes e.g. "3 min read" (nothing when 0)

// Welcome Message section — call in exactly one of header.php/sidebar.php
has_welcome_message(): bool
welcome_message_placement(): string     // 'header' or 'sidebar'
welcome_message(): void                 // echoes rendered HTML, no-ops if empty

// Footer section — distinct from the fixed powered_by_html() attribution
has_footer_html(): bool
footer_html(): void                     // echoes rendered HTML, no-ops if empty
```

Every one of these self-guards (no-ops when unset), the same convention `nav_menu()`/`dynamic_sidebar()`/`custom_css()` already follow — a theme that never calls them loses nothing, and a theme that does call them gets an empty render rather than stray markup when nothing is configured. The default, duskline, and xena-central themes all call these from their own `header.php`/`sidebar.php`/`footer.php` — read those for the exact integration points. **A theme is always free to ignore any of these** (see `content_width`'s own precedent) — xena-central deliberately does not wire `has_header_image()`/`header_image_url()` into its header at all, since its bundled `xc-banner` image is its own permanent defining visual, not something a generic per-theme upload should replace.

### CSS-variable-injected fields

Give a field a `cssVariable` (e.g. `'--my-theme-hero-color'`) and its value is automatically included in the `<style>` block `theme_options_css()` outputs in `header.php` — no extra plumbing needed on your end.

**Important — read before adding a `cssVariable`:** if your theme's own `style.css` already sets a deliberate value for the same CSS variable name (e.g. your theme picks its own `--lp-max-width`), a Theme Options field injecting that same variable will silently override your theme's choice for every visitor, the moment the field exists — even before an admin has touched it — unless the field is given `allowEmpty: true` and a default of `''`. An empty value is treated as "inherit whatever the theme's own stylesheet already set" and is not emitted into the `<style>` block at all; only an admin's explicit, non-empty choice overrides your theme. A theme's own deliberate choices should win over a generic default, and this once affected Lumora Press's own `content_width` field.

The built-in "Body font" field follows the same rule: its default is an empty "Theme default" choice, so a theme's own `--lp-font-body` (Lumora Classic uses Lora) shows unless an admin explicitly picks Georgia, sans-serif, and so on. A theme that wants a separate title font can define its own variable, as Lumora Classic does with `--lp-font-heading`.

## Dark mode

The base convention still lives entirely in `style.css`: define your theme's colors as CSS custom properties on `:root`, then override them inside a `@media (prefers-color-scheme: dark)` block with the same variable names. As of LP-117, a visitor can also override that OS preference explicitly via a header toggle — supporting that needs one extra CSS guard plus one `<script>` tag, both shown below.

```css
:root {
    --my-bg: #ffffff;
    --my-text: #1a1a1a;
}

/* :not([data-theme="light"]) stops an explicit Light choice (below)
   from being overridden back to dark by a dark OS preference. */
@media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
        --my-bg: #1a1a1a;
        --my-text: #f0f0f0;
    }
}

/* An explicit Dark choice, regardless of OS preference. Keep this
   block's values identical to the media-query block above. */
:root[data-theme="dark"] {
    --my-bg: #1a1a1a;
    --my-text: #f0f0f0;
}
```

Add a toggle button anywhere in your markup — `data-lp-theme-toggle` is the only contract theme-toggle.js looks for:

```php
<button type="button" class="lp-theme-toggle" data-lp-theme-toggle aria-label="Toggle dark mode">
    <span class="lp-theme-toggle__icon lp-theme-toggle__icon--dark" aria-hidden="true">🌙</span>
    <span class="lp-theme-toggle__icon lp-theme-toggle__icon--light" aria-hidden="true">☀️</span>
</button>
```

Which icon is visible is driven purely by CSS (the same `data-theme`/ `prefers-color-scheme` guard as the color tokens above), not JS — see Lumora Classic's `style.css` for the `.lp-theme-toggle__icon--*` rules to copy.

Load `theme-toggle.js` as a plain (no `defer`/`async`/`type="module"`) `<script src>` in `<head>`, **before** your theme's stylesheet `<link>`:

```php
<script src="<?= esc_url(core_asset_url('js/theme-toggle.js')) ?>"></script>
```

This ordering matters: the script runs synchronously and sets `data-theme` on `<html>` from `localStorage` (key `lp-theme`) before the stylesheet is even requested, so there's no flash of the wrong theme on reload. It must be an external file, not an inline `<script>` — this project's Content-Security-Policy `script-src` has no `'unsafe-inline'`/nonce allowance (only `style-src` does, via `csp_style_nonce()`), so an inline script here would be silently blocked by every browser. A click on any `[data-lp-theme-toggle]` button only ever switches between an explicit Light and Dark — never back to "follow the system" — mirroring the admin sidebar's own quick theme toggle. See Lumora Classic's `header.php`/`style.css` for a complete real example, and `xena-central`'s/`duskline`'s for how the same three pieces (CSS guard, script tag, toggle button) adapt to a theme with its own token names and header layout.

If you also expose one of these tokens as a Theme Options `cssVariable` field, give it `allowEmpty: true` (see above) so an admin leaving the field unset doesn't force a light-mode-derived value onto dark-mode visitors.

A color an administrator sets in the plain Colors panel is a bare `:root` declaration, so your dark-mode blocks (more specific selectors) override it in dark mode. The Dark Mode Colors panel exists for that: its values are emitted inside the same two dark selectors, after your stylesheet, and win. A theme with a dark appearance built this way should declare `add_theme_support('dark-mode')` (see the Customize section below); without it the Dark Mode Colors panel is hidden.

## Styling bundled plugin markup

### Download cards (Downloads plugin)

Every download in a public list (`[lumora_downloads]`, `[lumora_downloads_all]`, `[lumora_recent_downloads]`, and the single-download form) is one `<li class="lp-downloads-list__item lp-download-card">` inside the `.lp-downloads-list` box. A theme styles these classes and never needs to touch PHP:

| Class | What it is |
| --- | --- |
| `.lp-download-card` | The card itself. The older `.lp-downloads-list__item` class stays on the same element, so a theme that only styles that one keeps working (it will need to undo its separator-row look for a card). |
| `.lp-download-card--has-media` | Added when the card has at least one image. |
| `.lp-download-card__title` | The download's title, an `h4` under a category heading and an `h3` when there is none. |
| `.lp-download-card__media` | The images area (only present when there are images). It also carries `lp-gallery`, so each card is its own lightbox group. |
| `.lp-download-card__carousel` | A horizontally scrolling, snapping row. It works with no JavaScript; give it `scroll-snap-type: x mandatory`. |
| `.lp-download-card__slide` | One image (a link to the full image inside a fixed-height box). Give it a fixed `height` so the card keeps its shape and reserves space before the image loads. |
| `.lp-download-card__nav`, `--prev`, `--next` | The previous/next buttons, added by the script only when there is more than one image. They sit over `.lp-download-card__media`, which should be `position: relative`. |
| `.lp-download-card__status` | The "Image 2 of 5" line the script keeps up to date for screen readers. |
| `.lp-download-card__body` | Wraps everything under the images. |
| `.lp-download-card__meta`, `__link`, `__size` | The row holding the Download button-style link and the optional file size (`.lp-downloads-list__link` and `.lp-downloads-list__size` are on the same elements). |
| `.lp-download-card__description` | The description. Give it a `max-height` and `overflow-y: auto` so a long README scrolls inside the card (`.is-scrollable` is added when it really overflows). |

The images are the download's thumbnail first, then every image found in its description, each picture once. They are lifted out of the description text for display only; the stored description is not changed. `assets/js/download-card.js` is loaded in the footer (through `footer_assets`) only on a page that needs it, so a theme must not load it itself. Because the cards are dropped into post content, a card's `lp-gallery` is nested inside the post's own; the lightbox script opens only the clicked card's images.

## A minimal complete example

```php
<?php // header.php ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= esc_html(site_name()) ?></title>
    <?php the_font_stylesheets(['https://fonts.googleapis.com/css2?family=Lora:wght@400..700&display=swap']); ?>
    <link rel="stylesheet" href="<?= esc_url(theme_url('style.css')) ?>">
    <style nonce="<?= esc_attr(csp_style_nonce()) ?>"><?= theme_options_css() ?></style>
    <?php do_action('head_assets'); ?>
</head>
<body class="<?= esc_attr(body_class('lp-site')) ?>">
    <header>
        <a href="<?= esc_url(site_url('')) ?>"><?= esc_html(site_name()) ?></a>
        <?php nav_menu('primary'); ?>
    </header>
```

```php
<?php // index.php ?>
<?php get_header(); ?>

<?php foreach ($posts as $post): ?>
    <article>
        <h2><a href="<?= esc_url(post_permalink($post)) ?>"><?= esc_html($post->title) ?></a></h2>
        <?php if (has_post_thumbnail($post)): ?>
            <?php the_post_thumbnail_lightbox($post); ?>
        <?php endif; ?>
        <p>By <?php the_author_link($post); ?> on <?= esc_html(the_date($post->publishedAt)) ?></p>
        <?= get_the_excerpt($post) ?>
    </article>
<?php endforeach; ?>

<?php render_pagination($pagination); ?>

<?php get_sidebar(); ?>
<?php get_footer(); ?>
```

For the full picture, read `content/themes/lumora-classic/` — every template listed in this document, working, styled, and dark-mode-aware.
