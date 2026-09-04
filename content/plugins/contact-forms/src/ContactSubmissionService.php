<?php

/**
 * CRUD for the contact_form_submissions table.
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

final class ContactSubmissionService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    /**
     * @param array<string, string> $data
     */
    public function create(int $formId, array $data, ?string $ipAddress, ?string $pageUrl, bool $isSpam): ContactSubmission
    {
        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (form_id, data, ip_address, page_url, is_read, is_spam, created_at)
             VALUES (:form_id, :data, :ip_address, :page_url, 0, :is_spam, :created_at)',
            [
                'form_id' => $formId,
                'data' => json_encode($data) ?: '{}',
                'ip_address' => $ipAddress,
                'page_url' => $pageUrl,
                'is_spam' => $isSpam ? 1 : 0,
                'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );

        $submission = $this->findById((int) $id);

        if ($submission === null) {
            throw new RuntimeException('Failed to load the submission that was just created.');
        }

        return $submission;
    }

    public function findById(int $id): ?ContactSubmission
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, ContactSubmission>
     */
    public function listAll(?int $formId = null, ?bool $spamOnly = null, int $limit = 100): array
    {
        $where = [];
        $params = [];

        if ($formId !== null) {
            $where[] = 'form_id = :form_id';
            $params['form_id'] = $formId;
        }

        if ($spamOnly !== null) {
            $where[] = 'is_spam = :is_spam';
            $params['is_spam'] = $spamOnly ? 1 : 0;
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $limit = max(1, $limit);

        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . $whereSql . " ORDER BY created_at DESC LIMIT {$limit}",
            $params,
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function countUnread(): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE is_read = 0 AND is_spam = 0',
        );
    }

    public function markRead(int $id): void
    {
        $this->database->execute('UPDATE ' . $this->table() . ' SET is_read = 1 WHERE id = :id', ['id' => $id]);
    }

    public function delete(int $id): bool
    {
        return $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]) > 0;
    }

    /**
     * Called when a form itself is deleted (see
     * admin/views/contact-forms/all-forms.php) so its submissions don't
     * linger as orphaned rows with no owning form to view them under.
     */
    public function deleteByFormId(int $formId): void
    {
        $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE form_id = :form_id', ['form_id' => $formId]);
    }

    /**
     * A light per-IP flood guard, applied across every form rather than
     * scoped to one, since a bot hammering this endpoint doesn't care
     * which form id it's hitting.
     */
    public function recentSubmissionFromIpExists(string $ipAddress, int $windowSeconds): bool
    {
        $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);

        return $this->database->fetchOne(
            'SELECT id FROM ' . $this->table() . ' WHERE ip_address = :ip_address AND created_at >= :window_start',
            ['ip_address' => $ipAddress, 'window_start' => $windowStart],
        ) !== null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ContactSubmission
    {
        $decoded = json_decode((string) $row['data'], true);

        return new ContactSubmission(
            id: (int) $row['id'],
            formId: (int) $row['form_id'],
            data: is_array($decoded) ? array_map('strval', $decoded) : [],
            ipAddress: $row['ip_address'] !== null ? (string) $row['ip_address'] : null,
            pageUrl: $row['page_url'] !== null ? (string) $row['page_url'] : null,
            isRead: ((int) $row['is_read']) === 1,
            isSpam: ((int) $row['is_spam']) === 1,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'contact_form_submissions';
    }
}
