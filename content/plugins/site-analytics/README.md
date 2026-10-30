# Site Analytics

Adds a third-party traffic-statistics service to your site without pasting any code. Pick a provider, enter its ID, and the tracking tag is added to every public page for you.

For a counter that uses no outside service at all, use Visitor Stats instead. This plugin is for people who want an external service and accept that trade-off.

## What this plugin does

- **Settings → Analytics**: choose a provider and fill in only the fields it needs. Nothing is added to your pages while the provider is **None** (the default).
- **Plausible**: your site's domain, and optionally the address of your own Plausible server.
- **Fathom**: your site ID.
- **Matomo**: the `https://` address where Matomo is installed, and the site ID.
- **Google Analytics 4**: your measurement ID (it looks like `G-XXXXXXXXXX`).
- **Search engine verification**: optional Google Search Console and Bing Webmaster Tools codes. Paste the code or the whole `<meta>` tag; the tag is added to every public page.
- Lumora Press itself never contacts the provider. Only your visitors' browsers do.

## Privacy

- **Don't count signed-in users** (on by default) keeps your own visits, and your authors' and editors', out of the numbers.
- **Respect Do Not Track** (on by default) leaves out visitors whose browser sends Do Not Track or Global Privacy Control. This is decided in the visitor's browser, so it also works for cached pages.
- The admin, the maintenance page and previews of unpublished posts are never counted.
- Plausible and Fathom do not use cookies. Google Analytics sets cookies and, depending on where your visitors live, may need a consent notice. Matomo's cookie use depends on how your Matomo is set up. Lumora Press does not provide a consent banner.

## What it allows through the Content-Security-Policy

Your site's security policy blocks outside scripts by default. This plugin adds only the selected provider's hosts, and nothing while the provider is None. The Settings → Analytics screen lists exactly what is allowed.

| Provider | Hosts added |
| --- | --- |
| Plausible | `plausible.io`, or the host of your own server's script address |
| Fathom | `cdn.usefathom.com` |
| Matomo | the host of your Matomo address |
| Google Analytics 4 | `www.googletagmanager.com`, `*.google-analytics.com`, `*.analytics.google.com`, `*.googletagmanager.com` |

Only `https://` addresses are accepted.

## Not included

A box for pasting any script or HTML (it would weaken the security policy for every visitor; a one-off script belongs in a small custom plugin), consent banners, and a traffic dashboard inside Lumora Press.
