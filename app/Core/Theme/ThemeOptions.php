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
 * The Theme Options system. Sections and fields are registered once per
 * request — core's registerStandardOptions() plus anything a theme/plugin
 * adds via the 'register_theme_options' action.
 *
 * Values are scoped per active theme, stored as JSON under a
 * `theme_options_{slug}` option — switching themes switches which values
 * are shown/editable, each theme keeping its own independent set.
 *
 * Values are always plain strings — Checkbox stores '1'/'0', Number a
 * numeric string, Color a '#rrggbb' hex string or '' for "inherit the
 * theme's own default" (see ThemeOptionField's $allowEmpty docblock).
 */
final class ThemeOptions
{
    /** @var array<string, ThemeOptionSection> */
    private array $sections = [];

    /** @var array<string, ThemeOptionField> */
    private array $fields = [];

    /** @var array<string, string>|null */
    private ?array $values = null;

    /** True while an unsaved draft is being previewed: nothing may be written back. */
    private bool $previewOnly = false;

    public function __construct(
        private readonly PressConfig $config,
        private readonly string $activeThemeSlug = 'lumora-classic',
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
     * Core's own built-in options — called once from bootstrap, before a
     * theme/plugin registers more via 'register_theme_options'. Covers
     * Colors, Typography, Layout, Post Display, and Header/Welcome
     * Message/Footer; Homepage, Blog, and Images remain deferred.
     */
    public function registerStandardOptions(): void
    {
        $this->registerSection('colors', 'Colors', 'Overrides the active theme\'s default color palette in light mode. Leave a color set to "Use theme default" to keep the theme\'s own choice. Dark mode keeps the theme\'s own dark colors unless you set them under Dark Mode Colors.');

        $this->registerField(new ThemeOptionField(
            key: 'accent_color',
            section: 'colors',
            type: ThemeOptionType::Color,
            label: 'Accent color',
            cssVariable: '--lp-accent',
            derivedVariables: ['--lp-accent-hover' => 'color-mix(in srgb, {value} 80%, black)'],
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
        // The chosen color is the state's border; its background and text are mixed from it and
        // the theme's own surface and text colors, so they stay readable in light and dark mode.
        $this->registerField(new ThemeOptionField(
            key: 'success_color',
            section: 'colors',
            type: ThemeOptionType::Color,
            label: 'Success color',
            cssVariable: '--lp-success-border',
            derivedVariables: [
                '--lp-success-bg' => 'color-mix(in srgb, {value} 14%, var(--lp-bg-alt))',
                '--lp-success-text' => 'color-mix(in srgb, {value} 45%, var(--lp-text))',
            ],
            help: 'Used for confirmation messages, such as "Your comment was submitted".',
            allowEmpty: true,
            previewDefault: '#00a32a',
        ));
        $this->registerField(new ThemeOptionField(
            key: 'error_color',
            section: 'colors',
            type: ThemeOptionType::Color,
            label: 'Error color',
            cssVariable: '--lp-error-border',
            derivedVariables: [
                '--lp-error-bg' => 'color-mix(in srgb, {value} 14%, var(--lp-bg-alt))',
                '--lp-error-text' => 'color-mix(in srgb, {value} 45%, var(--lp-text))',
            ],
            help: 'Used for error messages and form problems.',
            allowEmpty: true,
            previewDefault: '#d63638',
        ));

        // The same colors again, applied only in dark mode. A theme without a dark appearance
        // doesn't declare 'dark-mode', so this whole panel stays hidden for it.
        $this->registerSection('colors_dark', 'Dark Mode Colors', 'Colors used when a visitor is in dark mode, whether from their device setting or the site\'s light/dark switch. Leave a color on "Use theme default" to keep the theme\'s own dark color.');

        foreach ([
            ['dark_accent_color', 'Accent color', '--lp-accent', '#61d0c2', ['--lp-accent-hover' => 'color-mix(in srgb, {value} 80%, white)']],
            ['dark_text_color', 'Text color', '--lp-text', '#edf3f1', []],
            ['dark_text_muted_color', 'Muted text color', '--lp-text-muted', '#a6b4b5', []],
            ['dark_background_color', 'Background color', '--lp-bg', '#111821', []],
            ['dark_background_alt_color', 'Alternate background color', '--lp-bg-alt', '#1b2530', []],
            ['dark_border_color', 'Border color', '--lp-border', '#33434b', []],
            ['dark_success_color', 'Success color', '--lp-success-border', '#2fae4a', [
                '--lp-success-bg' => 'color-mix(in srgb, {value} 14%, var(--lp-bg-alt))',
                '--lp-success-text' => 'color-mix(in srgb, {value} 45%, var(--lp-text))',
            ]],
            ['dark_error_color', 'Error color', '--lp-error-border', '#d63638', [
                '--lp-error-bg' => 'color-mix(in srgb, {value} 14%, var(--lp-bg-alt))',
                '--lp-error-text' => 'color-mix(in srgb, {value} 45%, var(--lp-text))',
            ]],
        ] as [$key, $label, $variable, $preview, $derived]) {
            $this->registerField(new ThemeOptionField(
                key: $key,
                section: 'colors_dark',
                type: ThemeOptionType::Color,
                label: $label,
                cssVariable: $variable,
                derivedVariables: $derived,
                allowEmpty: true,
                previewDefault: $preview,
                requiresSupport: 'dark-mode',
                darkMode: true,
            ));
        }

        $this->registerSection('typography', 'Typography', 'Controls the fonts and text sizing used across every public page.');

        $this->registerField(new ThemeOptionField(
            key: 'body_font',
            section: 'typography',
            type: ThemeOptionType::Select,
            label: 'Body font',
            default: '',
            cssVariable: '--lp-font-body',
            allowEmpty: true,
            choices: [
                '' => 'Theme default',
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

        // Like the images options, these change a theme's own heading styling only once an
        // administrator picks a value; body_class() adds lp-custom-heading-font and each theme's
        // override rule applies --lp-font-heading to its headings. The family field is registered
        // after the choice so, when both are set, its declaration is the one that wins.
        $this->registerField(new ThemeOptionField(
            key: 'heading_font',
            section: 'typography',
            type: ThemeOptionType::Select,
            label: 'Heading font',
            default: '',
            cssVariable: '--lp-font-heading',
            allowEmpty: true,
            requiresSupport: 'heading-font',
            help: 'The font for page and post titles, titles in lists and widgets, and headings inside content.',
            choices: [
                '' => 'Theme default',
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
            key: 'heading_google_fonts_family',
            section: 'typography',
            type: ThemeOptionType::Text,
            label: 'Heading Google Fonts font family',
            cssVariable: '--lp-font-heading',
            requiresSupport: 'heading-font',
            help: 'The font-family value of a font from the Google Fonts URL above, e.g. \'Playfair Display\', serif — overrides the Heading font selection when set. The URL can include more than one family, one for the body and one for headings.',
            allowEmpty: true,
        ));

        $this->registerSection('layout', 'Layout', 'Controls the width and sidebar placement of the site\'s header, content, and footer.');

        $this->registerField(new ThemeOptionField(
            key: 'content_width',
            section: 'layout',
            type: ThemeOptionType::Select,
            label: 'Content width',
            default: '',
            cssVariable: '--lp-max-width',
            help: 'Leave set to "Use theme default" to keep the active theme\'s own chosen width — only pick a specific width here to force every theme to that same value regardless of its own design.',
            choices: [
                '' => 'Use theme default',
                '720px' => 'Narrow',
                '960px' => 'Default',
                '1200px' => 'Wide',
                'none' => 'Full width',
            ],
            allowEmpty: true,
        ));

        $this->registerField(new ThemeOptionField(
            key: 'sidebar_position',
            requiresSupport: 'sidebar-position',
            section: 'layout',
            type: ThemeOptionType::Select,
            label: 'Sidebar position',
            default: 'right',
            choices: [
                'right' => 'Right',
                'left' => 'Left',
                'none' => 'No sidebar',
            ],
            help: '"No sidebar" hides the sidebar on every page, including a welcome message placed there, and lets the content use the full width. On small screens the sidebar always sits below the content.',
        ));

        // Its own section, not folded into Layout: page width vs. how much
        // of each post a listing shows are unrelated concerns. None of
        // these fields carry a $cssVariable — they're read directly via
        // theme_option() to decide what to render, not injected as CSS.
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
        $this->registerSection('post_meta', 'Post Details', 'Choose which details appear with each post, on the front page, archives, and single posts.');

        foreach ([
            'show_post_author' => ['Show author', 'Show who wrote the post.', '1'],
            'show_post_date' => ['Show date', 'Show when the post was published.', '1'],
            'show_post_categories' => ['Show categories', 'Show the "Filed under" list of categories.', '1'],
            'show_post_tags' => ['Show tags', 'Show the post\'s tags below its content.', '1'],
            'show_post_reading_time' => ['Show reading time', 'Show an estimate such as "3 min read", based on the post\'s word count.', '0'],
        ] as $key => [$label, $help, $default]) {
            $this->registerField(new ThemeOptionField(
                key: $key,
                section: 'post_meta',
                requiresSupport: 'post-details',
                type: ThemeOptionType::Checkbox,
                label: $label,
                default: $default,
                help: $help,
            ));
        }

        $this->registerSection('page_elements', 'Page Elements', 'Choose which navigation and heading elements appear on pages and archives.');

        $this->registerField(new ThemeOptionField(
            key: 'show_breadcrumbs',
            section: 'page_elements',
            type: ThemeOptionType::Checkbox,
            label: 'Show breadcrumbs',
            default: '1',
            help: 'Show the "Home › Parent" trail above child pages and sub-category archives.',
        ));
        $this->registerField(new ThemeOptionField(
            key: 'show_page_titles',
            requiresSupport: 'page-titles',
            section: 'page_elements',
            type: ThemeOptionType::Checkbox,
            label: 'Show page titles',
            default: '1',
            help: 'Show the title at the top of each static page. When off, the title stays in the page for screen readers and search engines, just not visible. Post titles, archives, and search results are not affected.',
        ));
        $this->registerField(new ThemeOptionField(
            key: 'show_back_to_top',
            requiresSupport: 'back-to-top',
            section: 'page_elements',
            type: ThemeOptionType::Checkbox,
            label: 'Show back-to-top button',
            default: '0',
            help: 'Show a button in the corner that scrolls back to the top once a visitor has scrolled down a little.',
        ));

        // Its own section rather than folded into Post Display: this is a
        // single visual choice with its own admin picker UI (customize.php
        // renders featured_image_position as two clickable preview cards,
        // not a plain <select>), not a list of unrelated toggles.
        $this->registerSection('featured_image', 'Featured Image', 'Choose where the featured image appears relative to the title on a single post or page.');

        $this->registerField(new ThemeOptionField(
            key: 'featured_image_position',
            section: 'featured_image',
            type: ThemeOptionType::Select,
            label: 'Featured image position',
            default: 'above',
            choices: [
                'above' => 'Above title',
                'beside' => 'Beside title',
            ],
            help: 'On the homepage, this also switches the Classic layout\'s listing between the two arrangements — the Magazine layout always shows the image above.',
        ));

        // These three override a theme's own image styling only once an
        // administrator picks a value (default: none), so a theme author's
        // design wins until then. The variable alone changes nothing: body_class()
        // adds a lp-custom-* class that switches on each theme's override rule.
        $this->registerSection('images', 'Images', 'Choose how images look across posts, pages, and galleries. Leave a setting on "Use theme default" to keep the active theme\'s own style.');

        $this->registerField(new ThemeOptionField(
            key: 'image_radius',
            requiresSupport: 'image-styling',
            section: 'images',
            type: ThemeOptionType::Select,
            label: 'Image corners',
            default: '',
            cssVariable: '--lp-image-radius',
            choices: [
                '' => 'Use theme default',
                'square' => 'Square',
                'soft' => 'Slightly rounded',
                'round' => 'Rounded',
            ],
            cssValueMap: ['square' => '0', 'soft' => '6px', 'round' => '16px'],
            help: 'The corners of images in posts and pages, thumbnails, and galleries.',
            allowEmpty: true,
        ));
        $this->registerField(new ThemeOptionField(
            key: 'image_shadow',
            requiresSupport: 'image-styling',
            section: 'images',
            type: ThemeOptionType::Select,
            label: 'Image shadow',
            default: '',
            cssVariable: '--lp-image-shadow',
            choices: [
                '' => 'Use theme default',
                'none' => 'No shadow',
                'soft' => 'Soft shadow',
                'strong' => 'Strong shadow',
            ],
            cssValueMap: [
                'none' => 'none',
                'soft' => '0 4px 14px rgba(0, 0, 0, 0.15)',
                'strong' => '0 10px 30px rgba(0, 0, 0, 0.35)',
            ],
            help: 'A shadow under images in posts and pages, thumbnails, and galleries.',
            allowEmpty: true,
        ));
        $this->registerField(new ThemeOptionField(
            key: 'gallery_spacing',
            requiresSupport: 'image-styling',
            section: 'images',
            type: ThemeOptionType::Select,
            label: 'Gallery spacing',
            default: '',
            cssVariable: '--lp-gallery-gap',
            choices: [
                '' => 'Use theme default',
                'tight' => 'Tight',
                'normal' => 'Normal',
                'wide' => 'Wide',
            ],
            cssValueMap: ['tight' => '0.25rem', 'normal' => '0.75rem', 'wide' => '1.5rem'],
            help: 'The space between thumbnails in a gallery.',
            allowEmpty: true,
        ));

        // header_image itself is deliberately NOT a ThemeOptionField — a
        // file upload doesn't fit this class's string-in/string-out
        // sanitize() contract — see headerImageMediaId() below instead.
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
            key: 'show_search_bar',
            requiresSupport: 'search-bar',
            section: 'header',
            type: ThemeOptionType::Checkbox,
            label: 'Show search bar',
            default: '1',
            help: 'Show the search box in the header. Visitors can still reach search results by visiting the search address directly.',
        ));
        $this->registerField(new ThemeOptionField(
            key: 'show_tagline',
            requiresSupport: 'tagline',
            section: 'header',
            type: ThemeOptionType::Checkbox,
            label: 'Show tagline',
            default: '1',
            help: 'Show the site tagline (set in Settings › General) in the header.',
        ));
        $this->registerField(new ThemeOptionField(
            key: 'header_height',
            section: 'header',
            type: ThemeOptionType::Number,
            label: 'Header image height (px)',
            default: '',
            cssVariable: '--lp-header-image-height',
            min: 50,
            max: 800,
            cssUnit: 'px',
            help: 'Only applies when a header image is set below. Leave blank to use the active theme\'s own height.',
            allowEmpty: true,
        ));
        $this->registerField(new ThemeOptionField(
            key: 'header_image_order',
            section: 'header',
            type: ThemeOptionType::Select,
            label: 'Header image order',
            default: 'added',
            choices: [
                'added' => 'In the order added',
                'random' => 'Random',
            ],
            help: 'Only matters with more than one header image: each page view shows the next image in the order added, or a random one.',
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

        // Separate from the fixed "Powered by Lumora Press" attribution
        // (see powered_by_html()) — this is additional content, not a replacement.
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
        $this->registerField(new ThemeOptionField(
            key: 'footer_widget_columns',
            requiresSupport: 'footer-columns',
            section: 'footer',
            type: ThemeOptionType::Select,
            label: 'Footer widget columns',
            default: 'auto',
            choices: [
                'auto' => 'Automatic',
                '1' => '1 column',
                '2' => '2 columns',
                '3' => '3 columns',
                '4' => '4 columns',
            ],
            help: 'How many columns the widgets in the Footer widget area are arranged in. Automatic fits as many as the width allows. Small screens always use one column. Has no effect while the Footer widget area has no widgets.',
        ));
    }

    /**
     * Stored as a reserved key in the same per-theme values blob rather
     * than as a ThemeOptionField (file uploads don't fit the string-in/
     * string-out sanitize() contract) — this also means Reset Section on
     * 'header' clears it for free, since it lives in the same array.
     */
    public function headerImageMediaId(): int
    {
        return $this->headerImageMediaIds()[0] ?? 0;
    }

    /**
     * The header images in the order they were added. A site that only has
     * the older single-image value is read as a list of one.
     *
     * @return array<int, int>
     */
    public function headerImageMediaIds(): array
    {
        $values = $this->loadValues();
        $raw = (string) ($values['header_image_media_ids'] ?? '');

        if ($raw === '' && (int) ($values['header_image_media_id'] ?? 0) > 0) {
            $raw = (string) (int) $values['header_image_media_id'];
        }

        return $this->cleanMediaIds(explode(',', $raw));
    }

    /**
     * @param array<int, int|string> $mediaIds
     */
    public function setHeaderImageMediaIds(array $mediaIds): void
    {
        $values = $this->loadValues();
        $ids = $this->cleanMediaIds($mediaIds);

        // The older single-image value is superseded by the list.
        unset($values['header_image_media_id']);

        if ($ids === []) {
            unset($values['header_image_media_ids']);
        } else {
            $values['header_image_media_ids'] = implode(',', $ids);
        }

        $this->persist($values);
    }

    public function setHeaderImageMediaId(int $mediaId): void
    {
        $this->setHeaderImageMediaIds($mediaId > 0 ? [$mediaId] : []);
    }

    public function removeHeaderImage(): void
    {
        $this->setHeaderImageMediaIds([]);
    }

    /**
     * @param array<int, int|string> $candidates
     * @return array<int, int>
     */
    private function cleanMediaIds(array $candidates): array
    {
        $ids = [];

        foreach ($candidates as $candidate) {
            $id = (int) $candidate;

            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
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

    public function themeSlug(): string
    {
        return $this->activeThemeSlug;
    }

    /**
     * Whether the active theme implements what this option needs. An option that names no
     * feature is honored by every theme.
     */
    public function isSupported(ThemeOptionField $field): bool
    {
        return $field->requiresSupport === null || ThemeSupport::has($field->requiresSupport);
    }

    /**
     * Reads one section's submitted form into what saving it would do, without saving: values
     * to set, options to put back to the theme's own default, and the labels of any that failed
     * validation. Options the theme doesn't support are skipped, and a Select that wasn't
     * submitted keeps its current value. Save and Preview both go through this so they can't
     * disagree about what a form means.
     *
     * @param array<string, mixed> $post
     * @return array{set: array<string, string>, reset: array<int, string>, errors: array<int, string>}
     */
    public function draftFromInput(string $sectionKey, array $post): array
    {
        $draft = ['set' => [], 'reset' => [], 'errors' => []];

        foreach ($this->fieldsForSection($sectionKey) as $field) {
            if (!$this->isSupported($field)) {
                continue;
            }

            $postKey = 'opt_' . $field->key;

            if ($field->allowEmpty && ($post['reset_' . $field->key] ?? '') === '1') {
                $draft['reset'][] = $field->key;

                continue;
            }

            if ($field->type === ThemeOptionType::Select && !isset($post[$postKey])) {
                continue;
            }

            $rawValue = $field->type === ThemeOptionType::Checkbox
                ? (isset($post[$postKey]) ? '1' : '0')
                : (string) ($post[$postKey] ?? '');

            $sanitized = $this->sanitize($field, $rawValue);

            if ($sanitized === null) {
                $draft['errors'][] = $field->label;

                continue;
            }

            $draft['set'][$field->key] = $sanitized;
        }

        return $draft;
    }

    /**
     * Saves what draftFromInput() returned.
     *
     * @param array{set: array<string, string>, reset: array<int, string>, errors: array<int, string>} $draft
     */
    public function applyDraft(array $draft): void
    {
        foreach ($draft['reset'] as $key) {
            $this->reset($key);
        }

        foreach ($draft['set'] as $key => $value) {
            $this->set($key, $value);
        }
    }

    /**
     * Makes this request read the draft's values instead of the saved ones and refuses to write
     * anything, so previewing unsaved changes can never alter the site.
     *
     * @param array{set: array<string, string>, reset: array<int, string>} $draft
     */
    public function usePreviewValues(array $draft): void
    {
        $values = $this->loadValues();

        foreach ($draft['reset'] as $key) {
            unset($values[$key]);
        }

        $this->values = array_replace($values, $draft['set']);
        $this->previewOnly = true;
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
     * something invalid.
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

        // Has no ThemeOptionField of its own, so fieldsForSection() never
        // covers it — clear it explicitly so resetting Header reaches it too.
        if ($sectionKey === 'header') {
            unset($values['header_image_media_id'], $values['header_image_media_ids']);
        }

        $this->persist($values);
    }

    public function resetAll(): void
    {
        $this->persist([]);
    }

    /**
     * Renders every field with a $cssVariable as one `:root { ... }`
     * block, echoed inside a <style> tag via theme_options_css(). A field
     * with an empty, $allowEmpty-permitted value is skipped entirely
     * rather than emitting an override, so the theme's own default (dark
     * mode included) keeps controlling that token.
     *
     * A field's $derivedVariables are emitted right after it, so a custom
     * color doesn't leave its hover or tint variants mismatched.
     */
    public function cssVariables(): string
    {
        $declarations = [];
        $darkDeclarations = [];

        foreach ($this->fields as $field) {
            if ($field->cssVariable === null) {
                continue;
            }

            $value = $this->value($field->key);

            if ($value === '' && $field->allowEmpty) {
                continue;
            }

            $cssValue = $field->cssValueMap[$value] ?? $value;
            $lines = [$field->cssVariable . ': ' . $cssValue . $field->cssUnit . ';'];

            foreach ($field->derivedVariables as $variable => $template) {
                $lines[] = $variable . ': ' . str_replace('{value}', $value, $template) . ';';
            }

            if ($field->darkMode) {
                array_push($darkDeclarations, ...$lines);
            } else {
                array_push($declarations, ...$lines);
            }
        }

        $css = $declarations === [] ? '' : ':root{' . implode('', $declarations) . '}';

        // Same two selectors the themes' own dark colors use (the device preference unless the
        // visitor chose light, and the explicit dark switch); placed after the theme's
        // stylesheet, they win on source order.
        if ($darkDeclarations !== []) {
            $dark = implode('', $darkDeclarations);
            $css .= '@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){' . $dark . '}}:root[data-theme="dark"]{' . $dark . '}';
        }

        return $css;
    }

    private function sanitize(ThemeOptionField $field, string $rawValue): ?string
    {
        $rawValue = trim($rawValue);

        return match ($field->type) {
            // A $cssVariable field's value is interpolated directly into a
            // :root declaration (see cssVariables()) — stripping these
            // characters is a defensive break-out guard, not a security
            // boundary, at the same trust level as custom_css()'s own strip.
            ThemeOptionType::Text, ThemeOptionType::Textarea => $field->cssVariable !== null
                ? str_replace(['{', '}', '<', '>'], '', $rawValue)
                : $rawValue,
            ThemeOptionType::Checkbox => $rawValue === '1' ? '1' : '0',
            ThemeOptionType::Select => array_key_exists($rawValue, $field->choices) ? $rawValue : null,
            ThemeOptionType::Color => $this->sanitizeColor($field, $rawValue),
            ThemeOptionType::Number => $this->sanitizeNumber($field, $rawValue),
            ThemeOptionType::Url => $this->sanitizeUrl($field, $rawValue),
            // Stored raw, sanitized only at render time by render_content() —
            // same posture as custom_css() and post/page content.
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
     * Requires https and, when the field restricts it, an exact host
     * match — see ThemeOptionField::$allowedHosts.
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
        if ($rawValue === '') {
            return $field->allowEmpty ? '' : null;
        }

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

        // Formatted without a trailing ".0" to match what a plain
        // <input type="number" step="1"> submits.
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
        if ($this->previewOnly) {
            return;
        }

        $this->values = $values;
        $this->config->setOption($this->optionKey(), json_encode($values, JSON_THROW_ON_ERROR));
    }
}
