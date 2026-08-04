<?php

declare(strict_types=1);

namespace LumoraPress\Core\Theme;

/**
 * Control types the Theme Options admin UI knows how to render and
 * validate (LP-034's "Supported Control Types"). Not every type from the
 * original ticket wishlist is implemented yet — Toggle switch, Radio,
 * Multi-select, Image selector, File upload, Email, Date, and Range
 * slider are deferred; a new ThemeOptionField can only use one of the
 * cases below until a matching branch is added to ThemeOptions'
 * rendering/validation and the admin view's field partial.
 */
enum ThemeOptionType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Checkbox = 'checkbox';
    case Select = 'select';
    case Color = 'color';
    case Url = 'url';
}
