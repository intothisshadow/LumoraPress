<?php

/**
 * A single Contact Form: its fields, recipient, and post-submission behavior.
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

use DateTimeImmutable;

final class ContactForm
{
    /**
     * @param array<int, ContactFormField> $fields
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $recipientEmail,
        public readonly array $fields,
        public readonly string $successMessage,
        public readonly ?string $redirectUrl,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public function fieldByKey(string $key): ?ContactFormField
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }
}
