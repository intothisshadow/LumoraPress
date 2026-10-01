# Updates

The full mechanics of the update system — for administrators who want the
details behind the summary in the root [`README.md`](../README.md)'s
Updating section.

Administrators can update Lumora Press from the admin panel under
**Maintenance &rsaquo; Updates** (`/admin/maintenance/updates`), without FTP or SSH access,
either of two ways, each on its own tab (**GitHub**, the default tab, and
**Manual Update**):

- **Check for Updates (GitHub)** — click "Check for Updates" to query the
  GitHub Releases API (configurable repository, optional personal access
  token, and a stable/pre-release channel setting) for the latest release.
  If a newer version is available, "Download & Check" downloads the
  official curated release package, verifies its SHA-256 checksum when the
  release publishes one, and continues into the same review/confirm flow
  as a manual upload. This stays a fully manual, administrator-initiated
  process — nothing downloads or installs automatically.
- **Upload Update Package** — upload an official Lumora Press release ZIP
  directly.

Either way, the package is validated (integrity, structure, version
number) and checked for compatibility (PHP version, required extensions,
disk space, writable directories, connected database server version,
`config/config.php`'s own health, and that the backup destination is
writable with enough free space) before anything is touched. Two further
checks are non-blocking warnings rather than reasons to stop: another user
recently active in the admin area, and a core file that appears to have
been hand-edited since it was last installed and is about to be
overwritten.

1. Review the summary screen — it shows the version change and any
   warnings — then confirm.
2. Lumora Press automatically backs up the core application files and the
   database to `storage/backups/` before applying the update (each backup
   is verified immediately after being written, so a corrupted or
   truncated one is caught before the update proceeds), briefly enables
   maintenance mode for the duration of the update (restored to whatever
   it was set to beforehand once finished — the admin area itself always
   stays reachable), runs any new database migrations, and verifies the
   new version took effect. If anything goes wrong after the backup, it
   automatically restores the files and database from that backup. On a
   site with a large database, the backup step runs in batches across
   several auto-advancing page loads rather than one long request — the
   page keeps advancing on its own, no action needed.

Every download, validation, and install step shows live, step-by-step
progress on the Updates page while it runs (e.g. Backing up files &rarr;
Backing up database &rarr; Applying update files &rarr; Running database
migrations), rather than leaving the page blank until it finishes — a
failed step is shown distinctly from a completed one, so the actual
point of failure stays visible.

Every attempt (success, failure, or rollback) is recorded and listed on
the Updates page, tagged with its source (GitHub or manual). Only the
application code, the bundled Lumora Classic theme, and the bundled plugins (Font
Awesome, Dummy Content, WordPress Importer, Downloads, Contact Forms) are
ever replaced — `config/`, `content/uploads/`, any user-installed plugin,
any theme other than the default, and `storage/` are never touched. If a
future release drops one of those core paths entirely, the old one is
automatically removed too, scoped so it can only ever act on Lumora
Press's own core paths, never anything else on the server.

Every backup pair is also listed in a **Backups** panel on the Updates
page — "Back up now" creates one on demand, independent of running an
actual update — with one-click "Restore" and "Delete" per backup (both
behind a confirmation prompt), plus "Download files"/"Download database"
links for saving a copy off-server. The Updates page also shows the database
schema's migration status and a System status panel (PHP version, ZIP/cURL
availability, file permissions, disk space, and the update staging
directory), reflecting this server's current environment.

If a newer version is available on GitHub, a notice appears on the
**Dashboard** as well as the Updates page — Lumora Press checks
automatically (Dashboard-triggered, not a real server cron job, since none
is required to install Lumora Press) on a schedule you control from the
GitHub Update Settings panel (hourly/daily/weekly, or disabled entirely).
This only ever checks; nothing downloads or installs without an explicit
click.

## Updating a bundled plugin on its own

A plugin that ships with Lumora Press (Font Awesome, Downloads, Contact Forms, and the rest of the bundled set) can be updated without a full Lumora Press update, for when a release fixes only that plugin.

- **Where updates come from.** A GitHub release can carry a separate package for each bundled plugin whose version changed, named `{plugin-folder}-v{version}.zip` with a matching `.sha256` checksum, alongside the usual full Lumora Press package. The same "Check for Updates" that looks for a new Lumora Press release also notes these plugin packages, whether it runs from Maintenance &rsaquo; Updates, the Dashboard's automatic check, or the **Check for Updates** button on the Plugins screen.
- **What you see.** On the **Plugins** screen, each bundled plugin with a newer package shows an "Update available" badge and an **Update** button (in the list, the grid, and the Details panel), and the count of available updates appears above the list. The "Update Available" filter shows only those plugins. Plugins you installed yourself never show an update here; replace them with a new ZIP through Install a Plugin as before.
- **What happens.** Update downloads the plugin's package from the latest release, verifies its checksum, and checks it: it must really be that plugin, it can't be older than what's installed, and its "Requires at least" and "Requires PHP" must be met. A plugin version that needs a newer Lumora Press than this site runs is refused, with a message to update Lumora Press first. A summary screen then shows the version change and any warnings (another user active in the admin, or a file in that plugin edited since it was installed) before you confirm.
- **Applying it.** The same safety steps as a full update run, with live progress on the page: maintenance mode, a backup of the core files and database (labelled with the Lumora Press version), then only that plugin's folder is replaced. There are no database migrations for a plugin-only update, since a bundled plugin's tables ship with Lumora Press itself. If anything fails, the backup is restored automatically. Settings, content, and whether the plugin is active are left as they were.
- **History.** Plugin updates appear in Maintenance &rsaquo; Updates' Update History with the source "Plugin", the plugin's own old and new version numbers, and the plugin's name in the message. Lumora Press keeps its own record of each bundled plugin's installed version in `storage/updates/plugin-versions.json`, kept in step with each plugin's own `Version:` header, including when a full Lumora Press update brings a newer copy of a plugin.

## Updating a bundled theme on its own

The theme that ships with Lumora Press (Lumora Classic) can be updated the same way as a bundled plugin, for when a release fixes only the theme, such as a stylesheet bug or a template tweak.

- **Where updates come from.** A GitHub release can carry a package for each bundled theme whose version changed, named `theme-{theme-folder}-v{version}.zip` with a matching `.sha256` checksum. The `theme-` prefix keeps these apart from plugin packages. The same "Check for Updates" that looks for a new Lumora Press release also notes theme packages, whether it runs from Maintenance &rsaquo; Updates, the Dashboard's automatic check, or the **Check for Updates** button on the Themes screen.
- **What you see.** On **Appearance** &rsaquo; **Themes**, a bundled theme with a newer package shows an "Update available" badge and an **Update** button on its card and in its Details panel, and the count of available updates appears above the list. Themes you installed yourself never show an update here; replace them with a new ZIP as before.
- **What happens.** Update downloads the theme's package from the latest release, verifies its checksum, and checks it: it must really be that theme (a `style.css` with a `Version:` header), it can't be older than what's installed, and its "Requires at least" and "Requires PHP" must be met. A theme version that needs a newer Lumora Press than this site runs is refused, with a message to update Lumora Press first. A summary screen then shows the version change and any warnings before you confirm.
- **Applying it.** The same safety steps as a full update run, with live progress on the page: maintenance mode, a backup of the core files and database, then only that theme's folder is replaced. There are no database migrations for a theme-only update. If anything fails, the backup is restored automatically. Your Theme Options and Customize settings are stored in the database and are left as they were, and the theme keeps working if it is the active one.
- **History.** Theme updates appear in Update History with the source "Theme" and the theme's own old and new version numbers. Lumora Press keeps its own record of each bundled theme's installed version in `storage/updates/theme-versions.json`, kept in step with the theme's `style.css` header, including when a full Lumora Press update brings a newer copy.
- **Interrupted updates.** An unfinished theme update shows up on Maintenance &rsaquo; Updates like any other; **Resume Update** returns to the Themes screen.

## Manual recovery

If the admin panel itself becomes unreachable after a failed update,
restore manually: unzip the most recent `storage/backups/files-*.zip` over
the installation directory, and re-import the most recent database backup
into the database — `storage/backups/db-*.sql.gz` on a server with the
zlib extension (the default), or plain `storage/backups/db-*.sql` on one
without it. A `.sql.gz` file needs decompressing first (`gunzip` or your
hosting file manager's equivalent); both formats are otherwise plain,
human-readable SQL — `storage/` is never web-accessible, so retrieve them
via FTP/SFTP or your hosting file manager.
