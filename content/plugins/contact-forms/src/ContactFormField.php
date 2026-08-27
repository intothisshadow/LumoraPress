<?php

/**
 * A single field definition within a Contact Form's stored `fields` JSON.
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
 * $key is a slug derived from $label at save time (see
 * ContactFormService::slugifyKey()) and is what a submission's stored
 * `data` is keyed by — kept stable even if $label is edited later, so a
 * historic submission's data never gets silently orphaned by a rename.
 * $options is only meaningful for ContactFieldType::Select (a plain list
 * of choice strings); every other type leaves it empty.
 */
final class ContactFormField
{
    /**
     * @param array<int, string> $options
     */
    public function __construct(
        public readonly ContactFieldType $type,
        public readonly string $label,
        public readonly string $key,
        public readonly bool $required,
        public readonly array $options = [],
    ) {
    }

    /**
     * @return array{type: string, label: string, key: string, required: bool, options: array<int, string>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'label' => $this->label,
            'key' => $this->key,
            'required' => $this->required,
            'options' => $this->options,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: ContactFieldType::tryFrom((string) ($data['type'] ?? '')) ?? ContactFieldType::Text,
            label: (string) ($data['label'] ?? ''),
            key: (string) ($data['key'] ?? ''),
            required: (bool) ($data['required'] ?? false),
            options: is_array($data['options'] ?? null) ? array_map('strval', $data['options']) : [],
        );
    }
}
