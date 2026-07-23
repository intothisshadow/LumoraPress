<?php

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

use RuntimeException;

/**
 * Locates and renders classic PHP theme templates (header.php, footer.php,
 * sidebar.php, index.php, single.php, page.php, archive.php, search.php,
 * 404.php). All public-facing HTML rendering goes through this class.
 */
final class ThemeRenderer
{
    private ?string $activeTheme = null;

    private bool $functionsLoaded = false;

    public function __construct(
        private readonly string $themesPath,
        private readonly string $themesUrl,
    ) {
    }

    public function setActiveTheme(string $theme): void
    {
        $this->activeTheme = $theme;
        $this->functionsLoaded = false;
    }

    public function activeTheme(): ?string
    {
        return $this->activeTheme;
    }

    public function themePath(): string
    {
        if ($this->activeTheme === null) {
            throw new RuntimeException('No active theme has been set.');
        }

        return rtrim($this->themesPath, '/') . '/' . $this->activeTheme;
    }

    public function themeUrl(string $path = ''): string
    {
        if ($this->activeTheme === null) {
            throw new RuntimeException('No active theme has been set.');
        }

        $base = rtrim($this->themesUrl, '/') . '/' . $this->activeTheme;

        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }

    public function locateTemplate(string $template): ?string
    {
        $path = $this->themePath() . '/' . ltrim($template, '/');

        return is_file($path) ? $path : null;
    }

    public function loadFunctions(): void
    {
        if ($this->functionsLoaded) {
            return;
        }

        $file = $this->themePath() . '/functions.php';

        if (is_file($file)) {
            require $file;
        }

        $this->functionsLoaded = true;
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function render(string $template, array $vars = []): void
    {
        $path = $this->locateTemplate($template);

        if ($path === null) {
            throw new RuntimeException("Theme template not found: {$template}");
        }

        $this->includeTemplate($path, $vars);
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function renderPartial(string $partial, array $vars = []): void
    {
        $path = $this->locateTemplate($partial . '.php');

        if ($path === null) {
            return;
        }

        $this->includeTemplate($path, $vars);
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function includeTemplate(string $path, array $vars): void
    {
        extract($vars, EXTR_SKIP);

        require $path;
    }
}
