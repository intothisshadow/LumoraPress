<?php

/**
 * The Theme Options system (LP-034): themes and plugins register configurable settings surfaced on the Appearance admin screen.
 *
 * @package LumoraPress
 * @subpackage Themes
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

use LumoraPress\Core\PressConfig;

/**
 * LP-034's Theme Options system (originally scoped as the standalone
 * LP-023 ticket — see DECISIONS.md's "LP-023 merged into LP-034" entry).
 *
 * Sections and fields are registered once per request — core's own
 * standard Colors/Typography/Layout options via registerStandardOptions()
 * (called from include/bootstrap.php), plus anything a theme or plugin
 * adds by hooking `add_action('register_theme_options', function
 * (ThemeOptions $options) { ... })`.
 *
 * Values are scoped per active theme (LP-123): each theme's values live
 * under their own `theme_options_{slug}` option (a JSON map of key =>
 * string value, the same "structured value as JSON" convention
 * widgets_config/nav_menus already use), keyed by $activeThemeSlug at
 * construction time. Switching the active theme genuinely switches which
 * values are shown/editable — each theme keeps its own independent set,
 * so e.g. an Accent Color chosen while duskline is active has no effect
 * once xena-central is activated. (Before LP-123, all sites shared one flat
 * `theme_options` option regardless of active theme; migration
 * 0054_migrate_theme_options_to_active_theme.sql carries any pre-existing
 * global values over to whichever theme was active at upgrade time.)
 *
 * Values are always plain strings — Checkbox stores '1'/'0', Number
 * stores a numeric string, Color stores a '#rrggbb' hex string or '' for
 * "inherit the active theme's own default" (see ThemeOptionField's
 * $allowEmpty docblock for why Colors specifically need that empty state
 * and Typography/Layout don't).
 */
final class ThemeOptions
{
    /** @var array<string, ThemeOptionSection> */
    private array $sections = [];

    /** @var array<string, ThemeOptionField> */
    private array $fields = [];

    /** @var array<string, string>|null */
    private ?array $values = null;

    public function __construct(
        private readonly PressConfig $config,
        private readonly string $activeThemeSlug = 'default',
    ) {
    }

    private function optionKey(): string
    {
        return 'theme_options_' . $this->activeThemeSlug;
    }

    public function registerSection(string $key, string $label, string $description = ''): void
    {
        $this->sections[$key] = new ThemeOptionSection($key, $label, $description);
    }

    public function registerField(ThemeOptionField $field): void
    {
        $this->fields[$field->key] = $field;
    }

    /**
     * Core's own built-in options — called once from bootstrap, before
     * the active theme's functions.php gets a chance (via the
     * 'register_theme_options' action) to register more. Covers Colors,
     * Typography, Layout, Post Display (LP-034/LP-079), and Header/
     * Welcome Message/Footer (LP-123, for the Appearance > Customize
     * screen's tabs of the same name); Homepage, Blog, and Images remain
     * deferred — see TODO.md's LP-034 for the exact remaining checklist.
     */
    public function registerStandardOptions(): void
    {
        $this->registerSection('colors', 'Colors', 'Overrides the active theme\'s default color palette. Leave a color set to "Use theme default" to keep the theme\'s own choice, including its dark-mode variant.');

        $this->registerField(new ThemeOptionField(
            key: 'accent_color',
            section: 'colors',
            type: ThemeOptionType::Color,
            label: 'Accent color',
            cssVariable: '--lp-accent',
            help: 'Used for links, buttons, and highlighted elements.',
            allowEmpty: true,
            previewDefault: '#2271b1',
        ));
        $this->registerField(new ThemeOptionField(
            key: 'text_color',
            section: 'colors',
            type: ThemeOptionType::Color,
            label: 'Text color',
            cssVariable: '--lp-text',
            allowEmpty: true,
            previewDefault: '#1d2327',
        ));
        $this->registerField(new ThemeOptionField(
            key: 'text_muted_color',
            section: 'colors',
            type: ThemeOptionType::Color,
            label: 'Muted text color',
            help: 'Used for dates, metadata, and secondary text.',
            cssVariable: '--lp-text-muted',
            allowEmpty: true,
            previewDefault: '#646970',
        ));
        $this->registerField(new ThemeOptionField(
            key: 'background_color',
            section: 'colors',
            type: ThemeOptionType::Color,
            label: 'Background color',
            cssVariable: '--lp-bg',
            allowEmpty: true,
            previewDefault: '#ffffff',
        ));
        $this->registerField(new ThemeOptionField(
            key: 'background_alt_color',
            section: 'colors',
            type: ThemeOptionType::Color,
            label: 'Alternate background color',
            help: 'Used for the header, footer, and card backgrounds.',
            cssVariable: '--lp-bg-alt',
            allowEmpty: true,
            previewDefault: '#f7f7f8',
        ));
        $this->registerField(new ThemeOptionField(
            key: 'border_color',
            section: 'colors',
            type: ThemeOptionType::Color,
            label: 'Border color',
            cssVariable: '--lp-border',
            allowEmpty: true,
            previewDefault: '#dcdcde',
        ));

        $this->registerSection('typography', 'Typography', 'Controls the fonts and text sizing used across every public page.');

        $this->registerField(new ThemeOptionField(
            key: 'body_font',
            section: 'typography',
            type: ThemeOptionType::Select,
            label: 'Body font',
            default: 'serif',
            cssVariable: '--lp-font-body',
            choices: [
                'serif' => 'Serif (Georgia)',
                'sans' => 'Sans-serif',
                'system' => 'System UI',
                'mono' => 'Monospace',
            ],
            cssValueMap: [
                'serif' => 'Georgia, "Times New Roman", serif',
                'sans' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif',
                'system' => 'system-ui, sans-serif',
                'mono' => '"SF Mono", Consolas, "Courier New", monospace',
            ],
        ));
        $this->registerField(new ThemeOptionField(
            key: 'base_font_size',
            section: 'typography',
            type: ThemeOptionType::Number,
            label: 'Base font size (px)',
            default: '16',
            cssVariable: '--lp-font-size-base',
            min: 14,
            max: 22,
            cssUnit: 'px',
        ));
        $this->registerField(new ThemeOptionField(
            key: 'line_height',
            section: 'typography',
            type: ThemeOptionType::Select,
            label: 'Line height',
            default: '1.6',
            cssVariable: '--lp-line-height-base',
            choices: [
                '1.4' => 'Compact',
                '1.6' => 'Default',
                '1.8' => 'Relaxed',
            ],
        ));
        $this->registerField(new ThemeOptionField(
            key: 'google_fonts_url',
            section: 'typography',
            type: ThemeOptionType::Url,
            label: 'Google Fonts URL',
            help: 'Paste a stylesheet URL from fonts.google.com ("Get font" → "Get embed code" → the <link href="..."> value). Only fonts.googleapis.com URLs are accepted.',
            allowEmpty: true,
            allowedHosts: ['fonts.googleapis.com'],
        ));
        $this->registerField(new ThemeOptionField(
            key: 'google_fonts_family',
            section: 'typography',
            type: ThemeOptionType::Text,
            label: 'Google Fonts font family',
            cssVariable: '--lp-font-body',
            help: 'The font-family value from the same embed code, e.g. \'Inter\', sans-serif — overrides the Body font selection above when set. Has no effect without a Google Fonts URL above.',
            allowEmpty: true,
        ));

        $this->registerSection('layout', 'Layout', 'Controls the width of the site\'s header, content, and footer.');

        $this->registerField(new ThemeOptionField(
            key: 'content_width',
            section: 'layout',
            type: ThemeOptionType::Select,
            label: 'Content width',
            default: '',
            cssVariable: '--lp-max-width',
            help: 'Leave set to "Use theme default" to keep the active theme\'s own chosen width (e.g. duskline\'s own 1160px) — only pick a specific width here to force every theme to that same value regardless of its own design.',
            choices: [
                '' => 'Use theme default',
                '720px' => 'Narrow',
                '960px' => 'Default',
                '1200px' => 'Wide',
                'none' => 'Full width',
            ],
            allowEmpty: true,
        ));

        // LP-079: kept as its own section rather than folded into Layout
        // above — that section controls page *width*, an unrelated
        // concern from how much of each post's content a listing shows.
        // None of these fields carry a $cssVariable: they're read
        // directly via theme_option() in content/themes/default/index.php
        // and archive.php to decide what to render, not injected as CSS.
        $this->registerSection('post_display', 'Post Display', 'Controls how posts appear on the front page and category/tag/date/author archives. Single-post pages always show the full post regardless of these settings.');

        $this->registerField(new ThemeOptionField(
            key: 'post_display_mode',
            section: 'post_display',
            type: ThemeOptionType::Select,
            label: 'Post display',
            default: 'excerpt',
            choices: [
                'excerpt' => 'Excerpt',
                'full' => 'Full Content',
            ],
            help: 'Excerpt shows a preview with a Read More link; Full Content shows the entire post inline with no Read More link. A post with its own Read More tag in the content editor always cuts off there and shows a Read More link, regardless of this setting.',
        ));
        $this->registerField(new ThemeOptionField(
            key: 'show_featured_image_in_listings',
            section: 'post_display',
            type: ThemeOptionType::Checkbox,
            label: 'Show featured image',
            default: '1',
            help: 'Show each post\'s featured image in front-page/archive listings, when it has one.',
        ));
        $this->registerField(new ThemeOptionField(
            key: 'excerpt_length',
            section: 'post_display',
            type: ThemeOptionType::Number,
            label: 'Automatic excerpt length (words)',
            default: '55',
            min: 1,
            max: 300,
            help: 'Used only when a post has no manually entered excerpt and no More tag in its content.',
        ));
        $this->registerField(new ThemeOptionField(
            key: 'read_more_text',
            section: 'post_display',
            type: ThemeOptionType::Text,
            label: 'Read More text',
            default: 'Continue reading →',
        ));

        // LP-123: Header/Welcome Message/Footer, registered for the new
        // Appearance > Customize screen's tabs of the same name. header_image
        // itself is deliberately NOT a ThemeOptionField — a file upload
        // doesn't fit this class's string-in/string-out sanitize() contract
        // — see headerImageMediaId()/setHeaderImageMediaId() below instead.
        $this->registerSection('header', 'Header', 'Controls what appears in the site header above the navigation.');

        $this->registerField(new ThemeOptionField(
            key: 'show_site_title',
            section: 'header',
            type: ThemeOptionType::Checkbox,
            label: 'Show site title',
            default: '1',
            help: 'Show the site title/logo in the header. Turn off if your header image already includes the title.',
        ));
        $this->registerField(new ThemeOptionField(
            key: 'header_height',
            section: 'header',
            type: ThemeOptionType::Number,
            label: 'Header image height (px)',
            default: '200',
            cssVariable: '--lp-header-image-height',
            min: 50,
            max: 800,
            cssUnit: 'px',
            help: 'Only applies when a header image is set below.',
        ));

        $this->registerSection('welcome_message', 'Welcome Message', 'An optional message shown near the top of your site.');

        $this->registerField(new ThemeOptionField(
            key: 'welcome_message',
            section: 'welcome_message',
            type: ThemeOptionType::Html,
            label: 'Welcome message',
            default: '',
        ));
        $this->registerField(new ThemeOptionField(
            key: 'welcome_message_format',
            section: 'welcome_message',
            type: ThemeOptionType::Select,
            label: 'Welcome message editor',
            default: 'html',
            choices: [
                'html' => 'Visual/HTML',
                'markdown' => 'Markdown',
                'plain' => 'Plain text',
            ],
        ));
        $this->registerField(new ThemeOptionField(
            key: 'welcome_message_placement',
            section: 'welcome_message',
            type: ThemeOptionType::Select,
            label: 'Placement',
            default: 'header',
            choices: [
                'header' => 'Below the header',
                'sidebar' => 'In the sidebar',
            ],
        ));

        // footer_html is deliberately separate from the fixed "Powered by
        // Lumora Press" attribution every theme's footer.php prints (see
        // powered_by_html()) — this is additional, per-theme footer
        // content, not a replacement for it.
        $this->registerSection('footer', 'Footer', 'Additional footer content, shown alongside the "Powered by Lumora Press" line.');

        $this->registerField(new ThemeOptionField(
            key: 'footer_html',
            section: 'footer',
            type: ThemeOptionType::Html,
            label: 'Footer content',
            default: '',
        ));
        $this->registerField(new ThemeOptionField(
            key: 'footer_html_format',
            section: 'footer',
            type: ThemeOptionType::Select,
            label: 'Footer content editor',
            default: 'html',
            choices: [
                'html' => 'Visual/HTML',
                'markdown' => 'Markdown',
                'plain' => 'Plain text',
            ],
        ));
    }

    /**
     * Header image is stored as a reserved key inside the same per-theme
     * values blob rather than as a ThemeOptionField (file uploads don't fit
     * the field system's string-in/string-out sanitize() contract) — this
     * also means Reset Section/Reset Everything on the 'header' section
     * clears it for free, since it lives in the same persisted array.
     */
    public function headerImageMediaId(): int
    {
        return (int) ($this->loadValues()['header_image_media_id'] ?? 0);
    }

    public function setHeaderImageMediaId(int $mediaId): void
    {
        $values = $this->loadValues();
        $values['header_image_media_id'] = (string) $mediaId;
        $this->persist($values);
    }

    public function removeHeaderImage(): void
    {
        $values = $this->loadValues();
        unset($values['header_image_media_id']);
        $this->persist($values);
    }

    /**
     * @return array<string, ThemeOptionSection> keyed by section key, in registration order
     */
    public function sections(): array
    {
        return $this->sections;
    }

    /**
     * @return array<int, ThemeOptionField>
     */
    public function fieldsForSection(string $sectionKey): array
    {
        return array_values(array_filter(
            $this->fields,
            static fn (ThemeOptionField $field): bool => $field->section === $sectionKey,
        ));
    }

    public function field(string $key): ?ThemeOptionField
    {
        return $this->fields[$key] ?? null;
    }

    public function value(string $key): string
    {
        $field = $this->field($key);

        if ($field === null) {
            return '';
        }

        $stored = $this->loadValues()[$key] ?? null;

        return $stored ?? $field->default;
    }

    /**
     * Validates and persists a single field's value. Returns false (and
     * leaves the stored value untouched) when validation fails, so the
     * admin view can report a per-field error instead of silently storing
     * something invalid — LP-034's "Display validation errors".
     */
    public function set(string $key, string $rawValue): bool
    {
        $field = $this->field($key);

        if ($field === null) {
            return false;
        }

        $sanitized = $this->sanitize($field, $rawValue);

        if ($sanitized === null) {
            return false;
        }

        $values = $this->loadValues();
        $values[$key] = $sanitized;
        $this->persist($values);

        return true;
    }

    public function reset(string $key): void
    {
        $field = $this->field($key);

        if ($field === null) {
            return;
        }

        $values = $this->loadValues();
        unset($values[$key]);
        $this->persist($values);
    }

    public function resetSection(string $sectionKey): void
    {
        $values = $this->loadValues();

        foreach ($this->fieldsForSection($sectionKey) as $field) {
            unset($values[$field->key]);
        }

        // header_image_media_id has no ThemeOptionField of its own (see its
        // docblock above), so fieldsForSection('header') never covers it —
        // clear it explicitly here so resetting the Header section reaches
        // the header image too, not just show_site_title/header_height.
        if ($sectionKey === 'header') {
            unset($values['header_image_media_id']);
        }

        $this->persist($values);
    }

    public function resetAll(): void
    {
        $this->persist([]);
    }

    /**
     * Renders every field with a $cssVariable as one `:root { ... }`
     * block, meant to be echoed inside a <style> tag in <head> — see
     * include/helpers.php's theme_options_css(). A field with an empty,
     * $allowEmpty-permitted value is skipped entirely rather than
     * emitting an override — for a Color this means the active theme's
     * own :root default, including its `prefers-color-scheme: dark`
     * variant, keeps controlling that token; for google_fonts_family it
     * means body_font's own choice (registered earlier, so its
     * declaration for the same --lp-font-body comes first in the block)
     * keeps controlling it instead. Every field without $allowEmpty has a
     * real default and is always emitted.
     *
     * accent_color gets a second derived declaration for
     * --lp-accent-hover (color-mix(), already used elsewhere in the
     * default theme's own stylesheet for its focus-ring tint) so a
     * custom accent color doesn't leave hover/focus states pointing at
     * the theme's original, now-mismatched, hover color.
     */
    public function cssVariables(): string
    {
        $declarations = [];

        foreach ($this->fields as $field) {
            if ($field->cssVariable === null) {
                continue;
            }

            $value = $this->value($field->key);

            if ($value === '' && $field->allowEmpty) {
                continue;
            }

            $cssValue = $field->cssValueMap[$value] ?? $value;
            $declarations[] = $field->cssVariable . ': ' . $cssValue . $field->cssUnit . ';';

            if ($field->key === 'accent_color') {
                $declarations[] = '--lp-accent-hover: color-mix(in srgb, ' . $value . ' 80%, black);';
            }
        }

        if ($declarations === []) {
            return '';
        }

        return ':root{' . implode('', $declarations) . '}';
    }

    private function sanitize(ThemeOptionField $field, string $rawValue): ?string
    {
        $rawValue = trim($rawValue);

        return match ($field->type) {
            // A field with a $cssVariable gets its value interpolated
            // directly into a `:root { ... }` declaration inside a plain
            // <style> block (see cssVariables()) — stripping these four
            // characters (never legitimate in a font-family/CSS value)
            // blocks a trivial break-out of that one declaration/rule/tag,
            // the same "defensive, not a security boundary" posture
            // custom_css()'s own `</style` strip already documents for
            // this same trust level (manage_themes administrators only).
            ThemeOptionType::Text, ThemeOptionType::Textarea => $field->cssVariable !== null
                ? str_replace(['{', '}', '<', '>'], '', $rawValue)
                : $rawValue,
            ThemeOptionType::Checkbox => $rawValue === '1' ? '1' : '0',
            ThemeOptionType::Select => array_key_exists($rawValue, $field->choices) ? $rawValue : null,
            ThemeOptionType::Color => $this->sanitizeColor($field, $rawValue),
            ThemeOptionType::Number => $this->sanitizeNumber($field, $rawValue),
            ThemeOptionType::Url => $this->sanitizeUrl($field, $rawValue),
            // Stored raw, unsanitized — same posture as custom_css() and
            // post/page content: this is admin-authored markup trusted at
            // this level (manage_themes only), sanitized once at render
            // time by render_content(), not double-sanitized at storage.
            ThemeOptionType::Html => $rawValue,
        };
    }

    private function sanitizeColor(ThemeOptionField $field, string $rawValue): ?string
    {
        if ($rawValue === '') {
            return $field->allowEmpty ? '' : null;
        }

        return preg_match('/^#[0-9a-fA-F]{6}$/', $rawValue) === 1 ? strtolower($rawValue) : null;
    }

    /**
     * Requires https and (when the field restricts it) an exact host
     * match — see ThemeOptionField::$allowedHosts. Deliberately not a
     * general-purpose "is this a URL" check: the point is to stop this
     * field from becoming a way to load an arbitrary stylesheet from an
     * arbitrary host on every visitor's browser.
     */
    private function sanitizeUrl(ThemeOptionField $field, string $rawValue): ?string
    {
        if ($rawValue === '') {
            return $field->allowEmpty ? '' : null;
        }

        if (filter_var($rawValue, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($rawValue);

        if (($parts['scheme'] ?? '') !== 'https') {
            return null;
        }

        if ($field->allowedHosts !== [] && !in_array($parts['host'] ?? '', $field->allowedHosts, true)) {
            return null;
        }

        return $rawValue;
    }

    private function sanitizeNumber(ThemeOptionField $field, string $rawValue): ?string
    {
        if (!is_numeric($rawValue)) {
            return null;
        }

        $number = (float) $rawValue;

        if ($field->min !== null && $number < $field->min) {
            return null;
        }

        if ($field->max !== null && $number > $field->max) {
            return null;
        }

        // Whole-number fields (every Number field registered today) stay
        // formatted without a trailing ".0" so the stored value matches
        // what a plain <input type="number" step="1"> submits.
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    /**
     * @return array<string, string>
     */
    private function loadValues(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        $decoded = json_decode((string) $this->config->option($this->optionKey(), '{}'), true);
        $this->values = is_array($decoded) ? array_map(strval(...), $decoded) : [];

        return $this->values;
    }

    /**
     * @param array<string, string> $values
     */
    private function persist(array $values): void
    {
        $this->values = $values;
        $this->config->setOption($this->optionKey(), json_encode($values, JSON_THROW_ON_ERROR));
    }
}
