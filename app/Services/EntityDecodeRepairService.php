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
 * WordPress HTML-entity-encodes plain-text fields (term names, post/page
 * titles and excerpts, display names, comment author names/content) before
 * storing them — typing "TV & Movies" into wp-admin saves `TV &amp;
 * Movies`. Before WordPressSource::decodeEntities() (LP-113) existed, the
 * WordPress Importer stored that value verbatim; esc_html()/esc_attr()
 * (include/helpers.php) then re-encoded the already-encoded value on the
 * way out, so a category imported before that fix renders as the literal
 * text "TV &amp; Movies" instead of "TV & Movies" on the public site.
 *
 * That importer fix only prevents the bug for a *future* import — content
 * already imported (or otherwise saved) with a double-encoded value before
 * the fix landed stays broken until it's decoded once, in place. This
 * class is that one-time repair, run from Maintenance > Tools.
 *
 * Deliberately bypasses CategoryService/TagService/PostService/etc.'s own
 * update() methods and writes directly via parameterized SQL instead:
 * PostService::update()/PageService::update() need a full set of unrelated
 * fields (content, status, featured image, ...) and fire a 'post_saved'
 * hook that can trigger a real outbound request (BlueskyResolverService,
 * see bootstrap.php), and every affected update() regenerates a
 * slug from the title unless one is passed explicitly — none of which
 * belongs in a narrow "fix how this text is encoded, touch nothing else"
 * repair pass. A row is only ever written when decoding actually changes
 * at least one of its columns, so a site with no affected data performs
 * pure reads.
 */
final class EntityDecodeRepairService
{
    /**
     * table => text columns to check/repair on that table. Every column
     * here is rendered via esc_html()/esc_attr() somewhere on the public
     * site or in admin — see this class's own docblock for the exact
     * render sites already confirmed for each (categories.name/tags.name:
     * CoreWidgets/theme templates; posts.title/pages.title: the_title();
     * posts.excerpt/pages.excerpt: the_excerpt(); comments.guest_name/
     * comments.content: comment_list()/format_comment_content();
     * users.display_name: author bylines; media.alt_text/caption/
     * description: image alt attributes and the Media Manager detail view).
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
