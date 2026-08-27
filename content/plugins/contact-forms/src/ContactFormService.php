<?php

/**
 * CRUD for the contact_forms table (LPP-003).
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
use LumoraPress\Core\Database\Database;
use RuntimeException;

/**
 * `fields` is stored as one JSON column per form (mirrors the site-wide
 * `widgets_config` option's "one JSON blob, no EAV table" precedent, just
 * scoped per-row instead of per-site-option) — a handful of fields per
 * form doesn't justify a separate fields table.
 */
final class ContactFormService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    /**
     * @param array<int, ContactFormField> $fields
     */
    public function create(string $title, string $recipientEmail, array $fields, string $successMessage, ?string $redirectUrl): ContactForm
    {
        $now = new DateTimeImmutable();

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (title, recipient_email, fields, success_message, redirect_url, created_at, updated_at)
             VALUES (:title, :recipient_email, :fields, :success_message, :redirect_url, :created_at, :updated_at)',
            [
                'title' => $title,
                'recipient_email' => $recipientEmail,
                'fields' => $this->encodeFields($fields),
                'success_message' => $successMessage,
                'redirect_url' => $redirectUrl,
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ],
        );

        $form = $this->findById((int) $id);

        if ($form === null) {
            throw new RuntimeException('Failed to load the contact form that was just created.');
        }

        return $form;
    }

    /**
     * @param array<int, ContactFormField> $fields
     */
    public function update(int $id, string $title, string $recipientEmail, array $fields, string $successMessage, ?string $redirectUrl): ContactForm
    {
        $this->database->execute(
            'UPDATE ' . $this->table() . '
                SET title = :title, recipient_email = :recipient_email, fields = :fields,
                    success_message = :success_message, redirect_url = :redirect_url, updated_at = :updated_at
              WHERE id = :id',
            [
                'title' => $title,
                'recipient_email' => $recipientEmail,
                'fields' => $this->encodeFields($fields),
                'success_message' => $successMessage,
                'redirect_url' => $redirectUrl,
                'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'id' => $id,
            ],
        );

        $form = $this->findById($id);

        if ($form === null) {
            throw new RuntimeException("Contact form {$id} does not exist.");
        }

        return $form;
    }

    public function delete(int $id): bool
    {
        return $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]) > 0;
    }

    public function findById(int $id): ?ContactForm
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, ContactForm>
     */
    public function listAll(): array
    {
        $rows = $this->database->fetchAll('SELECT * FROM ' . $this->table() . ' ORDER BY title ASC');

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * Turns admin-submitted field label/type/required/options arrays into
     * validated ContactFormField objects, deriving each one's stable `key`
     * from its label (see slugifyKey()). A blank label means "unused
     * trailing row" (the always-present blank row `add-new.php` renders
     * for the no-JS fallback — see `custom-fields.js`'s identical
     * "blank row" convention) and is silently skipped, not an error.
     *
     * @param array<int, string> $labels
     * @param array<int, string> $types
     * @param array<int, string> $required
     * @param array<int, string> $optionsRaw
     * @return array<int, ContactFormField>
     */
    public function buildFieldsFromSubmission(array $labels, array $types, array $required, array $optionsRaw): array
    {
        $fields = [];
        $usedKeys = [];

        foreach ($labels as $i => $label) {
            $label = trim($label);

            if ($label === '') {
                continue;
            }

            $type = ContactFieldType::tryFrom((string) ($types[$i] ?? '')) ?? ContactFieldType::Text;
            $isRequired = (string) ($required[$i] ?? '0') === '1';
            $options = $type === ContactFieldType::Select
                ? array_values(array_filter(array_map('trim', explode(',', (string) ($optionsRaw[$i] ?? '')))))
                : [];

            $key = $this->slugifyKey($label, $usedKeys);
            $usedKeys[$key] = true;

            $fields[] = new ContactFormField($type, $label, $key, $isRequired, $options);
        }

        return $fields;
    }

    /**
     * A slug unique within this one form's own field list — collisions
     * (e.g. two fields both labeled "Name") get a numeric suffix, the same
     * "append -2, -3, ..." approach MediaService::sanitizeFilename()'s
     * callers use for duplicate filenames.
     *
     * @param array<string, bool> $usedKeys
     */
    private function slugifyKey(string $label, array $usedKeys): string
    {
        $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $label) ?? '', '_'));
        $base = $base === '' ? 'field' : $base;
        $key = $base;
        $suffix = 2;

        while (isset($usedKeys[$key])) {
            $key = $base . '_' . $suffix;
            $suffix++;
        }

        return $key;
    }

    /**
     * @param array<int, ContactFormField> $fields
     */
    private function encodeFields(array $fields): string
    {
        return json_encode(array_map(static fn (ContactFormField $field): array => $field->toArray(), $fields)) ?: '[]';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ContactForm
    {
        $decoded = json_decode((string) $row['fields'], true);
        $fields = is_array($decoded) ? array_map(ContactFormField::fromArray(...), $decoded) : [];

        return new ContactForm(
            id: (int) $row['id'],
            title: (string) $row['title'],
            recipientEmail: (string) $row['recipient_email'],
            fields: $fields,
            successMessage: (string) $row['success_message'],
            redirectUrl: $row['redirect_url'] !== null ? (string) $row['redirect_url'] : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'contact_forms';
    }
}
