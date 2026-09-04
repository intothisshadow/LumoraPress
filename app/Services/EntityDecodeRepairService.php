<?php

/**
 * One-time repair for content whose plain-text fields were HTML-entity-encoded twice on the way in.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.6.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use LumoraPress\Core\Database\Database;

/**
 * Before WordPressSource::decodeEntities() existed, the WordPress Importer stored HTML
 * entities verbatim (e.g. "TV &amp; Movies"); esc_html()/esc_attr() then re-encoded that
 * already-encoded value on the way out, so old imports render literal entity text on the
 * public site. That importer fix only prevents the bug for future imports — this class is
 * the one-time repair for content already affected, run from Maintenance > Tools.
 *
 * Bypasses each service's own update() and writes directly via parameterized SQL instead,
 * since update() needs unrelated fields and fires hooks (e.g. an outbound BlueskyResolverService
 * request) that don't belong in a narrow encoding-only repair. A row is only written when
 * decoding actually changes a column, so an unaffected site performs pure reads.
 */
final class EntityDecodeRepairService
{
    /**
     * table => text columns to check/repair on that table. Every column here is rendered
     * via esc_html()/esc_attr() somewhere on the public site or in admin.
     *
     * @var array<string, array<int, string>>
     */
    private const REPAIRABLE_COLUMNS = [
        'categories' => ['name', 'description'],
        'tags' => ['name', 'description'],
        'posts' => ['title', 'excerpt'],
        'pages' => ['title', 'excerpt'],
        'comments' => ['guest_name', 'content'],
        'users' => ['display_name'],
        'media' => ['alt_text', 'caption', 'description'],
    ];

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    /**
     * Scans every table in REPAIRABLE_COLUMNS and decodes any column whose
     * value changes under html_entity_decode() — i.e. anything that was
     * itself already HTML-entity-encoded rather than plain text.
     *
     * @return array<string, int> table => number of rows fixed
     */
    public function repair(): array
    {
        $counts = [];

        foreach (self::REPAIRABLE_COLUMNS as $table => $columns) {
            $counts[$table] = $this->repairTable($table, $columns);
        }

        return $counts;
    }

    /**
     * @param array<int, string> $columns
     */
    private function repairTable(string $table, array $columns): int
    {
        $rows = $this->database->fetchAll(
            'SELECT id, ' . implode(', ', $columns) . ' FROM ' . $this->tablePrefix . $table,
        );

        $fixed = 0;

        foreach ($rows as $row) {
            $changes = [];

            foreach ($columns as $column) {
                $value = $row[$column] ?? null;

                if ($value === null) {
                    continue;
                }

                $decoded = html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8');

                if ($decoded !== $value) {
                    $changes[$column] = $decoded;
                }
            }

            if ($changes === []) {
                continue;
            }

            $setClauses = [];
            $params = ['id' => $row['id']];

            foreach ($changes as $column => $decoded) {
                $setClauses[] = $column . ' = :' . $column;
                $params[$column] = $decoded;
            }

            $this->database->execute(
                'UPDATE ' . $this->tablePrefix . $table . ' SET ' . implode(', ', $setClauses) . ' WHERE id = :id',
                $params,
            );

            $fixed++;
        }

        return $fixed;
    }
}
