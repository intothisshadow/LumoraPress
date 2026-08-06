<?php

/**
 * Discovers and loads active plugins from content/plugins.
 *
 * @package LumoraPress
 * @subpackage Core
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core;

/**
 * Discovers and loads plugins from content/plugins. Each plugin is a
 * directory containing a file of the same name (content/plugins/foo/foo.php)
 * which registers its hooks via the procedural add_action()/add_filter()
 * bridge (see include/hooks.php) at load time — this class only requires
 * the file, it doesn't touch HookManager directly.
 */
final class PluginManager
{
    /** @var array<int, string> */
    private array $loaded = [];

    public function __construct(
        private readonly string $pluginsPath,
    ) {
    }

    /**
     * @param array<int, string> $activePlugins
     */
    public function loadActive(array $activePlugins): void
    {
        foreach ($activePlugins as $plugin) {
            $this->load($plugin);
        }
    }

    public function load(string $plugin): bool
    {
        $file = rtrim($this->pluginsPath, '/') . '/' . $plugin . '/' . $plugin . '.php';

        if (!is_file($file)) {
            return false;
        }

        require_once $file;
        $this->loaded[] = $plugin;

        return true;
    }

    /**
     * @return array<int, string>
     */
    public function loaded(): array
    {
        return $this->loaded;
    }

    /**
     * Plugin directory names found on disk, regardless of active state.
     *
     * @return array<int, string>
     */
    public function discover(): array
    {
        $dirs = glob(rtrim($this->pluginsPath, '/') . '/*', GLOB_ONLYDIR) ?: [];

        return array_map('basename', $dirs);
    }
}
