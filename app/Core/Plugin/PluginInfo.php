<?php

declare(strict_types=1);

namespace LumoraPress\Core\Plugin;

/**
 * Metadata for one installed plugin, as discovered by PluginRegistry —
 * parsed from its main file's comment header (the classic WordPress
 * "Plugin Name: / Description: / Version: / Author: / Plugin URI: /
 * Author URI: / License: / License URI: / Tags: / Requires at least: /
 * Requires PHP: / Requires Plugins:" convention) plus any preview images
 * and documentation files present in the plugin directory (LP-045).
 */
final class PluginInfo
{
    /**
     * @param array<int, string> $tags
     * @param array<int, string> $requiresPlugins
     * @param array<int, string> $screenshots
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $description,
        public readonly string $version,
        public readonly string $author,
        public readonly string $authorUri,
        public readonly string $pluginUri,
        public readonly string $license,
        public readonly string $licenseUri,
        public readonly string $requiresAtLeast,
        public readonly string $requiresPhp,
        public readonly array $requiresPlugins,
        public readonly array $tags,
        public readonly array $screenshots,
        public readonly ?string $screenshotUrl,
        public readonly string $relativePath,
        public readonly ?string $readmeFile,
        public readonly ?string $changelogFile,
        public readonly bool $isActive,
        public readonly bool $isDisabled,
    ) {
    }
}
