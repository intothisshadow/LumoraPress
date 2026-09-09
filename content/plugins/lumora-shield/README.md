# Lumora Shield

Optional hardening and spam-prevention features beyond core's own basics (honeypot/CSRF/submission-timing/rate-limiting, plus optional Akismet — see Settings &rsaquo; Security).

## What this plugin does

### Stop User Enumeration

The public author archive already refuses to reveal whether a username exists — an author with nothing published shows the same page as a made-up username, unconditionally, whether or not this plugin is active. **Settings &rsaquo; Security &rsaquo; Lumora Shield** adds one further, optional step on top of that:

- **Hide author archives entirely** (off by default) — every author archive URL is hidden regardless of post count, including real authors who have actually published something. This is the one genuine tradeoff: it removes the public "browse everything by this author" feature, so it's opt-in rather than secure-by-default.

### Monitoring

Every blocked author-archive request is logged at **Maintenance &rsaquo; Logs**: IP address, requested username, reason, and timestamp. Configurable at **Settings &rsaquo; Security &rsaquo; Lumora Shield**:

- **Log blocked enumeration attempts** (on by default) and a **retention period** in days — old rows are cleaned up automatically.
- **Email the site admin on repeated attempts** (off by default) — fires at most once per hour per IP, once a configurable attempt threshold is crossed.

### Comment Analysis

Runs independent content checks (excessive links, excessive uppercase/punctuation, hidden Unicode characters, repeated phrases, extremely short/long comments) and behavioral checks (posting frequency, prior spam history, duplicate content) on every comment — any one tripping pushes the comment toward Spam. This can only push a comment *toward* Spam, never un-spam one another check (or Akismet) already flagged. Toggled independently via **Enable Comment Analysis** at **Settings &rsaquo; Security &rsaquo; Lumora Shield**.

### Contact Form Protection

Applies the same content checks Comment Analysis uses to comments, against every field of a Contact Forms plugin submission — closing the one gap that plugin's own CSRF/honeypot/rate-limiting/CAPTCHA/Akismet protection doesn't cover on its own. Toggled independently via **Enable Contact Form Protection** at **Settings &rsaquo; Security &rsaquo; Lumora Shield**. Only takes effect while the Contact Forms plugin is active.

## Notes

- Building a theme or plugin that wants to hook into Stop User Enumeration or Comment/Contact Form Analysis? See [`docs/DEVELOPER-APIS.md`](../../../docs/DEVELOPER-APIS.md) for the extension points.
