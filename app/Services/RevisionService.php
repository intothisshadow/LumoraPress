<?php

/**
 * Revision history for Posts and Pages (LP-017).
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use DateTimeImmutable;
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\PressConfig;
use LumoraPress\Models\ContentFormat;
use LumoraPress\Models\Revision;
use LumoraPress\Models\RevisionableType;

/**
 * Revision history for Posts and Pages (LP-017). A snapshot is saved by the
 * caller (admin/views/posts.php, admin/views/pages.php) right before it
 * overwrites a post/page via PostService::update()/PageService::update() —
 * this service only stores/retrieves/prunes snapshots, it never reaches
 * into PostService/PageService itself, so restoring a revision is just the
 * caller applying its stored fields back through the normal update() path
 * (which in turn creates its own "before restore" revision, making a
 * restore itself undoable).
 */
final class RevisionService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly PressConfig $config,
    ) {
    }

    public function save(
        RevisionableType $contentType,
        int $contentId,
        string $title,
        string $content,
        string $excerpt,
        ContentFormat $contentFormat,
        int $authorId,
    ): Revision {
        $now = new DateTimeImmutable();

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (content_type, content_id, title, content, excerpt, content_format, author_id, created_at)
             VALUES (:content_type, :content_id, :title, :content, :excerpt, :content_format, :author_id, :created_at)',
            [
                'content_type' => $contentType->value,
                'content_id' => $contentId,
                'title' => $title,
                'content' => $content,
                'excerpt' => $excerpt,
                'content_format' => $contentFormat->value,
                'author_id' => $authorId,
                'created_at' => $now->format('Y-m-d H:i:s'),
            ],
        );

        $this->prune($contentType, $contentId);

        $revision = $this->find((int) $id);

        if ($revision === null) {
            throw new \RuntimeException('Failed to load the revision that was just created.');
        }

        return $revision;
    }

    public function find(int $id): ?Revision
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, Revision> newest first
     */
    public function listFor(RevisionableType $contentType, int $contentId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . '
                WHERE content_type = :content_type AND content_id = :content_id
             ORDER BY created_at DESC, id DESC',
            ['content_type' => $contentType->value, 'content_id' => $contentId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function countFor(RevisionableType $contentType, int $contentId): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE content_type = :content_type AND content_id = :content_id',
            ['content_type' => $contentType->value, 'content_id' => $contentId],
        );
    }

    public function deleteAllFor(RevisionableType $contentType, int $contentId): void
    {
        $this->database->execute(
            'DELETE FROM ' . $this->table() . ' WHERE content_type = :content_type AND content_id = :content_id',
            ['content_type' => $contentType->value, 'content_id' => $contentId],
        );
    }

    /**
     * Deletes the oldest revisions for a piece of content beyond the
     * configured retention limit. A `revision_retention` of 0 means
     * unlimited — never prune.
     */
    private function prune(RevisionableType $contentType, int $contentId): void
    {
        $keep = max(0, (int) $this->config->option('revision_retention', 25));

        if ($keep === 0) {
            return;
        }

        $ids = $this->database->fetchAll(
            'SELECT id FROM ' . $this->table() . '
                WHERE content_type = :content_type AND content_id = :content_id
             ORDER BY created_at DESC, id DESC',
            ['content_type' => $contentType->value, 'content_id' => $contentId],
        );

        $excessIds = array_slice(array_column($ids, 'id'), $keep);

        foreach ($excessIds as $excessId) {
            $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => (int) $excessId]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Revision
    {
        return new Revision(
            id: (int) $row['id'],
            contentType: RevisionableType::from((string) $row['content_type']),
            contentId: (int) $row['content_id'],
            title: (string) $row['title'],
            content: (string) $row['content'],
            excerpt: (string) ($row['excerpt'] ?? ''),
            contentFormat: ContentFormat::tryFrom((string) ($row['content_format'] ?? '')) ?? ContentFormat::Plain,
            authorId: (int) $row['author_id'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }

    private function table(): string
    {
        return $this->tablePrefix . 'revisions';
    }
}
