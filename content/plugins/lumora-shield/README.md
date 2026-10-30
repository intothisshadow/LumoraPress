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

Runs independent content checks (excessive links, excessive uppercase/punctuation, hidden Unicode characters, words that mix Latin and Cyrillic letters, repeated phrases, extremely short/long comments) and behavioral checks (posting frequency, prior spam history, duplicate content) on every comment — any one tripping pushes the comment toward Spam. This can only push a comment *toward* Spam, never un-spam one another check (or Akismet) already flagged. Toggled independently via **Enable Comment Analysis** at **Settings &rsaquo; Security &rsaquo; Lumora Shield**.

### Trusted Senders & Sign-up Rules

At **Settings &rsaquo; Security &rsaquo; Lumora Shield**:

- **Trusted IP addresses** and **trusted email addresses and domains** skip every Lumora Shield check, for regular commenters, co-authors and your own office. One entry per line: an address or range such as `203.0.113.0/24`, a full email address, or a domain (which also covers its subdomains). Akismet and Settings &rsaquo; Discussion still apply. Lines that aren't valid are left out when you save.
- **Disposable email services** (on by default): comments and contact form messages from throw-away inbox services go to Spam, where you can restore them. Sign-ups from these services are already refused.
- **Spam words in sign-up names**: a new sign-up whose username or display name contains one of these words is dropped without telling the visitor why. A starter list is included; edit it or clear it to switch the check off.

### Spam Analysis Log

Every comment or contact form submission Lumora Shield flags is recorded at **Maintenance &rsaquo; Logs &rsaquo; Spam Analysis Log**: time, source, author name and email, IP address, a score (how many checks fired), the checks that caught it, and the action taken. A flagged comment links to its entry in Comments, so if a real message ends up in Spam you can see which check was responsible. Only submissions flagged by Lumora Shield appear here, not ones caught by Akismet or core's own checks. Records follow the same retention period as the enumeration log.

### Dashboard Panel

A **Spam Protection** panel on the Dashboard, shown to anyone who can moderate comments, gives a quick read on how things are going: how many submissions Lumora Shield flagged today, in the last 7 days, and in the last 30 days, the five most-flagged IP addresses over 30 days, the five checks that fired most often, and how many comments are waiting in the moderation queue. The counts come from the Spam Analysis Log, so they only reach back as far as its retention period. Like other Dashboard panels, it can be dragged into a different position.

### Contact Form Protection

Applies the same content checks Comment Analysis uses to comments, against every field of a Contact Forms plugin submission — closing the one gap that plugin's own CSRF/honeypot/rate-limiting/CAPTCHA/Akismet protection doesn't cover on its own. Toggled independently via **Enable Contact Form Protection** at **Settings &rsaquo; Security &rsaquo; Lumora Shield**. Only takes effect while the Contact Forms plugin is active.

## Notes

- Building a theme or plugin that wants to hook into Stop User Enumeration or Comment/Contact Form Analysis? See [`docs/DEVELOPER-APIS.md`](../../../docs/DEVELOPER-APIS.md) for the extension points.
