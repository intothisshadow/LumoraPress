# Dummy Content

A developer-only utility that generates realistic, varied placeholder content so theme and plugin developers can exercise real-world rendering paths without hand-authoring test data — the same role the classic WordPress "Theme Unit Test" XML data set plays for WordPress theme review, but native to Lumora Press's own content model and fully reversible.

## What this plugin does

- **Maintenance → Tools** (a section on that shared screen, not a dedicated menu entry of its own): pick a volume preset (small/medium/large), choose which content types to generate (users, posts, pages, media, comments), and click Generate.
- Generates one user per role (Administrator, Editor, Author, Contributor, Subscriber) with `@example.test` emails, a small nested category tree, a varied tag set, placeholder images (generated locally with GD, not fetched from any external service), posts mixing every status/content format with categories/tags/comments/occasional custom fields, pages with a shallow parent/child hierarchy, and comments mixing approved/pending/spam/trashed status, guest and registered commenters, and basic threaded replies.
- Every generated record is tagged so **Remove All Generated Content** deletes exactly what was generated and nothing else — a post, page, or user you created by hand is never touched.
- Generation refuses to run again while previously generated content still exists — remove it first, then generate again. This keeps re-running idempotent (never duplicates) without needing to diff against existing content.

## Notes

- Gated the same "internal tooling, not a user-facing feature" way this project already treats its own test suite — never active by default.
- Placeholder images are plain solid-color PNGs rendered with PHP's GD extension, not fetched from any external placeholder-image service — keeps generation fast and offline-safe.
- The generator's word lists and paragraph generation are intentionally simple (no external dependency) rather than a full Faker-style library.
