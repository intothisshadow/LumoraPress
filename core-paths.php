<?php

declare(strict_types=1);

/**
 * The fixed set of paths (relative to the install root) that make up the
 * core application, as distributed in an official release ZIP.
 *
 * Kept standalone so UpdateService::install() can `require` a staged
 * package's own copy of this list before bootstrap.php's require chain
 * would be safe to run against it, and union both lists so a path only
 * the new release knows about is still picked up when updating.
 *
 * Everything else under the install root (config/, content/uploads/,
 * user-installed plugins/themes, storage/) is user data and is never
 * touched by an update.
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
    'content/plugins/wordpress-importer',
    'content/plugins/downloads',
    'content/plugins/contact-forms',
    'content/plugins/lumora-shield',
    'content/plugins/visitor-stats',
    'content/plugins/lumora-gallery-shortcodes',
    'content/plugins/emoji-picker',
    'index.php',
    'version.php',
    '.htaccess',
    'README.md',
    'LICENSE.md',
    'docs',
    'core-paths.php',
];
