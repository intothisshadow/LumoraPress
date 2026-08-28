# Visitor & Post View Statistics

A local, privacy-respecting page-view counter for the Dashboard, with
optional country/referrer/browser/device breakdowns — the kind of "X
views today, most-viewed posts this week" panel classic WordPress
dashboards commonly showed via a simple stats plugin, not a full
analytics platform.

## What it does

- Counts a "view" once per guest (logged-out) request to a single post.
  An already-logged-in visitor — including you, previewing your own
  post — never counts.
- Shows a "Site Visitors" panel on the Dashboard: today / this week /
  this month / all-time totals, plus a Most Viewed (7 days) list.
- Optionally breaks those views down by country, referring domain,
  browser, and device type over the last 7 days.
- Off by default. Activating this plugin does not start collecting
  data on its own — turn on **Visitor Stats → Settings → Track post
  views** first.

## What it never does

- No outbound network request of any kind, including for country
  lookups (see "Country breakdown" below).
- No cookies, no sessions, no unique-visitor tracking.
- No raw IP address is ever stored — only used in-memory, for the
  one-shot country lookup, then discarded.
- No raw User-Agent string is ever stored — only two resolved buckets
  (browser family, device type).
- No full referrer URL is ever stored — only the referring domain, and
  only for external referrers (this site's own domain doesn't count as
  a "referrer").
- Every table this plugin creates stores day-level aggregate counts
  only. No row anywhere corresponds to one visitor or one request, so
  no query against this data can reconstruct what any individual
  visitor did.

## Country breakdown (optional)

Resolving a visitor's country needs a geo-IP database. This plugin
does **not** bundle one — MaxMind's GeoLite2 data requires a free
account, and redistributing it is the account holder's own choice to
make, not something to ship pre-loaded.

To enable it:

1. Create a free account at
   [maxmind.com/en/geolite2/signup](https://www.maxmind.com/en/geolite2/signup).
2. Download **GeoLite2 Country**, in the **CSV** format (not the
   `.mmdb` binary format).
3. Unzip it — you'll get `GeoLite2-Country-Blocks-IPv4.csv` and
   `GeoLite2-Country-Locations-en.csv`, among other files.
4. Upload those two files at **Visitor Stats → Settings**.

This plugin parses both files once and loads a compact
network-range-to-country table into your database; it doesn't keep the
uploaded CSVs around beyond that (they're saved to `storage/geoip/`,
outside the web root, so re-importing later with a newer download is
just re-uploading). If you never do this, the country breakdown simply
stays empty — every other breakdown works fine without it.

v1 only resolves IPv4 addresses; an IPv6 visitor's view doesn't get a
country.

GeoLite2 data is © MaxMind, used under MaxMind's own GeoLite2 End User
License Agreement — see MaxMind's site for current terms. This plugin
only reads whatever file you provide from your own MaxMind account; it
does not distribute MaxMind's data itself.

## Settings

| Setting | Description |
| --- | --- |
| Track post views | Master toggle. Off by default. Also gates every breakdown below — there's no separate opt-out for those alone. |
| GeoLite2 CSV upload | Optional. See "Country breakdown" above. |
