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

Two more categories deal with the accounts that pile up when public registration (Settings &rsaquo; Registration) is switched on:

- **Inactive Accounts** — sign-ups that were confirmed but never used. An account is listed only if it registered through the public sign-up form, is a Subscriber or Contributor, is older than the number of days you choose (default 30), and has never signed in (or not within the days you choose). An account that has any posts, pages, comments, uploads, comment subscriptions, reactions, reports, or notifications is never listed, and neither are Authors, Editors, Administrators, accounts an administrator added, imported accounts, or your own account. The tab shows the count and the oldest 20 usernames. **Move to Trash** sends them to the Users trash, where each can be restored.
- **Old Trashed Users** — permanently deletes users who have been in the Users trash for at least the number of days you choose (default 30). It covers every trashed user, not only sign-ups. A user who wrote posts or pages is never deleted, and neither is the last Administrator.

Both run in batches with a progress bar, so a site with thousands of accounts does not time out. A third panel, **Account Clean-up Settings**, remembers your rules and sets the **reminder**: when the number of inactive accounts reaches a threshold (default 25, 0 turns it off), every Administrator gets a "Clean-up reminder" in the Notifications inbox with an **Open Sweep** button. It is checked at most once an hour while an administrator is using the admin, nothing new is sent while an earlier reminder is unread, and the next waits at least the number of days you set (default 7). Each Administrator can switch the reminder off on My Profile. It is never emailed.

After each category's cleanup, `OPTIMIZE TABLE` runs on whatever tables that category just touched.

## What it doesn't do

Manual trigger only — this app has no cron/queue infrastructure, so nothing here runs automatically or on a schedule.

Ported only what's real for Lumora Press's own schema, not WP-Sweep's full WordPress-shaped feature list:

- **No "auto-drafts."** Lumora's `Draft` posts are real user drafts, not a WordPress-style silent autosave state — nothing here is safe to sweep as one.
- **No "orphan user meta."** No `user_meta` table exists in this application.
- **No "transient options."** This application has no transients API or table at all.
