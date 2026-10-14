<?php

/**
 * Collects scripts enqueued by core, plugins, and themes and prints them in the head or footer with the site's defer setting applied.
 *
 * @package LumoraPress
 * @subpackage Themes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.24.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

/**
 * Registered on `head_assets` and `footer_assets`, so a theme needs no markup of its own
 * for enqueued scripts. Only external files are supported: the Content-Security-Policy has
 * no `unsafe-inline` for scripts, so an inline script would be blocked anyway.
 */
final class ScriptQueue
{
    private static bool $deferByDefault = false;

    private static bool $footerByDefault = true;

    private static bool $headPrinted = false;

    /**
     * `footer` and `defer` stay null until printing when the caller gave no preference, since a
     * plugin can enqueue before the site's settings have been applied.
     *
     * @var array<string, array{src: string, deps: array<int, string>, footer: ?bool, defer: ?bool, async: bool, module: bool, attributes: array<string, string>}>
     */
    private static array $scripts = [];

    /**
     * @var array<string, true>
     */
    private static array $printed = [];

    public static function configure(bool $deferByDefault, bool $footerByDefault): void
    {
        self::$deferByDefault = $deferByDefault;
        self::$footerByDefault = $footerByDefault;
    }

    public static function reset(): void
    {
        self::$deferByDefault = false;
        self::$footerByDefault = true;
        self::$headPrinted = false;
        self::$scripts = [];
        self::$printed = [];
    }

    /**
     * Options: `deps` (handles that must print first), `in_footer`, `defer`, `async`,
     * `module` (each defaulting to the Settings > Performance choice), and `attributes`
     * (extra attributes such as `crossorigin`). Enqueuing a handle twice keeps the first.
     *
     * @param array{deps?: array<int, string>, in_footer?: bool, defer?: bool, async?: bool, module?: bool, attributes?: array<string, string>} $options
     */
    public static function enqueue(string $handle, string $src, array $options = []): void
    {
        if ($handle === '' || $src === '' || isset(self::$scripts[$handle])) {
            return;
        }

        $module = ($options['module'] ?? false) === true;
        $async = ($options['async'] ?? false) === true;

        self::$scripts[$handle] = [
            'src' => $src,
            'deps' => array_values(array_map('strval', $options['deps'] ?? [])),
            // The head has already been sent once it is printed, so a late request can only go to the footer.
            'footer' => self::$headPrinted ? true : ($options['in_footer'] ?? null),
            'defer' => $options['defer'] ?? null,
            'async' => $async,
            'module' => $module,
            'attributes' => $options['attributes'] ?? [],
        ];
    }

    /**
     * A bare `<script src>` for markup core prints itself, with the site-wide defer choice
     * applied. Use enqueue() instead where a plugin or theme is the caller.
     */
    public static function tag(string $src, bool $module = false): string
    {
        $flags = $module ? ' type="module"' : (self::$deferByDefault ? ' defer' : '');

        return '<script' . $flags . ' src="' . esc_url($src) . '"></script>' . "\n";
    }

    public static function renderHead(): void
    {
        self::$headPrinted = true;
        self::print(false);
    }

    public static function renderFooter(): void
    {
        // A plugin that never saw the head hook fire still gets its scripts out.
        self::print(false);
        self::print(true);
    }

    private static function print(bool $footer): void
    {
        foreach (array_keys(self::$scripts) as $handle) {
            self::printHandle($handle, $footer, []);
        }
    }

    /**
     * @param array<int, string> $trail handles currently being resolved, to stop a dependency loop
     */
    private static function printHandle(string $handle, bool $footer, array $trail): void
    {
        if (isset(self::$printed[$handle]) || !isset(self::$scripts[$handle]) || in_array($handle, $trail, true)) {
            return;
        }

        $script = self::$scripts[$handle];
        $script['footer'] ??= self::$footerByDefault;
        // Modules defer on their own and async scripts must run as soon as they arrive.
        $script['defer'] = !$script['module'] && !$script['async'] && ($script['defer'] ?? self::$deferByDefault);

        // A head script that depends on a footer script can't run before it, so it moves down.
        if (!$script['footer'] && self::dependsOnFooter($script['deps'])) {
            self::$scripts[$handle]['footer'] = true;
            $script['footer'] = true;
        }

        if ($script['footer'] !== $footer) {
            return;
        }

        foreach ($script['deps'] as $dependency) {
            self::printHandle($dependency, $footer, [...$trail, $handle]);
        }

        self::$printed[$handle] = true;

        $attributes = '';

        foreach ($script['attributes'] as $name => $value) {
            if (preg_match('/^[a-z][a-z0-9-]*$/', $name) === 1 && !in_array($name, ['src', 'defer', 'async', 'type'], true)) {
                $attributes .= ' ' . $name . '="' . esc_attr($value) . '"';
            }
        }

        echo '<script'
            . ($script['module'] ? ' type="module"' : '')
            . ($script['defer'] ? ' defer' : '')
            . ($script['async'] ? ' async' : '')
            . $attributes
            . ' src="' . esc_url($script['src']) . '"></script>' . "\n";
    }

    /**
     * @param array<int, string> $dependencies
     */
    private static function dependsOnFooter(array $dependencies): bool
    {
        foreach ($dependencies as $dependency) {
            if (isset(self::$scripts[$dependency]) && (self::$scripts[$dependency]['footer'] ?? self::$footerByDefault) && !isset(self::$printed[$dependency])) {
                return true;
            }
        }

        return false;
    }
}
