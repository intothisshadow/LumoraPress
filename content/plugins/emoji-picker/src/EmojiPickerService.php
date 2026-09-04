<?php

/**
 * Core logic for the bundled Emoji Picker plugin: settings and the bundled emoji dataset.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.9.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\EmojiPicker;

use LumoraPress\Core\ActiveConfig;

/**
 * Resolves settings and the bundled emoji dataset. Deliberately hook-agnostic so it can be
 * unit tested without booting the full application; hook registration lives in
 * emoji-picker.php. The dataset (data/emoji.php) is a curated subset of Unicode CLDR
 * annotations, extendable via the `lp_emoji_dataset` filter in dataset() below.
 *
 * The dataset is small enough to send to the browser once and filter there, so this class
 * has no AJAX query method; "recently used" persistence is core UserService's concern.
 */
final class EmojiPickerService
{
    private const OPTION_KEY = 'emoji_picker_settings';

    private const DATA_PATH = __DIR__ . '/../data/emoji.php';

    private const DEFAULT_RECENT_LIMIT = 24;

    private static ?self $instance = null;

    /** @var array<int, array{emoji: string, name: string, category: string, keywords: array<int, string>}>|null */
    private ?array $datasetCache = null;

    /** @var array{enabled: bool, wysiwyg: bool, markdown: bool, recent_limit: int, default_category: string}|null */
    private ?array $settingsCache = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * The bundled dataset, normalized and with `lp_emoji_dataset` applied
     * — the hook point a site uses to extend or override it. Loaded fresh
     * (not request-cached beyond $this->datasetCache) since the source
     * file is small and this only ever runs on an admin editor screen,
     * not on every public page load.
     *
     * @return array<int, array{emoji: string, name: string, category: string, keywords: array<int, string>}>
     */
    public function dataset(): array
    {
        if ($this->datasetCache !== null) {
            return $this->datasetCache;
        }

        $raw = is_file(self::DATA_PATH) ? (require self::DATA_PATH) : [];
        $normalized = $this->normalizeDataset(is_array($raw) ? $raw : []);

        /** @var array<int, array{emoji: string, name: string, category: string, keywords: array<int, string>}> $filtered */
        $filtered = apply_filters('lp_emoji_dataset', $normalized);

        $this->datasetCache = $this->normalizeDataset($filtered);

        return $this->datasetCache;
    }

    /**
     * @return list<string> category names, in the dataset's own first-seen order
     */
    public function categories(): array
    {
        $categories = [];

        foreach ($this->dataset() as $entry) {
            if (!in_array($entry['category'], $categories, true)) {
                $categories[] = $entry['category'];
            }
        }

        return $categories;
    }

    /**
     * @param array<int, mixed> $rawEntries
     * @return array<int, array{emoji: string, name: string, category: string, keywords: array<int, string>}>
     */
    private function normalizeDataset(array $rawEntries): array
    {
        $normalized = [];

        foreach ($rawEntries as $entry) {
            if (!is_array($entry) || !isset($entry['emoji'], $entry['name'], $entry['category'])) {
                continue;
            }

            $normalized[] = [
                'emoji' => (string) $entry['emoji'],
                'name' => (string) $entry['name'],
                'category' => (string) $entry['category'],
                'keywords' => is_array($entry['keywords'] ?? null) ? array_values(array_map('strval', $entry['keywords'])) : [],
            ];
        }

        return $normalized;
    }

    /**
     * @return array{enabled: bool, wysiwyg: bool, markdown: bool, recent_limit: int, default_category: string}
     */
    public function settings(): array
    {
        if ($this->settingsCache !== null) {
            return $this->settingsCache;
        }

        $stored = ActiveConfig::instance()->option(self::OPTION_KEY, null);
        $decoded = is_string($stored) ? json_decode($stored, true) : null;

        $defaults = [
            'enabled' => true,
            'wysiwyg' => true,
            'markdown' => true,
            'recent_limit' => self::DEFAULT_RECENT_LIMIT,
            'default_category' => '',
        ];

        $settings = array_merge($defaults, is_array($decoded) ? $decoded : []);

        $settings = [
            'enabled' => (bool) $settings['enabled'],
            'wysiwyg' => (bool) $settings['wysiwyg'],
            'markdown' => (bool) $settings['markdown'],
            'recent_limit' => max(0, min(100, (int) $settings['recent_limit'])),
            'default_category' => trim((string) $settings['default_category']),
        ];

        $this->settingsCache = $settings;

        return $settings;
    }

    /**
     * @param array{enabled: bool, wysiwyg: bool, markdown: bool, recent_limit: int, default_category: string} $settings
     */
    public function saveSettings(array $settings): void
    {
        ActiveConfig::instance()->setOption(self::OPTION_KEY, json_encode($settings));
        $this->settingsCache = null;
    }

    public function isEnabled(): bool
    {
        return $this->settings()['enabled'];
    }

    /**
     * Whether the picker button should render for a given editor —
     * 'wysiwyg' (TinyMCE, also covers the ticket's "HTML editor mode",
     * since this codebase's ContentFormat::Html *is* the TinyMCE
     * instance — there is no separate raw-source editor) or 'markdown'
     * (EasyMDE). Always false when the picker is disabled entirely.
     */
    public function editorEnabled(string $editor): bool
    {
        $settings = $this->settings();

        if (!$settings['enabled']) {
            return false;
        }

        return match ($editor) {
            'wysiwyg' => $settings['wysiwyg'],
            'markdown' => $settings['markdown'],
            default => false,
        };
    }

    public function recentLimit(): int
    {
        return $this->settings()['recent_limit'];
    }

    public function defaultCategory(): string
    {
        $configured = $this->settings()['default_category'];

        if ($configured !== '' && in_array($configured, $this->categories(), true)) {
            return $configured;
        }

        return $this->categories()[0] ?? '';
    }
}
