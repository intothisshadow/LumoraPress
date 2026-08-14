<?php

declare(strict_types=1);

/**
 * The fixed set of paths (relative to the install root) that make up the
 * core application, as distributed in an official release ZIP.
 *
 * Kept as its own standalone, side-effect-free file (rather than an array
 * literal inline in include/bootstrap.php, where it lived until this file
 * was split out) specifically so UpdateService::install() can read a
 * newly-uploaded package's own copy of this list — via a plain `require`
 * against the staged, not-yet-installed files, before bootstrap.php's much
 * larger require chain would be safe to run against them. Without that,
 * updating a site meant using only the *currently-running* (old) code's
 * corePaths list to decide what to overlay from the new package, so a
 * path this list only just gained (a new bundled plugin, most recently
 * content/plugins/dummy-content) could never actually reach a site
 * updating from a version older than the release that added it — the old
 * code simply never knew to look for it. UpdateService now unions this
 * file's list from the *staged* package with the currently-running one,
 * closing that gap. See UpdateService::resolveEffectiveCorePaths().
 *
 * Everything else under the install root — config/, content/uploads/, the
 * rest of content/plugins/ (any plugin the administrator installed
 * themselves), custom themes other than "default", and storage/ — is user
 * data and is never touched by a manual or automatic update.
 * content/themes/default and the bundled first-party plugins
 * (content/plugins/font-awesome, content/plugins/dummy-content) get the
 * same "bundled, not user-installed" treatment. docs/ (CHANGELOG.md/
 * HISTORY.md/TROUBLESHOOTING.md) ships with every release, unlike the
 * user-data paths above.
 *
 * @return array<int, string>
 */
return [
    'app',
    'admin',
    'assets',
    'include',
    'install',
    'content/themes/default',
    'content/plugins/font-awesome',
    'content/plugins/dummy-content',
    'index.php',
    'version.php',
    '.htaccess',
    'README.md',
    'LICENSE.md',
    'docs',
    'core-paths.php',
];
