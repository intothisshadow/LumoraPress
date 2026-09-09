<?php

/**
 * The fixed set of field kinds a Contact Form field can be.
 *
 * @package LumoraPress
 * @subpackage Plugins
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.7.0
 */

declare(strict_types=1);

namespace LumoraPress\Plugins\ContactForms;

/**
 * Deliberately a fixed, small list rather than an extensible
 * custom-field-type registry — Name/Subject/Text/Email/Message/Textarea all
 * render as a plain text input or textarea with different labels/
 * validation, Select/Checkbox cover choice-based input, and FileUpload adds
 * a single attachment, which covers what most contact forms actually need
 * without the complexity of a plugin-extensible field-type API.
 */
enum ContactFieldType: string
{
    case Name = 'name';
    case Email = 'email';
    case Subject = 'subject';
    case Message = 'message';
    case Text = 'text';
    case Textarea = 'textarea';
    case Checkbox = 'checkbox';
    case Select = 'select';
    case FileUpload = 'file_upload';

    /**
     * Whether this field renders as a `<textarea>` rather than an
     * `<input>`/`<select>`/checkbox.
     */
    public function isMultiline(): bool
    {
        return $this === self::Message || $this === self::Textarea;
    }

    /**
     * The `<input type="...">` value for the non-multiline, non-select,
     * non-checkbox cases — Email gets HTML5 email validation for free,
     * everything else in that group is plain text.
     */
    public function inputType(): string
    {
        return $this === self::Email ? 'email' : 'text';
    }

    public function defaultLabel(): string
    {
        return match ($this) {
            self::Name => 'Name',
            self::Email => 'Email',
            self::Subject => 'Subject',
            self::Message => 'Message',
            self::Text => 'Text',
            self::Textarea => 'Textarea',
            self::Checkbox => 'Checkbox',
            self::Select => 'Select',
            self::FileUpload => 'File Upload',
        };
    }
}
