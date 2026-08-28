# Lumora Shield

Optional hardening and spam-prevention features beyond core's own basics
(honeypot/CSRF/submission-timing/rate-limiting, plus optional Akismet —
see Settings &rsaquo; Security). This is the first module of a much
larger, still-growing plugin — see `TODO-PLUGINS.md`'s LPP-001 for the
full roadmap.

## What this plugin does (first module: Stop User Enumeration)

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

## How it's wired in

`SiteController::author()` runs its own visibility decision through a
`lumora_shield_author_archive_visible` filter (default `true`, a no-op
when this plugin isn't active) — called only *after* the zero-post
check above has already run unconditionally. This plugin's only
listener, `LumoraShieldService::authorArchiveVisible()`, has exactly one
thing left to decide: whether "hide entirely" is on.

## Deferred (see `TODO-PLUGINS.md`'s LPP-001 for the full checklist)

Everything else in the ticket — blacklist/whitelist management,
reputation scoring, CAPTCHA support, dashboards/reporting, third-party
integrations beyond core's own Akismet, request logging, and the
Developer API — is not built yet.
