# Shortcodes

A shortcode is a `[name attr="value"]` tag you type directly into a post, page, or Download description — Lumora Press replaces it with real rendered HTML wherever the content is displayed. They work the same way in Markdown, HTML, and Plain content, and the same way in every field that accepts formatted content (a post/page body, a Download's description).

Anyone who can already edit the content a shortcode is typed into can use it — there's no separate permission for shortcodes themselves.

## Inserting a shortcode

Some shortcodes have a dedicated toolbar button in the content editor (Insert Audio, Insert Video, Insert Folder) that builds the tag for you. Others show up in the editor toolbar's **Insert Shortcode** picker — a form for the shortcode's main options — once the relevant core feature or plugin registers them. Either way, you can also just type the tag by hand; the picker only covers the more common attributes, and every shortcode below documents its full attribute set for when you need one the picker doesn't expose.

## Core shortcodes

These are always available — no plugin required.

### `[lumora_folder_gallery]`

Inserted via the editor's **Insert Folder** button. Renders every image in a Media Manager folder as a row of thumbnails.

| Attribute | Required | Description |
| --- | --- | --- |
| `folder_id` | Yes | The Media folder's id. |
| `link` | No | `none` (default) or `full` — whether each thumbnail links to the full-size image in the lightbox. |

```
[lumora_folder_gallery folder_id="12" link="full"]
```

### `[lumora_audio]`

Inserted via the editor's **Insert Audio** button. Embeds a Plyr-enhanced audio player for an uploaded audio file.

| Attribute | Required | Description |
| --- | --- | --- |
| `id` | Yes | The Media item's id (must be an audio file). |

```
[lumora_audio id="45"]
```

### `[lumora_video]`

Inserted via the editor's **Insert Video** button. Embeds a Plyr-enhanced video player. A poster image and a caption/subtitle track, if set on the Media item's own edit screen, render automatically — there's no shortcode attribute for either.

| Attribute | Required | Description |
| --- | --- | --- |
| `id` | Yes | The Media item's id (must be a video file). |

```
[lumora_video id="78"]
```

## Plugin shortcodes

These only work while the plugin providing them is active.

### `[icon]` — Font Awesome plugin

Shows up in the editor's Insert Shortcode picker as **Icon**, with a searchable icon browser for the `name` field. Usable in Markdown, HTML, or Plain content.

| Attribute | Required | Description |
| --- | --- | --- |
| `name` | Yes | The icon's slug, e.g. `camera`. |
| `style` | No | `solid` (default), `regular`, `brands`, `light`, `thin`, or `duotone` — not every style exists for every icon. |
| `size` | No | `xs`, `sm`, `lg`, or `1x`&ndash;`10x`. |
| `rotate` | No | `90`, `180`, or `270`. |
| `flip` | No | `horizontal`, `vertical`, or `both`. |
| `animation` | No | `spin`, `spin-pulse`, `spin-reverse`, `pulse`, `beat`, `beat-fade`, `fade`, `bounce`, or `shake`. |
| `color` | No | A hex code or CSS color keyword. |
| `class` | No | Extra space-separated CSS classes. |
| `label` | No | An accessible name read by screen readers; omit for a purely decorative icon. |

```
[icon name="camera" style="solid" size="lg" color="#c0392b" label="Camera"]
```

### `[lumora_downloads]` — Downloads plugin

Shows up in the picker as **Downloads from Category**. Three variants, checked in this order: a single download by id, the newest N downloads (optionally filtered to a category), or every download in a category. **Downloads &rsaquo; Shortcodes** in the admin has a live reference built from your site's own categories.

| Attribute | Required | Description |
| --- | --- | --- |
| `download_id` | No | Show one specific download by id. |
| `count` | No | Show the newest N downloads, newest first. Combine with `category_id`/`category` to limit to one category. |
| `category_id` | No | A Download Category's id. Takes priority over `category` if both are given. |
| `category` | No | A Download Category's name, matched case-insensitively. Ignored if `category_id` is set. |
| `show_size` | No | `1` to show each file's size next to its link. Defaults to off. |

With none of `download_id`/`count`/`category_id`/`category` given, nothing renders. With `category_id`/`category` alone (no `count`), every download in that category renders, alphabetically.

```
[lumora_downloads category="Wallpapers" show_size="1"]
[lumora_downloads count="5"]
```

### `[contact_form]` — Contact Forms plugin

Shows up in the picker as **Contact Form**. **Contact Forms &rsaquo; All Forms** lists each form's ready-to-copy shortcode.

| Attribute | Required | Description |
| --- | --- | --- |
| `id` | Yes | The form's id. |

```
[contact_form id="1"]
```

### `[lumora_gallery_album]` / `[lumora_gallery_newest]` — Lumora Gallery Shortcodes plugin

Only works with a separately-installed [Lumora Gallery](https://coding.unloved-heart.net/scripts/lumoragallery) site configured on **Lumora Gallery Shortcodes &rsaquo; Settings**. Both shortcodes show up in the picker with their most common attribute; the rest are typeable by hand. **Lumora Gallery Shortcodes &rsaquo; Shortcodes** in the admin has the full reference with copy-paste examples.

`[lumora_gallery_album]` has three variants, checked in this order:

| Attribute | Required | Description |
| --- | --- | --- |
| `image_id` | No | One or more specific image ids, comma-separated. Renders exactly those images regardless of the other attributes. |
| `album_id` | No\* | An album's id. |
| `folder` | No\* | An album's folder name, if you'd rather not look up its id. Ignored if `album_id` is set. |
| `count` | No | With `album_id`/`folder` and no `image_id`: the newest N images in that album, instead of every image. |

\* One of `album_id`/`folder` is required unless `image_id` is given.

`[lumora_gallery_newest]` shows the newest images across the whole gallery, not scoped to one album:

| Attribute | Required | Description |
| --- | --- | --- |
| `count` | No | Number of images. Defaults to `10`. |

```
[lumora_gallery_album folder="xena/season1"]
[lumora_gallery_album album_id="4" count="6"]
[lumora_gallery_newest count="12"]
```

## Imported-content compatibility

The WordPress Importer plugin renders three shortcodes from WordPress's Simple Download Monitor plugin, only for content actually migrated by an import — they aren't meant to be typed by hand on new content, but stay available so imported posts/pages keep rendering afterward. Each resolves against content the import already migrated (a Media folder, or the specific imported file/redirect a shortcode's original id pointed at).

| Shortcode | Attributes |
| --- | --- |
| `[sdm_show_dl_from_category]` | `category_slug` (required) — a Media folder, matched by its slugified name, the same way the imported content itself references it. `show_size` — `1` to show each file's size. |
| `[sdm_download]` | `id` (required) — the download's original WordPress id. |
| `[sdm_latest_downloads]` | `number` — how many to show, newest first. Defaults to `5`. `category_slug` — limit to one Media folder. `show_size` — `1` to show each file's size. |

## Building your own

Building a plugin that adds a shortcode — including how to register one for the editor's Insert Shortcode picker — is covered in [`DEVELOPER-APIS.md`](DEVELOPER-APIS.md)'s Shortcodes section.
