# Contact Forms

A lightweight contact form builder for personal sites, blogs, and fansites — a fixed set of field types, one shortcode, and a submissions inbox, without the drag-and-drop builder/CAPTCHA-everywhere/CSV-export scope of a full plugin like Contact Form 7 or WPForms.

## What this plugin does

- **Contact Forms → All Forms**: a list of every form (title, recipient email, field count, submission count, and its `[contact_form id="N"]` shortcode ready to copy).
- **Contact Forms → Add New**: create or edit a form — a title, recipient email (defaults to the site's admin email), success message, optional redirect URL, and a field editor. Fields are added one row at a time from a fixed list — Name, Email, Subject, Message, Text, Textarea, Checkbox, Select — each with a label and a required/optional choice (Select also takes a comma-separated list of options). Add/Remove/Move Up/Move Down are plain buttons, no drag-and-drop; without JavaScript the same rows still submit correctly.
- **Contact Forms → Submissions**: every submission across every form (or filtered to one), with Unread/Spam status tabs, mark-as-read, and delete.
- **Contact Forms → Settings**: optional Google reCAPTCHA and Cloudflare Turnstile keys, plus a checkbox to also check submissions against the site's existing Akismet configuration (Settings &rsaquo; Discussion) — all off by default.
- `[contact_form id="1"]` renders the form anywhere in post/page content.

## Notes

- Every submission is checked by a stack of spam protections before it's saved: a honeypot field and timing check that fail silently, a per-IP flood guard, and — if enabled — reCAPTCHA/Turnstile verification, followed by Akismet. reCAPTCHA/Turnstile are hard gates you explicitly opted into, so an unreachable verification service rejects the submission rather than letting it through; Akismet can only flag a submission as spam for review, matching its existing comment-moderation behavior. The Lumora Shield plugin's Contact Form Protection module adds a further content check on top of this stack — see that plugin's own README.
