# Theme Development

A reference for building a Lumora Press theme: required files, the
template hierarchy, the template-tag API available inside a template, and
how to register widget areas, nav menus, and Theme Options fields.

Themes are traditional PHP templates in the classic WordPress style — no
block editor, no `theme.json`, no Full Site Editing. If you can write
`header.php`/`footer.php`/`single.php` for classic WordPress, you already
know the shape of a Lumora Press theme.

**Keep this document current.** Whenever a template-tag function, the
template hierarchy, a widget/menu/Theme-Options registration pattern, or
the dark-mode convention changes, update this file in the same change —
see `CLAUDE.md`'s "After Every Code Change" rule. This is reference
material, not a changelog: it should describe the API as it exists right
now, not a history of how it got there.

For the hook/filter system, plugin structure, and service-layer APIs, see
[`DEVELOPER-APIS.md`](DEVELOPER-APIS.md) instead — this document is
scoped to what a *theme* author needs.

## Getting started

A theme lives in its own directory under `content/themes/{slug}/`. The
only required file is `style.css`, with a header comment block Lumora
Press reads to identify the theme:

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

Every field is optional (a theme with no `Theme Name:` still shows up,
titled from its directory slug) and only the first 8192 bytes of
`style.css` are read for the header, mirroring classic WordPress's own
`style.css` convention. Add a `preview.jpg`/`screenshot.jpg` (or numbered
`screenshot-2.png`, etc.) in the theme directory for the Appearance ›
Themes admin screen's thumbnail/gallery.

Beyond `style.css`, the templates below are what `SiteController` and
`ThemeRenderer` actually request. The default theme
(`content/themes/default/`) is a complete, working reference
implementation — copy it as a starting point rather than building from
this document alone.

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

`index.php` through `maintenance.php` above are genuinely required:
`ThemeRenderer::render()` throws if the requested file is missing. The
four partials (`sidebar.php`/`comments.php`, plus `header.php`/
`footer.php` which are *conventionally* always present) are located via
`renderPartial()`, which no-ops silently if the file doesn't exist —
useful if your theme has no sidebar at all.

## Template hierarchy

Lumora Press does **not** implement a cascading template hierarchy the
way classic WordPress does (no `single-{post-type}-{slug}.php` fallback
chain). `SiteController` hardcodes which literal filename to render for
each route:

| Route | Template |
|---|---|
| Homepage / static "Posts page" | `index.php` |
| Single post (and post preview) | `single.php` |
| Static page | `page.php` |
| Author, category, tag, and date archives | `archive.php` (all four — distinguished only by an `$archive_type` variable: `'author'`/`'category'`/`'tag'`/`'date'`) |
| Search results | `search.php` |
| Unmatched route / explicit 404 | `404.php` |
| Maintenance Mode | `maintenance.php` |

Partials (`header.php`, `footer.php`, `sidebar.php`, `comments.php`) are
requested by name from inside the templates above via `get_header()`/
`get_footer()`/`get_sidebar()`/`comments_template()` — see the Template
Tags reference below. `functions.php` is loaded once per request,
separately from rendering, before any template or Theme Options
registration happens.

There is no way for a theme to add a per-slug or per-category template
override beyond what's listed above (a `single-my-slug.php` file, for
example, is simply never looked for).

## The "loop"

There is no global `$post`/`have_posts()`/`the_post()` state to manage.
`SiteController` hands each template exactly the data it needs as plain
local variables:

- `index.php` and `archive.php` receive `$posts` (a plain array of
  `Post` objects) — iterate it with an ordinary `foreach`:

  ```php
  <?php foreach ($posts as $post): ?>
      <h2><a href="<?= esc_url(post_permalink($post)) ?>"><?= esc_html($post->title) ?></a></h2>
      <?= get_the_excerpt($post) ?>
  <?php endforeach; ?>
  ```

- `single.php` and `page.php` receive a single `$post`/`$page` variable
  directly — no loop needed.
- `search.php` receives `$results` (an array of `SearchResult` objects),
  iterated the same way.
- `comments.php` receives `$comment_tree` (a nested array), rendered via
  the `comment_list()` template tag rather than hand-written iteration.

## Template Tags

Every function below is declared with a `function_exists()` guard, so a
theme (or plugin) may safely redefine one if it truly needs to — though
that's rarely necessary. Functions that echo output are prefixed `the_`
(matching WordPress convention); functions that return a value for you to
handle yourself are prefixed `get_` or named directly after what they
return.

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
| `highlight_terms(string $escapedText, string $query): string` | Wraps matches of each query word (≥2 chars) in `<mark>`. Operates on **already-escaped** text — see `search.php`. |
| `render_pagination(array $pagination, string $label = 'Posts pagination'): void` | Echoes a pagination `<nav>` for `array{page, totalPages}`: First/Prev/Next/Last controls plus numbered pages truncated with `…` ellipses around the current page and the range's edges; no-op if there's only one page. Styling classes: `.lp-pagination__item--nav` (First/Prev/Next/Last, `.is-disabled` at either end of the range) and `.lp-pagination__item--ellipsis`. |

### Author

| Function | Purpose |
|---|---|
| `author_name(Post $post): string` | Author's display name (`'Unknown'` if the account was deleted). |
| `author_url(Post $post): ?string` | Author's public archive URL, or `null` if the account no longer exists. |
| `the_author_link(Post $post): void` | Echoes the author's name linked to their archive, falling back to plain text. |

### Pages / breadcrumbs

| Function | Purpose |
|---|---|
| `get_page_breadcrumbs(array $ancestors): array` | Maps a root-first `Page[]` ancestor chain (not including the current page) to `{title, url}` pairs. |
| `the_page_breadcrumbs(Page $page, array $ancestors): void` | Echoes a `<nav>`/`<ol>` breadcrumb trail. Outputs nothing for a top-level page. |

### URLs & permalinks

| Function | Purpose |
|---|---|
| `post_permalink(Post $post): string` | A post's public URL — the single choke point that honors the site's configured permalink structure (see Settings › Permalinks). Always use this, never hand-build `'post/' . $post->slug`. |
| `page_permalink(Page $page): string` | A page's URL — always flat `page/{slug}`; pages have no configurable structure. |
| `category_permalink(Category $category): string` / `tag_permalink(Tag $tag): string` | Category/tag archive URLs, honoring the configured base prefix. |
| `search_result_permalink(SearchResult $result): string` | Dispatches by the result's type to the right permalink builder above. |
| `privacy_policy_url(): ?string` | The URL of the Page named on Settings › Privacy, or `null` if none is set or the configured page no longer exists/isn't publicly visible. Whether and where to link it (footer, comment form notice, etc.) is entirely up to the theme — nothing links to it automatically. |
| `site_url(string $path = ''): string` | Root-relative URL under the site's base path. Use for any internal link. |
| `home_url(string $path = ''): string` | Absolute URL (scheme + host + base path) — needed for RSS, outbound email, and canonical/OG tags, where a relative URL won't do. |
| `admin_url(string $page = ''): string` | URL into `/admin`. |
| `theme_url(string $path = ''): string` | URL to a file inside the *active* theme's own directory, cache-busted by file mtime — e.g. `theme_url('style.css')`. |
| `core_asset_url(string $path = ''): string` | URL to a static file under the top-level `assets/` directory (framework-owned JS/CSS a theme may depend on). |
| `canonical_url(): string` | The current request's self-referencing canonical URL, keeping only `?paged=`/`?q=` and dropping every other query param. |

### Media & images

All of these accept `Post|Page|SearchResult $item` — anything with a
`featuredImageId`.

| Function | Purpose |
|---|---|
| `has_post_thumbnail($item): bool` | Whether a featured image resolves for `$item` (including the site-wide default featured image fallback). |
| `the_post_thumbnail($item, string $size = 'medium', array $attrs = []): void` | Echoes an `<img>` with a real `srcset`/`sizes`, or a fixed-size `<img>` if a manual crop applies. No-op if there's no image. |
| `the_post_thumbnail_lightbox($item, string $size = 'medium', ?string $largeSize = null, array $attrs = []): void` | Same as above, wrapped in a PhotoSwipe-lightbox `<a>`. **Prefer this over `the_post_thumbnail()`** on any listing/single template — it's what marks the page as needing the lightbox JS/CSS (via `footer_assets`), so using the plain version means clicking the image does nothing. |
| `post_thumbnail_url($item, string $size = 'medium', bool $absolute = false): ?string` | Just the URL, for OG tags/RSS/manual `<img>` construction. |
| `post_thumbnail_caption($item): ?string` | The image's caption, or `null`. |
| `post_thumbnail_media($item): ?array` | The raw media row (advanced use — `file_size`, `mime_type`, etc.). |

### Comments

| Function | Purpose |
|---|---|
| `comment_form(Post\|Page $content, ?User $currentUser, array $guestFieldOptions = [], ?int $parentId = null, string $submitLabel = 'Post Comment'): void` | Renders one CSRF-protected form. Pass `$parentId` for a reply form. |
| `comment_list(array $tree, Post\|Page $content, ?User $currentUser, array $guestFieldOptions = [], int $depth = 0, int $maxDepth = 5, bool $avatarsEnabled = true, string $avatarRating = 'g', string $avatarDefault = 'mp'): void` | Renders the full nested comment thread, each reply's form embedded via `comment_form()`. |
| `comments_template(array $vars = []): void` | Fires the `comments_template` action, then renders the theme's own `comments.php` (no-op if it doesn't exist). Call this from `single.php`/`page.php` rather than writing comment markup by hand. |
| `comment_avatar_url(string $email, string $rating = 'g', string $default = 'mp', int $size = 48): string` | A Gravatar URL — used internally by `comment_list()`, exposed if you need it directly. |
| `format_comment_content(string $raw): string` | Escapes raw comment text and auto-links bare URLs. |

### Theme layout (`header.php`/`footer.php`/`sidebar.php`)

| Function | Purpose |
|---|---|
| `get_header(array $vars = []): void` | Fires the `get_header` action, then renders `header.php`, extracting `$vars` as local variables inside it (e.g. `get_header(['post' => $post])` from `single.php`). |
| `get_footer(): void` | Fires `get_footer`, then renders `footer.php`. |
| `get_sidebar(): void` | Fires `get_sidebar`, then renders `sidebar.php` (silent no-op if the theme has none). |

Your `header.php` and `footer.php` are also where you fire the two
theme-convention hooks other code (and plugins) expect: `do_action('head_assets')`
right before `</head>` and `do_action('footer_assets')` right before
`</body>`. Core itself listens on `footer_assets` (to emit lightbox/embed
script tags when a template used `the_post_thumbnail_lightbox()` or an
auto-embedded URL), so skipping these hooks will silently break those
features. See [`DEVELOPER-APIS.md`](DEVELOPER-APIS.md) for the full hook
reference.

### Site info & meta

| Function | Purpose |
|---|---|
| `site_name(): string` | Site display name (Settings › General). |
| `site_tagline(): string` | Site tagline/description. |
| `site_logo_url(): ?string` / `favicon_url(): ?string` | Configured logo/favicon URL, or `null`. |
| `meta_description(): string` | Site-wide fallback meta description. |
| `default_og_image_url(): ?string` | Site-wide fallback Open Graph/Twitter Card image. |
| `footer_copyright_text(): string` | Admin-configured footer text; empty by default (keep your theme's own auto `© {year} {site}` fallback when this is empty). |
| `search_engines_discouraged(): bool` | Whether Settings › Reading has asked search engines not to index the site — print `<meta name="robots" content="noindex,nofollow">` when true. |
| `the_date(\DateTimeInterface $date): string` / `the_time(\DateTimeInterface $date): string` | Formats a date/time using the admin-configured format (Settings › General). Always use these instead of calling `->format()` directly, so admin-configured date/time formats are respected everywhere. |

### Escaping

| Function | Purpose |
|---|---|
| `esc_html(string $value): string` | For HTML text nodes. |
| `esc_attr(string $value): string` | For HTML attribute values. |
| `esc_url(string $url): string` | For URL attribute values. |

Escape at output time, right where you echo — never store pre-escaped
data. Content returned by `the_content()`/`render_content()`/
`get_the_excerpt()` is already safe HTML/plain-text by the time it
reaches you; everything else (titles, slugs, user-entered fields) needs
explicit escaping.

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

`is_active_sidebar()` is a guard, not required — `dynamic_sidebar()`
itself no-ops if the area has no widgets, but wrapping the outer markup
in the guard avoids an empty `<aside>` shell.

## Navigation menus

Register in `functions.php`:

```php
register_nav_menu('primary', 'Primary Menu');
```

Render in a template:

```php
<?php nav_menu('primary'); ?>
```

`nav_menu()` renders the assigned menu as a nested `<ul class="lp-nav-menu">`
and self-guards (no-op if nothing is assigned to that location) — no
`has_nav_menu()` check is required around it, though `has_nav_menu(string $location): bool`
is available if you need a conditional wrapper around surrounding markup
(a `<nav>` tag, say).

## Theme Options

A theme (or plugin) registers its own Theme Options fields by hooking
`register_theme_options` from `functions.php` — this hook fires *after*
your theme's `functions.php` has already loaded and *after* core's own
eleven built-in fields (Colors, Typography, Layout, Post Display) are
already registered:

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

Read the value in a template with `theme_option('my_theme_hero_text')` —
this always returns a string, falling back to the field's own `default`
if unset, and never throws for an unknown key.

`ThemeOptionType` cases: `Text`, `Textarea`, `Number`, `Checkbox`,
`Select`, `Color`, `Url`.

`ThemeOptionField` constructor parameters: `key`, `section`, `type`,
`label`, `default = ''`, `cssVariable = null`, `help = ''`,
`choices = []` (Select: value ⇒ label), `min = null`/`max = null`
(Number), `cssUnit = ''` (Number, e.g. `'px'`), `cssValueMap = []`
(Select: stored value ⇒ actual CSS value), `allowEmpty = false`,
`previewDefault = null` (Color: swatch shown when the value is empty),
`allowedHosts = []` (Url: restrict the submitted URL's host).

### CSS-variable-injected fields

Give a field a `cssVariable` (e.g. `'--my-theme-hero-color'`) and its
value is automatically included in the `<style>` block
`theme_options_css()` outputs in `header.php` — no extra plumbing needed
on your end.

**Important — read before adding a `cssVariable`:** if your theme's own
`style.css` already sets a deliberate value for the same CSS variable
name (e.g. your theme picks its own `--lp-max-width`), a Theme Options
field injecting that same variable will silently override your theme's
choice for every visitor, the moment the field exists — even before an
admin has touched it — unless the field is given `allowEmpty: true` and
a default of `''`. An empty value is treated as "inherit whatever the
theme's own stylesheet already set" and is not emitted into the
`<style>` block at all; only an admin's explicit, non-empty choice
overrides your theme. See CLAUDE.md's "A theme's own explicit design
choices take precedence over generic core defaults" for the full
reasoning (this bit Lumora Press's own `content_width` field once — see
`DECISIONS.md`'s LP-095 entry).

## Dark mode

There's no PHP-level dark-mode API — no helper function, no body class.
The entire convention lives in `style.css`: define your theme's colors as
CSS custom properties on `:root`, then override them inside a
`@media (prefers-color-scheme: dark)` block with the same variable names:

```css
:root {
    --my-bg: #ffffff;
    --my-text: #1a1a1a;
}

@media (prefers-color-scheme: dark) {
    :root {
        --my-bg: #1a1a1a;
        --my-text: #f0f0f0;
    }
}
```

No JavaScript toggle, no server round-trip — pure OS-preference media
query. See the default theme's `style.css` for a complete real example.

If you also expose one of these tokens as a Theme Options `cssVariable`
field, give it `allowEmpty: true` (see above) so an admin leaving the
field unset doesn't force a light-mode-derived value onto dark-mode
visitors.

## A minimal complete example

```php
<?php // header.php ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= esc_html(site_name()) ?></title>
    <link rel="stylesheet" href="<?= esc_url(theme_url('style.css')) ?>">
    <style nonce="<?= esc_attr(csp_style_nonce()) ?>"><?= theme_options_css() ?></style>
    <?php do_action('head_assets'); ?>
</head>
<body>
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

For the full picture, read `content/themes/default/` — every template
listed in this document, working, styled, and dark-mode-aware.
