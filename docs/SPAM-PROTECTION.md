# Spam Protection

Lumora Press protects comments and logins with several quiet layers that work together, without CAPTCHAs and without sending anything to an outside service unless you choose to turn one on. This page explains what each layer does, how to handle spam when it gets through, and what is recorded about your visitors.

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

## The security log

Maintenance › Logs › Security Events lists blocked attempts: invalid or expired security tokens, filled-in trap fields, forms submitted too fast, forms open too long, comment rate limits, and login lockouts. Each entry has a time, the event, an IP address, and a short note. The log never stores comment text, passwords, or security tokens, and it rotates itself at about half a megabyte so it can't fill your disk. You can clear it at any time. An occasional entry is normal, since bots are always probing; a long run from one address is just the system doing its job.

## Privacy

- **Nothing leaves your site by default.** All of the protections above run on your own server. The one optional outside service is Akismet, which is off until you add a key. When it's on, the commenter's name, email, website, comment text, IP address, and browser details are sent to Akismet for checking, so mention it in your privacy policy.
- **IP addresses.** Comments record the commenter's IP address, and the rate limits, lockouts, and security log all use it. Turn on **IP address anonymization** under Settings › Discussion to store only a shortened address (the last part is zeroed). The trade-off is that visitors sharing a shortened address also share the rate limits.
- **How long things are kept.** The security log is kept until you clear it or it rotates. Failed-login records are kept for the lockout rules, and an address's records are wiped when someone logs in successfully from it. Comments, with their IP addresses, stay until deleted. Settings › Privacy can export or erase one person's comment data on request.
- **Cookies.** The protections use the session cookie that every visitor already gets. They set no tracking cookies. See the Cookies section of the README for the full list.
- **Emails.** A login lockout emails only the owner of the account that was targeted, and the message contains the attacker's IP address and nothing else about the attempt.
