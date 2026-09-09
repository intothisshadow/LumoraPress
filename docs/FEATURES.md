# Full Feature List

The complete, detailed feature inventory for the current release. For a
quick scannable overview, see the "Features" section of the root
[`README.md`](../README.md) instead — this document goes into
implementation-level detail on each area.

**Version 0.10.0**

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
  Merge), matching Posts/Pages where applicable. Tags support the same
  Merge and Delete bulk actions (flat, no hierarchy to reparent) plus a
  one-click "Remove unused tags," a post's own tags are shown on its
  single-post page linked to their archive, and every post's page shows
  a "Related Posts" block of other posts sharing at least one tag.
- **Permalinks** — a Settings &rsaquo; Permalinks screen to choose the
  post URL structure (Post name, Day and name, Month and name, or a
  custom token-based pattern) and rename the Category/Tag archive URL
  prefixes. Unconfigured, URLs are unchanged from `/post/{slug}`.
- **Comments** — threaded discussion on both Posts and Pages, with
  moderation (including bulk approve/spam/trash/delete), a per-status
  count on every filter tab (All/Pending/Approved/Spam/Trash), an
  Empty Spam action alongside Empty Trash, honeypot/CSRF/
  submission-timing spam protection, and optional Akismet spam-checking
  (Settings &rsaquo; Security — off by default, never required). Every
  post listing (the homepage and every category/tag/author/date
  archive) shows each post's comment count linking through to its own
  comment thread, the classic "X Comments" link.
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
- **Portable Settings Export/Import** (Maintenance &rsaquo; Tools) —
  download a file with one install's Permalinks, Reading, Discussion,
  Media/Thumbnails, and General's non-identity settings, then import it
  into a *different*, unrelated Lumora Press install to copy that
  configuration across — with a before/after preview shown before
  anything is applied. Site identity (URL, tagline, admin email) and
  anything install-specific (Security, Privacy, Redirects, Cache,
  Embeds, or a setting referencing a specific local media file or page)
  is never included.
- **Appearance** — theme browser (install, activate, preview — a
  previewed theme now persists across the whole site as you click
  through it, not just the page you started on — and delete inactive
  themes individually or in bulk), branding, custom CSS, a tabbed
  Customize screen (Header, Welcome Message, Body — colors/typography/
  layout/post display, Menu, Widgets, Footer; values are scoped per
  active theme, no CSS editing required), widgets (drag-to-reorder,
  including the Dashboard's own widgets, per signed-in user), navigation
  menus, and a built-in theme file editor.
- **Plugin browser** — install, activate, and manage plugins from the
  admin panel, including deleting inactive plugins individually or in
  bulk.
- **Font Awesome plugin** (bundled) — an `[icon]` shortcode and a small
  developer API (`lp_icon()` and friends) for icons in theme/plugin markup,
  with CDN or self-hosted delivery (Settings &rsaquo; Appearance &rsaquo;
  Font Awesome, off by default), plus a searchable icon picker in the
  post/page/downloads editor toolbar once enabled.
- **Emoji Picker plugin** (bundled, active by default) — a search-and-
  browse-by-category emoji picker in the post/page/downloads editor
  toolbar (Visual/HTML and Markdown), with a per-user Recently Used
  category and a small developer API (an `lp_emoji_dataset` filter, an
  `lp_emoji_inserted` action, `lp_emoji_picker_button()` for use outside
  the default toolbars). Inserts a plain Unicode character — no images,
  shortcodes, or third-party requests (Settings &rsaquo; Writing &rsaquo;
  Emoji Picker).
- **Lumora Shield plugin** (bundled) — optional hardening features
  beyond core's own basics: an optional "hide author archives entirely"
  setting on top of core's own always-on author-archive username-
  enumeration protection; a log of blocked enumeration attempts with
  configurable retention and optional email alerts; and content/
  behavioral spam heuristics (excessive links, uppercase/punctuation,
  hidden Unicode characters, repeated phrases, prior spam history,
  posting frequency, duplicate content) feeding both core's
  `comment_is_spam` extension point and, when the Contact Forms plugin
  is active, its own `contact_form_is_spam` filter (Lumora Shield
  &rsaquo; Settings, all off/on-by-sensible-default).
- **Media Manager** — multi-file uploads with per-file progress, virtual
  folders, metadata, thumbnail generation, a lightbox viewer (covering
  both featured images and images embedded directly in post/page
  content), download statistics for document/archive/audio/video files,
  and usage tracking with delete-time warnings.
- **Featured images** — per-post/page featured images with manual
  cropping (with a configurable output size), or crop any already-
  uploaded image directly from its Media Manager edit screen into a new,
  independently reusable featured-image-ready Library item. A site-wide
  Customize option chooses whether a featured image appears above the
  title or beside it, with the front page's Classic layout following
  the same choice.
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
  permission sees a quick "Edit this post" link on the single post view,
  and an "Edit this page" counterpart on a single Page.

See `TODO.md` for planned work and known gaps.
