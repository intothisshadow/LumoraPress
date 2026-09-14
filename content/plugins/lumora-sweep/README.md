# Lumora Sweep

A database cleanup utility for unused, orphaned, and duplicated rows — inspired by the WP-Sweep plugin, ported to Lumora Press's own (smaller) schema rather than copied 1:1.

## What it does

Adds a **Sweep** tab to **Maintenance &rsaquo; Tools**. Each category below shows a live count first; nothing is deleted until you click that category's own Clean Up button.

- **Excess Revisions** — `revision_retention` (Settings &rsaquo; Writing) already caps revisions per post/page going forward. This is a one-time retroactive prune for content that already had more revisions than the cap before it was set or lowered.
- **Old Trashed Posts & Pages** — Posts and Pages each already have their own unconditional Empty Trash action. This instead removes trashed items older than a chosen number of days, combined across both content types in one place.
- **Old Spam & Trashed Comments** — removes comments currently in Spam or Trash whose status hasn't changed in at least the chosen number of days.
- **Orphaned Custom Field Rows** — a `post_meta` row whose post no longer exists. This can happen from a raw database operation bypassing the normal post-deletion path, or from data left over before a cascade-delete fix.
- **Duplicate Custom Field Rows** — two or more `post_meta` rows on the same post with an identical key and value. A plausible bug artifact, safe to de-duplicate; the oldest copy is always kept.
- **Unused Categories & Tags** — categories/tags with zero posts attached. Off by default: an empty category or tag can be intentional (reserved for future content), unlike every other category above. Turn it on only if you're sure.

After each category's cleanup, `OPTIMIZE TABLE` runs on whatever tables that category just touched.

## What it doesn't do

Manual trigger only — this app has no cron/queue infrastructure, so nothing here runs automatically or on a schedule.

Ported only what's real for Lumora Press's own schema, not WP-Sweep's full WordPress-shaped feature list:

- **No "auto-drafts."** Lumora's `Draft` posts are real user drafts, not a WordPress-style silent autosave state — nothing here is safe to sweep as one.
- **No "orphan user meta."** No `user_meta` table exists in this application.
- **No "transient options."** This application has no transients API or table at all.
