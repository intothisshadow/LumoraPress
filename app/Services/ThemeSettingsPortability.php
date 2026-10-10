<?php

/**
 * Exports the active theme's Customize settings to a JSON file and imports one back.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.23.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use InvalidArgumentException;
use LumoraPress\Core\Theme\ThemeOptions;

/**
 * Each theme has its own settings, so a file only imports into the theme it came from. Every
 * value is validated exactly as saving the form would, and an import replaces the theme's
 * settings (rather than merging) so the result matches the file. Header images aren't included:
 * their media IDs don't exist on another site.
 */
final class ThemeSettingsPortability
{
    public const FORMAT = 'lumora-press-theme-settings';

    public const FORMAT_VERSION = 1;

    public const MAX_BYTES = 262144;

    public function __construct(private readonly ThemeOptions $options)
    {
    }

    public function filename(): string
    {
        return 'lumora-press-' . $this->options->themeSlug() . '-settings-' . date('Y-m-d') . '.json';
    }

    public function export(): string
    {
        return (string) json_encode([
            'format' => self::FORMAT,
            'version' => self::FORMAT_VERSION,
            'theme' => $this->options->themeSlug(),
            'exported_at' => gmdate('c'),
            'values' => $this->options->exportableValues(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * What an import would do, without doing it.
     *
     * @return array{values: array<string, string>, skipped: array<int, string>}
     * @throws InvalidArgumentException With a message fit to show the administrator.
     */
    public function inspect(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('That file is too large to be a theme settings export.');
        }

        $data = json_decode($json, true);

        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT || !is_array($data['values'] ?? null)) {
            throw new InvalidArgumentException('That file is not a Lumora Press theme settings export.');
        }

        if (($data['version'] ?? null) !== self::FORMAT_VERSION) {
            throw new InvalidArgumentException('That export was made by a different version of Lumora Press and can\'t be read.');
        }

        $theme = is_string($data['theme'] ?? null) ? $data['theme'] : '';

        if ($theme !== $this->options->themeSlug()) {
            throw new InvalidArgumentException(sprintf(
                'These settings are for the "%s" theme, but the active theme is "%s". Activate that theme and import again.',
                $theme === '' ? 'unknown' : $theme,
                $this->options->themeSlug(),
            ));
        }

        $values = [];
        $skipped = [];

        foreach ($data['values'] as $key => $value) {
            $field = is_string($key) ? $this->options->field($key) : null;

            if ($field === null || !is_string($value) || !$this->options->isSupported($field)) {
                $skipped[] = is_string($key) ? $key : '(invalid)';

                continue;
            }

            $valid = $this->options->validate($key, $value);

            if ($valid === null) {
                $skipped[] = $field->label;

                continue;
            }

            $values[$key] = $valid;
        }

        return ['values' => $values, 'skipped' => $skipped];
    }

    /**
     * @return array{imported: int, skipped: array<int, string>}
     * @throws InvalidArgumentException
     */
    public function import(string $json): array
    {
        $plan = $this->inspect($json);
        $this->options->replaceAll($plan['values']);

        return ['imported' => count($plan['values']), 'skipped' => $plan['skipped']];
    }
}
