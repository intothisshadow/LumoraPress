<?php

/**
 * A single registered Theme Option (LP-034).
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

/**
 * A single registered Theme Option. Immutable by design — sections/fields
 * are registered once per request (core's own registerStandardOptions(),
 * plus anything a theme/plugin adds via the 'register_theme_options'
 * action) and never mutated afterward; only the stored *value* for a
 * field's key changes, which ThemeOptions keeps separately.
 */
final class ThemeOptionField
{
    /**
     * @param array<int|string, string> $choices value => label, Select only (PHP stores numeric-string keys such as '1' as ints)
     * @param array<string, string> $cssValueMap Select only: stored choice value =>
     *     actual CSS value for $cssVariable, when they differ (e.g. body_font
     *     stores 'serif' but the CSS needs the full font stack).
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
         * Whether an empty stored value means "leave this unset" rather
         * than coerce back to $default. For Color fields this means
         * "inherit the theme's own default" — several default-theme color
         * tokens have a separate dark-mode value, so always emitting an
         * override would force light-mode colors on dark-mode visitors.
         * For other $cssVariable fields it means "don't emit this
         * declaration, let the theme's own :root default control it."
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
         * exactly match one of these — e.g. restricting a Google Fonts URL
         * field to fonts.googleapis.com so a compromised admin account
         * can't turn it into a way to load an arbitrary stylesheet.
         *
         * @var array<int, string>
         */
        public readonly array $allowedHosts = [],
        /**
         * A feature name (see ThemeSupport) the active theme must declare with
         * add_theme_support() for this option to do anything. Null means every
         * theme honors it.
         */
        public readonly ?string $requiresSupport = null,
        /**
         * Further custom properties computed from this field's value, emitted
         * alongside $cssVariable: custom property => CSS value, with {value}
         * standing for the (validated) stored value. A color is typically used
         * to derive its hover or tint variants, so they stay consistent.
         *
         * @var array<string, string>
         */
        public readonly array $derivedVariables = [],
        /**
         * Emitted only while the visitor is in dark mode, after the theme's own dark colors so it
         * wins. Without this, a custom color is overridden by the theme's dark value for the same
         * token (the theme's dark rules are more specific than a bare :root declaration).
         */
        public readonly bool $darkMode = false,
    ) {
    }
}
