# Spam Protection

Lumora Press protects comments, logins, and sign-ups with several quiet layers that work together, without CAPTCHAs and without sending anything to an outside service unless you choose to turn one on. This page explains what each layer does, how to handle spam when it gets through, and what is recorded about your visitors.

## How comments are protected

- **Security tokens.** Every form carries a one-time token tied to the visitor's session. A submission without a valid token is refused. Tokens expire after twelve hours and are thrown away when someone signs in or out.
- **Hidden trap fields.** The comment form includes two fields that real visitors never see or fill in. Bots that fill in everything give themselves away. The field names are unique to your site, so a bot can't be written to skip them everywhere. A bot is sent back to the form as if its comment had gone through.
- **Submission timing.** A form submitted within a few seconds of loading is treated as a bot. Optionally (Settings › Security › Comment Flood Protection) a form left open longer than a set number of hours is rejected too, and the visitor is asked to reload the page and try again. Leave this at 0 if your site is cached for a long time.
- **Rate limits.** The same IP address can't post twice within 30 seconds, and by default no more than 20 comments an hour. Both limits are on Settings › Security › Comment Flood Protection, and a visitor who hits one sees a "commenting too quickly" notice.
- **Moderation rules.** A first-time commenter's comment waits for approval, and Settings › Discussion has further rules (held links, keywords, blocked IP addresses).
- **Akismet (optional).** Turn it on under Settings › Security › Spam Protection (Akismet) with your own API key. Comments are then checked by Akismet, and your decisions on the Comments screen are reported back to it. If Akismet can't be reached, the other protections carry on as normal.

## Handling comments that get through

1. Open **Comments**. The filter tabs show Pending, Approved, Spam, and Trash, each with a count.
2. Work through **Pending** first. Approve what's real. Mark the rest as **Spam**. If Akismet is on, marking a comment as Spam, or approving one it called spam, teaches it.
3. Use the checkboxes and the **Bulk actions** menu to approve, unapprove, mark as spam, move to Trash, or delete many comments at once.
4. A comment wrongly marked as spam isn't lost. Open the **Spam** or **Trash** tab and choose **Approve** to restore it.
5. Use **Empty Spam** and **Empty Trash** to clear out what you've reviewed. Deleting permanently can't be undone.

If a real person tells you their comment disappeared, check the Spam and Trash tabs first, then Settings › Security › Comment Flood Protection in case a limit is set too tightly.

## Login protection

Repeated failed logins from one IP address lock that address out for a while. The thresholds are on Settings › Security › Login Lockout. If the same address keeps failing across a day, each new lockout is twice as long as the last, up to 16 times the base length. The attempts are listed under Maintenance › Logs, and the owner of the account being attacked gets an email saying so. A failed login always says only "Invalid username or password", so nobody can learn which usernames exist.

## Registration

Visitors can't sign up unless you turn it on under **Settings › Registration**, and it is off by default. When it is on, the log-in page links to a sign-up form, and the same quiet layers protect it.

- **Nothing becomes an account until the email is confirmed.** A sign-up is kept in a separate waiting list, never in Users, and an email goes out with a link. Following the link and pressing **Confirm My Account** creates the account. A bot that never reads its mail never gets that far, so spam sign-ups don't pile up in your Users list. Unconfirmed sign-ups are deleted after 48 hours without you doing anything.
- **Opening the link isn't enough.** The account is created only when the button on the page is pressed, so an email scanner that opens every link can't confirm an account for someone else.
- **Same security tokens, trap fields, and timing as comments.** A bot that fills in the hidden fields, or submits the form within a few seconds, is told "Check your email" just like a real visitor, and nothing is stored. The attempt appears in the security log.
- **A limit per IP address.** By default three sign-up attempts an hour from one address. Every submission counts, valid or not.
- **A flood breaker.** Bot networks switch addresses, so a limit per address isn't enough. If the total of all attempts in an hour passes a limit (30 by default), sign-ups pause by themselves for an hour, the security log records it, and every administrator gets a notification. **Resume Now** on the Registration screen ends a pause early.
- **Email addresses.** Well-known disposable email services are refused, and you can add domains of your own to refuse, or fill in an allowed list so only your own community's domain can sign up. A real visitor who is refused is told to use a different address.
- **No account enumeration.** The form answers the same way whether or not an address already has an account. Someone who already has one is emailed a reminder with log-in and password-reset links instead of a confirmation link, and at most two emails an hour go to one address, so the form can't be used to mail-bomb someone.
- **Names.** Usernames and display names can't contain links or email addresses, and reserved names like "admin", "support", "staff" and "moderator" are refused so nobody can pose as the site's own people. A username that is already taken is reported, since the visitor has to choose another.
- **Optional extras.** A question only your real readers can answer (a quiet alternative to a CAPTCHA), a check with Akismet, and administrator approval after the email is confirmed. Addresses you block on Settings › Discussion are refused here too.
- **New accounts are held at first.** A new sign-up can comment at once, but for the first 24 hours (you can change it, or switch it off) its comments wait for your approval, even if returning commenters are normally approved automatically. A bot that confirmed a throw-away inbox and waited lands in the Pending queue, not on your site.
- **Only safe roles.** New accounts are Subscribers, or Contributors if you choose, and never a role that can publish.

**Cleaning up.** Users shows a **Registered by** column (Sign-up, Administrator, or Import) with a filter, and the usual Move to Trash bulk action, so sign-ups can be told apart from accounts you added yourself. If approval is on, **Sign-ups Awaiting Approval** appears at the top of Users, and rejecting one sends nothing to the address.

## The security log

Maintenance › Logs › Security Events lists blocked attempts: invalid or expired security tokens, filled-in trap fields, forms submitted too fast, forms open too long, comment rate limits, login lockouts, and sign-ups that were blocked, rate-limited, or paused after a flood. Each entry has a time, the event, an IP address, and a short note. The log never stores comment text, passwords, or security tokens, and it rotates itself at about half a megabyte so it can't fill your disk. You can clear it at any time. An occasional entry is normal, since bots are always probing; a long run from one address is just the system doing its job.

## Privacy

- **Nothing leaves your site by default.** All of the protections above run on your own server. The one optional outside service is Akismet, which is off until you add a key. When it's on, the commenter's name, email, website, comment text, IP address, and browser details are sent to Akismet for checking, so mention it in your privacy policy.
- **IP addresses.** Comments record the commenter's IP address, and the rate limits, lockouts, and security log all use it. Turn on **IP address anonymization** under Settings › Discussion to store only a shortened address (the last part is zeroed). The trade-off is that visitors sharing a shortened address also share the rate limits.
- **How long things are kept.** The security log is kept until you clear it or it rotates. Failed-login records are kept for the lockout rules, and an address's records are wiped when someone logs in successfully from it. Comments, with their IP addresses, stay until deleted. Settings › Privacy can export or erase one person's comment data on request.
- **Lumora Shield's spam log.** If the Lumora Shield plugin is active, each submission it flags is recorded with the author's name, email, and IP address plus the checks that caught it, so you can trace a false positive. Records are removed automatically after the log retention period set on Settings › Security › Lumora Shield (30 days by default). They aren't included in Settings › Privacy's export or erase tools, so keep that period short if you need to honor erasure requests quickly.
- **Sign-ups.** A sign-up that is still waiting for its email confirmation holds the email address, the chosen username, a scrambled copy of the password (never the password itself), and the IP address, for at most 48 hours before it is deleted automatically (two weeks if it is waiting for your approval). A sign-up you reject is deleted at once. An erasure request on Settings › Privacy removes any waiting sign-up for that address too. The count of recent attempts used for the limits keeps the IP address and email address for a day.
- **Cookies.** The protections use the session cookie that every visitor already gets. They set no tracking cookies. See the Cookies section of the README for the full list.
- **Emails.** A login lockout emails only the owner of the account that was targeted, and the message contains the attacker's IP address and nothing else about the attempt.
