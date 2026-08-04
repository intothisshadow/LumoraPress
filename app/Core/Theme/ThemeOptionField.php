<?php

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

/**
 * A single registered Theme Option (LP-034's Developer API "Register
 * individual settings"). Immutable by design — sections/fields are
 * registered once per request (core's own registerStandardOptions(),
 * plus anything a theme/plugin adds via the 'register_theme_options'
 * action) and never mutated afterward; only the stored *value* for a
 * field's key changes, which ThemeOptions keeps separately.
 */
final class ThemeOptionField
{
    /**
     * @param array<string, string> $choices value => label, Select only
     * @param array<string, string> $cssValueMap Select only: stored choice
     *     value => the actual CSS value to emit for $cssVariable, when
     *     that differs from the choice value itself (e.g. body_font
     *     stores the short key 'serif' but the CSS declaration needs the
     *     full font stack). Left empty for every Select field whose
     *     choice values already *are* valid CSS values (content_width,
     *     line_height) — the stored value is used as-is.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $section,
        public readonly ThemeOptionType $type,
        public readonly string $label,
        public readonly string $default = '',
        public readonly ?string $cssVariable = null,
        public readonly string $help = '',
        public readonly array $choices = [],
        public readonly ?float $min = null,
        public readonly ?float $max = null,
        /**
         * Appended to a Number field's stored value when building its CSS
         * custom property declaration (e.g. 'px') — the stored value
         * itself stays a bare numeric string so min/max validation in
         * ThemeOptions::sanitizeNumber() doesn't have to parse a unit
         * suffix back off of it.
         */
        public readonly string $cssUnit = '',
        public readonly array $cssValueMap = [],
        /**
         * Whether an empty stored value is a legitimate "leave this
         * unset" state rather than something to coerce back to $default.
         * For Color fields this means "inherit the active theme's own
         * default for this token" — several of the default theme's color
         * tokens carry a separate value inside a `prefers-color-scheme:
         * dark` block (see style.css), so always emitting an override,
         * even one that matches the light-mode default, would silently
         * force light-mode colors onto every dark-mode visitor. For any
         * other field with a $cssVariable (e.g. google_fonts_family) it
         * means "don't emit this declaration, let an earlier field
         * registered for the same $cssVariable — or the theme's own
         * :root default if none — keep controlling it." See
         * ThemeOptions::cssVariables().
         */
        public readonly bool $allowEmpty = false,
        /**
         * Color fields only: the hex shown in the <input type="color">
         * swatch when the stored value is '' (inherit) — display only,
         * never persisted unless the administrator actually changes it.
         */
        public readonly ?string $previewDefault = null,
        /**
         * Url fields only: when non-empty, the submitted URL's host must
         * exactly match one of these — e.g. restricting a Google Fonts
         * URL field to fonts.googleapis.com so an administrator (or
         * whoever compromises that admin account) can't turn this field
         * into a way to load an arbitrary stylesheet from an arbitrary
         * host on every visitor's browser. Left empty for a Url field
         * with no such restriction.
         *
         * @var array<int, string>
         */
        public readonly array $allowedHosts = [],
    ) {
    }
}
