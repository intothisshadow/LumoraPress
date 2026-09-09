<?php

/**
 * A single stored Contact Form submission.
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

/**
 * $data is keyed by each field's stable ContactFormField::$key, not its
 * display label, so a later label edit on the parent form never orphans
 * historic submission data (see ContactForm::fieldByKey()).
 */
final class ContactSubmission
{
    /**
     * @param array<string, string> $data
     */
    public function __construct(
        public readonly int $id,
        public readonly int $formId,
        public readonly array $data,
        public readonly ?string $ipAddress,
        public readonly ?string $pageUrl,
        public readonly bool $isRead,
        public readonly bool $isSpam,
        public readonly bool $isArchived,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
