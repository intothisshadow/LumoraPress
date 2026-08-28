# Lumora Shield

Optional hardening and spam-prevention features beyond core's own basics
(honeypot/CSRF/submission-timing/rate-limiting, plus optional Akismet —
see Settings &rsaquo; Security). This is the first module of a much
larger, still-growing plugin — see `TODO-PLUGINS.md`'s LPP-001 for the
full roadmap.

## What this plugin does (first module: Stop User Enumeration)

A 2026-08-28 audit found the public author archive (`/author/{slug}`,
LP-008) is a username-existence oracle on its own: requesting a real
username's archive always returns a normal page — even an empty one, for
an account that's never published anything — while a made-up username
404s. Everything else in the classic WordPress user-enumeration attack
surface (XML-RPC, a REST endpoint listing user accounts, login/password-
reset responses that differ based on whether a username/email exists)
still doesn't exist in Lumora Press, so this module is scoped to the one
real gap rather than built speculatively against surface area that isn't
there.

**Lumora Shield &rsaquo; Settings**:

- **Enable Lumora Shield** — master toggle; off restores exact pre-plugin
  behavior everywhere.
- **Stop user enumeration via author archives** (on by default) — an
  author's archive 404s exactly like a nonexistent username whenever
  they have zero published posts. A real author who has actually
  published something stays visible — their identity is already public
  via their own posts' bylines, so hiding that too would break a real
  feature without closing any actual gap.
- **Hide author archives entirely** (off by default) — every
  `/author/{slug}` URL 404s regardless of post count. Stronger, but
  removes the public "browse everything by this author" feature; most
  sites only need the option above.

## How it's wired in

`SiteController::author()` runs its own visibility decision through a
`lumora_shield_author_archive_visible` filter (default `true`, a no-op
when this plugin isn't active) after resolving the requested slug to a
real user but before rendering. This plugin's only listener is
`LumoraShieldService::authorArchiveVisible()`.

## Deferred (see `TODO-PLUGINS.md`'s LPP-001 for the full checklist)

Everything else in the ticket — blacklist/whitelist management,
reputation scoring, CAPTCHA support, dashboards/reporting, third-party
integrations beyond core's own Akismet, request logging, and the
Developer API — is not built yet.
