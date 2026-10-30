# Migrating from WordPress to Lumora Press

This tutorial walks you through moving an existing WordPress site into Lumora Press, step by step: your posts, pages, comments, categories, tags, images and other media, users, and menus. It takes about 30 minutes of hands-on time for a typical site, plus however long the import itself runs.

The importer is the bundled **WordPress Importer** plugin. It works with any WordPress site running version 3.5 or later, including the current release.

## Before you start

You need:

- A working Lumora Press install. A fresh, empty one is best. See the main README for installation.
- Administrator access to your Lumora Press site.
- A **copy of your WordPress site's `wp-content/uploads` folder** on the same server as Lumora Press. The importer never downloads files from the internet, so your images and other uploads have to be on disk where Lumora Press can read them.
- Either **the WordPress site's database login** or **a WordPress export file** (see Step 1 for which to pick).

It is also wise to take a full backup of the WordPress site first. The import only reads from it, but a backup costs nothing.

## Step 1: Choose how to bring your content over

The importer can read your WordPress site in two ways. Pick one.

**Direct database connection** is the more complete option. It also brings over your real user roles, your site settings (title, tagline, timezone, date format, permalink structure), and your classic widgets. Choose it if you can reach the WordPress database, either on its own server or as a restored backup on this one.

**WordPress export file (WXR `.xml`)** is the easier option when you have no database access, for example when your host only hands you an export. It imports users, categories, tags, media, pages, posts, comments, and menus just as fully. Its limits are that every imported user becomes a Subscriber (you reassign roles afterward), and it carries no site settings or widgets, because WordPress's export format cannot hold them.

If you are not sure, use the export file. It is the simplest to set up.

### If you chose the export file: create it in WordPress

1. Log in to your WordPress admin.
2. Go to **Tools › Export**.
3. Choose **All content** and click **Download Export File**.
4. Save the `.xml` file somewhere safe.

### If you chose the database: find your database details

Open the WordPress site's `wp-config.php` file. You need these values:

- `DB_HOST` (the database host)
- `DB_NAME` (the database name)
- `DB_USER` and `DB_PASSWORD`
- `$table_prefix` (usually `wp_`)

You can skip typing them out. Step 5 shows how the importer reads this file for you.

## Step 2: Copy your files onto the server

Using FTP, SFTP, or your host's file manager, copy these onto the server that runs Lumora Press:

1. **The uploads folder**: the WordPress site's `wp-content/uploads`. This is required for either route, if you want your images and downloads to come across.
2. **Your export file**, if you chose that route.
3. **`wp-config.php`**, if you chose the database route and want the shortcut in Step 5.
4. **`wp-content/gallery`**, only if the old site used the NextGEN Gallery plugin.

Put them in a folder the web server can read. They do not need to be, and ideally are not, inside the public web folder. A good spot is the folder next to your Lumora Press install, for example:

```
/home/you/old-site/wp-config.php
/home/you/old-site/wp-content/uploads/
/home/you/old-site/export.xml
```

Make sure the web server's user can read those files. If the importer later says a folder is not readable, permissions are almost always the reason.

If the WordPress site is on the same server, you may not need to copy anything. The importer can find it for you (Step 5).

## Step 3: Turn on the WordPress Importer plugin

The plugin ships with Lumora Press but is switched off by default.

1. In the Lumora Press admin, open **Plugins**.
2. Find **WordPress Importer** and click **Activate**.

## Step 4: Open the import screen

Go to **Maintenance › Import** and click the **WordPress** tab. (The tab only appears while the plugin is active.)

You will see the **Import from WordPress** panel, with the numbered steps below.

## Step 5: Optional shortcut, fill in from wp-config.php

If you are using the database route, the panel opens with an optional shortcut. Skip this step entirely if you chose the export file.

1. In **Path to wp-config.php**, enter the full path to the copied file, for example `/home/you/old-site/wp-config.php`.
2. Click **Detect from wp-config.php**.

The database host, port, name, username, password, and table prefix fields, plus the uploads folder path, are filled in for you. You can still edit any of them. Lumora Press only reads the text of the file and never runs it.

If the WordPress site lives on the same server, click **Discover wp-config.php** instead. It lists the likely locations (in Lumora Press's own folder, the folder above, and the folders directly inside either). Click one to run the detection in a single step.

## Step 6: Fill in "Where the content comes from"

Under **1 Where the content comes from**:

- Select **Direct database connection** or **WordPress WXR (.xml) export file**.
- For a database connection, check the **Database host**, **Database port**, **Database name**, **Database username**, **Database password**, and **Table prefix** fields. If you used the shortcut in Step 5 they are already filled in.
- For an export file, enter the full path in **Path to WXR (.xml) export file**, for example `/home/you/old-site/export.xml`.

The fields for the route you did not choose are ignored.

## Step 7: Point at the files and test

Under **2 Source files and connection check**:

1. In **Uploads folder path (server filesystem)**, enter the full path to the copied uploads folder, for example `/home/you/old-site/wp-content/uploads`. If the WordPress site is on this server, **Discover Source Files** can fill it in for you.
2. If the old site used NextGEN Gallery, enter the copied folder in **Gallery folder path (server filesystem)**. Otherwise leave it blank.
3. Click **Test Connection**.

Test Connection checks the source and the uploads folder without importing anything. You should see a message that it connected (or that the WXR file is valid) and that the uploads folder is readable. For a database connection it also shows a read-only preview of the old site's settings.

If it fails, the message says what is wrong. See **If something goes wrong** at the end of this page.

## Step 8: Choose what to import

Under **3 What to import**:

**Site settings** (database route only) is off by default. If you tick **Site, Homepage, Reading, Discussion, Media, and Privacy settings**, the old site's values overwrite this site's own settings. Leave it unticked on a site you have already set up the way you like. This option also copies WordPress's Thumbnail, Medium, and Large image sizes into Lumora Press's Small, Medium, and Large thumbnail sizes. See Step 12 for what happens to image sizes.

**Content to import** has a checkbox for each type, all ticked by default:

- Users (authors)
- Categories & tags
- Media (attachments)
- NextGEN Gallery galleries
- Downloads (Simple Download Monitor)
- Pages
- Posts
- Comments (on posts only, not pages)
- Menus
- Widgets

For a first migration, leave everything ticked. Untick a type only if you are certain you do not want it. Note that comments, menus, and the featured images of posts and pages depend on the things they refer to also being imported.

**Import options** has two settings:

- **Delay between stages (milliseconds)**: leave at `0` when the source is a copy on your own server. Only raise it if you are importing from a live, busy shared-hosting site and want to go easy on it.
- **When content already exists**: leave it on the default for a first import. It matters when you import again later. See **Importing again** below.

## Step 9: Do a dry run

Under **4 Run the import**, tick **Dry run (preview only, makes no changes)** and click **Import**.

You get approximate counts for each content type, and nothing is changed. The counts are approximate because a missing file or a malformed row is only discovered during a real import, so the real numbers can land slightly lower.

Check that the numbers look right for your site. If your old site had 400 posts and the preview says 4, something is off with the source.

## Step 10: Run the real import

1. Untick **Dry run**.
2. Click **Import**.

A progress checklist appears showing the current stage: Site Settings, Users, Categories & Tags, Media, NextGEN Gallery, Downloads, Pages, Posts, Comments, Menus, and Widgets, followed by a few final tidy-up stages (updating internal links, regenerating thumbnails, and a verification pass). During the long stages you also see how many items are done out of the total.

**Keep the tab open** while it works. The import runs in short steps rather than one long request, so a host's time limit will not cut it off, but closing the tab pauses it.

### If the import is interrupted

If you lose your connection or close the tab, nothing is lost. Return to **Maintenance › Import › WordPress** and you will be offered:

- **Resume Import**, which continues from exactly where it stopped. The connection details are filled in again; you may need to re-enter the uploads folder path (and the database password, if your install could not store it securely).
- **Discard This Import**, which removes everything the interrupted import created so far.

## Step 11: Read the results

When it finishes, the **WordPress Import Results** panel shows how many of each type were imported, followed by anything that needs your attention:

- **Action needed** lists things that were not carried over and that you will probably want to fix by hand. Typical examples are a gallery or slideshow shortcode that has no direct equivalent, an image that was missing from your uploads folder, or an active WordPress plugin that Lumora Press has no counterpart for.
- **Other items skipped or had a problem** is the longer list of routine notices, such as widget types with no equivalent.
- **Redirect Mapping** lists every redirect the import created, so old links to renamed posts keep working. You can **Download as CSV**.

Read the Action needed list before you do anything else. It is your to-do list for the cleanup.

## Step 12: After the import, your checklist

Work through these once the import is done:

1. **Assign your menus.** Imported menus are created and named but not placed anywhere, because WordPress does not record what a menu location was called. Go to **Appearance › Menus › Manage Locations** and assign each menu to a location.
2. **Tell your users to reset their passwords.** Imported users keep their usernames and emails but get a random password. Everyone, including you, uses **Forgot password?** on the login screen to set a new one. If you used the export-file route, also review each user's role under **Users**, since they all start as Subscribers.
3. **Check your permalinks.** Under **Settings › Permalinks**, make sure the structure suits you. If you imported site settings from a database, the old structure was applied when Lumora Press supports it, otherwise it was skipped and noted in the warnings.
4. **Pick a theme.** WordPress themes do not work in Lumora Press. Choose one under **Appearance › Themes**. Your content comes across as plain HTML, so it works with any theme.
5. **Check your widgets.** Only the common widget types come across. Review **Appearance › Widgets**, and look in the Inactive Widgets list for any that did not match a widget area.
6. **Check your image sizes.** The importer brings over each image's original file only, not the resized copies WordPress made (the ones named like `photo-300x200.jpg`). At the end of the import, Lumora Press makes its own thumbnails from the originals at the sizes set under **Media › Thumbnails**, so images may display at slightly different dimensions than they did on WordPress. If you did not import site settings, compare those sizes with your old ones and adjust them if you like. Images inside your posts still point at the right pictures, because their addresses are updated to match.
7. **Look through your site.** Open a handful of posts, a gallery page, and a post with comments. Check that images show, internal links go to the right place, and comment threads look right.
8. **Work through the Action needed list** from Step 11.

## Importing again

You can import from the same site more than once, for example to pick up posts written since the first import. Use the **When content already exists** setting on the import form:

- **Skip existing content** leaves everything that was already imported alone, and imports only what is new.
- **Overwrite existing content** updates what was imported earlier with the source's current values.

Posts and pages match by the old site's own IDs, not by title or slug, so nothing is duplicated.

If you would rather start over, use **Remove All Imported Content** in the **Previous import** section of the WordPress tab. It deletes exactly what that import created and nothing else, so anything you wrote in Lumora Press yourself is safe. Without Skip or Overwrite, a second import refuses to start while a previous one still exists.

## If something goes wrong

**The WordPress tab is missing.** The plugin is not active. Activate **WordPress Importer** under **Plugins** (Step 3).

**Test Connection says it could not connect.** Check the database host, name, username, and password. Many hosts only allow connections from their own network, so a live remote database often cannot be reached from another server. The reliable fix is to restore a backup of the WordPress database onto a database on the Lumora Press server and connect to that instead, or to use the export file route.

**Test Connection says the uploads folder is not readable.** Check the path is typed exactly, that it points at the `uploads` folder itself, and that the web server's user has permission to read it and everything inside.

**Images are missing after the import.** The files were not in the uploads folder you pointed to, or the folder was an incomplete copy. The Action needed list names any media items whose files were not found. Copy the missing files into the uploads folder, then import again with **Skip existing content**.

**The import stops or the page seems frozen.** Reload **Maintenance › Import › WordPress** and choose **Resume Import**.

**Something imported incorrectly and you want a clean slate.** Use **Remove All Imported Content**, fix the cause, and import again.

## What does not come across

- WordPress themes and plugins. Lumora Press is its own system, so themes and plugins must be written for it.
- Comments on pages (comments on posts do import).
- Widgets and menus created by plugins that have no Lumora Press equivalent.
- Site settings and widgets when you use an export file, because WordPress's export format cannot carry them. Use the database route if you need those.

For everything else the importer can do, see the WordPress Importer plugin's own README.
