# Contact Forms

A lightweight contact form builder for personal sites, blogs, and
fansites — a fixed set of field types, one shortcode, and a submissions
inbox, without the drag-and-drop builder/CAPTCHA-everywhere/CSV-export
scope of a full plugin like Contact Form 7 or WPForms.

## What this plugin does

- **Contact Forms → All Forms**: a list of every form (title, recipient
  email, field count, submission count, and its `[contact_form id="N"]`
  shortcode ready to copy).
- **Contact Forms → Add New**: create or edit a form (via `?id=`) — a
  title, recipient email (defaults to the site's admin email), success
  message, optional redirect URL, and a field editor. Fields are added
  one row at a time from a fixed list — Name, Email, Subject, Message,
  Text, Textarea, Checkbox, Select — each with a label and a
  required/optional choice (Select also takes a comma-separated list of
  options). Add/Remove/Move Up/Move Down are plain buttons, no
  drag-and-drop; without JavaScript the same rows still submit correctly
  (a blank trailing row is always available to fill in), matching this
  admin's existing Custom Fields editor.
- **Contact Forms → Submissions**: every submission across every form
  (or filtered to one), with Unread/Spam status tabs, mark-as-read, and
  delete.
- **Contact Forms → Settings**: optional Google reCAPTCHA and Cloudflare
  Turnstile keys, plus a checkbox to also check submissions against the
  site's existing Akismet configuration (Settings &rsaquo; Discussion) —
  all off by default.
- `[contact_form id="1"]` renders the form anywhere in post/page content.

## How it's built

A form's fields are stored as one JSON column (`contact_forms.fields`),
not a separate fields table — a handful of fields per form doesn't
justify the complexity of an EAV schema, the same reasoning
`widgets_config`'s single JSON option already establishes elsewhere in
this codebase. A submission's stored data is keyed by each field's stable
slug (derived from its label at save time), not its display label, so
renaming a field later never orphans historic submissions.

Spam protection layers exactly like comment submission already does in
core (`SiteController`): a honeypot field and submission-timing check
(`FormTiming`) fail silently, a per-IP flood guard rejects with a visible
message, and — if enabled — reCAPTCHA/Turnstile verification runs before
Akismet. reCAPTCHA/Turnstile are hard gates a site owner explicitly opted
into, so an unreachable verification service **rejects** the submission
(fails closed); Akismet, reused from core rather than reconfigured here,
can only flag a submission as spam for an admin to review, never block it
outright (fails open), matching its existing comment-moderation behavior.
Right before persisting, submissions also run through a
`contact_form_is_spam` filter (`bool $isSpam, array<string, string> $data,
string $ipAddress`) — a no-op unless something listens, the same
can-only-push-toward-Spam shape core's own `comment_is_spam` filter
already establishes for comments. The Lumora Shield plugin's Contact Form
Protection module is its first real listener, covering the one gap this
plugin's own stack above doesn't (link-count/length/uppercase-ratio
content checks).

Because classic PHP theme templates in this codebase echo directly rather
than buffering the whole page, a shortcode can't safely `header()`
redirect from inside its own rendering callback by the time it runs. The
actual submission POST goes to a small, dedicated
`/contact-form/{id}/submit` route registered directly in
`include/bootstrap.php` (gated on this plugin being active) — the same
shape comment submission already uses, and the same kind of
plugin-specific core wiring the Downloads plugin's own admin menu entry
already established as this project's precedent for "no generic
registration hook exists yet."

## Deferred

Cut from a much larger original spec to keep this a lean v1: drag-and-drop
field ordering, form templates/duplicate, a file upload field, CSV/JSON
export, submission search/archive, AJAX submission, TinyMCE/EasyMDE
toolbar buttons, a developer API for custom field types, and HTML email
templates/SMTP settings. See `DECISIONS.md` for the reasoning.
