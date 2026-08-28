# Lumora Shield

Optional hardening and spam-prevention features beyond core's own basics
(honeypot/CSRF/submission-timing/rate-limiting, plus optional Akismet —
see Settings &rsaquo; Security). This is the first module of a much
larger, still-growing plugin — see `TODO-PLUGINS.md`'s LPP-001 for the
full roadmap.

## What this plugin does

### Stop User Enumeration

A 2026-08-28 audit found the public author archive (`/author/{slug}`,
LP-008) was a username-existence oracle on its own: requesting a real
username's archive always returned a normal page — even an empty one, for
an account that's never published anything — while a made-up username
404'd. Everything else in the classic WordPress user-enumeration attack
surface (XML-RPC, a REST endpoint listing user accounts, login/password-
reset responses that differ based on whether a username/email exists)
still doesn't exist in Lumora Press, so this module is scoped to the one
real gap rather than built speculatively against surface area that isn't
there.

The zero-published-posts half of that fix has no real tradeoff — an
author with nothing published has no archive content anyone
legitimately wants to browse — so it's fixed **unconditionally in core**
(`SiteController::author()`), not gated behind this plugin at all; it's
in effect whether or not Lumora Shield is even installed.

**Lumora Shield &rsaquo; Settings** (this plugin's only remaining job
here):

- **Hide author archives entirely** (off by default) — every
  `/author/{slug}` URL 404s regardless of post count, including real
  authors who have actually published something. This is the one
  genuine optional tradeoff: it removes the public "browse everything by
  this author" feature, so it's opt-in rather than secure-by-default.

`SiteController::author()` runs its own visibility decision through a
`lumora_shield_author_archive_visible` filter (default `true`, a no-op
when this plugin isn't active) — called only *after* the zero-post
check above has already run unconditionally. This plugin's only
listener, `LumoraShieldService::authorArchiveVisible()`, has exactly one
thing left to decide: whether "hide entirely" is on.

### Monitoring

Every blocked `/author/{slug}` request fires
`lumora_shield_enumeration_blocked` (a no-op core hook, same shape as
the filter above) — this plugin logs it to **Lumora Shield &rsaquo;
Logs**: IP address, requested slug, reason, and timestamp. Configurable
at **Lumora Shield &rsaquo; Settings**:

- **Log blocked enumeration attempts** (on by default) and a
  **retention period** in days — old rows are cleaned up automatically
  (probabilistically, on write; no cron/scheduler exists anywhere in
  this codebase).
- **Email the site admin on repeated attempts** (off by default) —
  fires at most once per hour per IP, once a configurable attempt
  threshold is crossed.

### Comment Analysis

A `CommentAnalyzer` class runs independent content checks (excessive
links, excessive uppercase/punctuation, hidden Unicode characters,
repeated phrases, extremely short/long comments) and behavioral checks
(posting frequency, prior spam history, duplicate content) — any one
tripping pushes the comment toward Spam via the existing
`comment_is_spam` filter, the same one every comment-creation call site
already exposes. Can only push *toward* Spam, matching that filter's
own contract — never un-spams a comment another check (or Akismet)
already flagged. Toggled independently via **Enable Comment Analysis**
at **Lumora Shield &rsaquo; Settings**.

### Contact Form Protection

A 2026-08-28 audit of the Contact Forms plugin found it had already
independently built CSRF, a honeypot, `FormTiming`, per-IP rate
limiting, and fully working reCAPTCHA v2/Cloudflare Turnstile/Akismet
— the one gap was a content check. This module closes it via the
Contact Forms plugin's own `contact_form_is_spam` filter (same
can-only-push-toward-Spam contract as `comment_is_spam`), reusing
`CommentAnalyzer::contentReasons()` — the exact same content checks
Comment Analysis applies to comments — against every submitted field
value joined together (a contact form's field set is arbitrary:
Name/Email/Subject/Message/Text/Textarea/Checkbox/Select, not a single
fixed "content" column). Toggled independently via **Enable Contact
Form Protection** at **Lumora Shield &rsaquo; Settings**. Only takes
effect while the Contact Forms plugin is active — registering the
listener is harmless when it isn't, since nothing ever calls that
filter in that case.

## Deferred (see `TODO-PLUGINS.md`'s LPP-001 for the full checklist)

Blacklist/whitelist management, a reputation-scoring engine, CAPTCHA
support, dashboards/reporting, third-party spam-service integrations
beyond core's own Akismet, and the Developer API are not built yet.
