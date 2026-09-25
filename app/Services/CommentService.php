<?php

/**
 * Comment CRUD, moderation, and the public comment tree for a post or page.
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
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Models\Comment;
use LumoraPress\Models\CommentStatus;
use LumoraPress\Models\PageStatus;
use LumoraPress\Models\PageVisibility;
use LumoraPress\Models\PostStatus;
use LumoraPress\Models\PostVisibility;
use RuntimeException;

/**
 * Comment CRUD, moderation, and the public comment tree for a post or page.
 *
 * Commenter identity is denormalized onto the comment row itself (guest_name/guest_email/
 * guest_url) even when user_id is set — a comment is a snapshot of who posted it, not a live
 * reference, avoiding an N+1 join for every listing. user_id is kept only for moderation
 * tooling to trace a comment back to an account. See CategoryService's docblock for why
 * every query uses a distinct placeholder name per occurrence.
 *
 * create() deliberately does not fire a hook itself: SiteController::submitComment() already
 * fires 'comment_posted' after calling it, so firing a second one here would double up
 * every listener.
 */
final class CommentService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    /**
     * Exactly one of $postId/$pageId should be passed — see Comment's
     * own docblock for why this is a nullable pair rather than a
     * polymorphic content_id/content_type column.
     *
     * $commentedAt lets a bulk importer (e.g. a WordPress import)
     * preserve a source comment's original date instead of always
     * stamping "now" — every other caller leaves it null.
     */
    public function create(
        ?int $postId,
        ?int $parentId,
        ?int $userId,
        string $guestName,
        string $guestEmail,
        ?string $guestUrl,
        string $content,
        CommentStatus $status,
        ?string $ipAddress,
        ?string $userAgent,
        ?int $pageId = null,
        ?DateTimeImmutable $commentedAt = null,
    ): Comment {
        $now = $commentedAt ?? new DateTimeImmutable();

        $id = $this->database->insertGetId(
            'INSERT INTO ' . $this->table() . '
                (post_id, page_id, parent_id, user_id, guest_name, guest_email, guest_url, content, status, ip_address, user_agent, created_at, updated_at)
             VALUES (:post_id, :page_id, :parent_id, :user_id, :guest_name, :guest_email, :guest_url, :content, :status, :ip_address, :user_agent, :created_at, :updated_at)',
            [
                'post_id' => $postId,
                'page_id' => $pageId,
                'parent_id' => $parentId,
                'user_id' => $userId,
                'guest_name' => $guestName,
                'guest_email' => $guestEmail,
                'guest_url' => $guestUrl,
                'content' => $content,
                'status' => $status->value,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ],
        );

        $comment = $this->findById((int) $id);

        if ($comment === null) {
            throw new RuntimeException('Failed to load the comment that was just created.');
        }

        return $comment;
    }

    /**
     * Only stamps edited_at when the text actually changes, so saving the
     * edit form untouched never marks a comment "(edited)" publicly.
     * Importers pass $markEdited = false: re-syncing from a source site
     * isn't an edit made here.
     */
    public function updateContent(int $id, string $content, bool $markEdited = true): Comment
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->database->execute(
            'UPDATE ' . $this->table() . ' SET content = :content, updated_at = :updated_at'
                . ($markEdited ? ', edited_at = :edited_at' : '')
                . ' WHERE id = :id AND content <> :unchanged',
            ['content' => $content, 'updated_at' => $now, 'id' => $id, 'unchanged' => $content] + ($markEdited ? ['edited_at' => $now] : []),
        );

        $comment = $this->findById($id);

        if ($comment === null) {
            throw new RuntimeException('Failed to load the comment that was just updated.');
        }

        return $comment;
    }

    /**
     * Claims the one-time right to send $id's follow-up notifications
     * (subscribers, in-app) — the conditional UPDATE means a comment
     * approved, unapproved, and approved again never notifies twice, and
     * two concurrent approvals can't both win.
     */
    public function claimFollowup(int $id): bool
    {
        return $this->database->execute(
            'UPDATE ' . $this->table() . ' SET followup_notified_at = :now WHERE id = :id AND followup_notified_at IS NULL',
            ['now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        ) > 0;
    }

    /**
     * Listeners on 'comment_status_changed' also receive the status the
     * comment had before (null if it couldn't be read), so a plugin can
     * tell an approval from an unapproval.
     */
    public function updateStatus(int $id, CommentStatus $status): Comment
    {
        $previousStatus = $this->findById($id)?->status;

        $this->database->execute(
            'UPDATE ' . $this->table() . ' SET status = :status, updated_at = :updated_at WHERE id = :id',
            ['status' => $status->value, 'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        );

        $comment = $this->findById($id);

        if ($comment === null) {
            throw new RuntimeException('Failed to load the comment that was just updated.');
        }

        $this->hooks?->doAction('comment_status_changed', $comment, $previousStatus);

        return $comment;
    }

    /**
     * Orphans any replies (their parent_id becomes NULL rather than
     * cascading the delete to them, the same trade-off CategoryService
     * makes for child categories) then deletes the comment itself.
     */
    public function delete(int $id): bool
    {
        $deleted = (bool) $this->database->transaction(function () use ($id): int {
            $this->database->execute(
                'UPDATE ' . $this->table() . ' SET parent_id = NULL WHERE parent_id = :parent_id',
                ['parent_id' => $id],
            );

            $this->database->execute('DELETE FROM ' . $this->metaTable() . ' WHERE comment_id = :comment_id', ['comment_id' => $id]);

            return $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);
        });

        if ($deleted) {
            $this->hooks?->doAction('comment_deleted', $id);
        }

        return $deleted;
    }

    /**
     * Plugin-defined data attached to a comment, one value per key.
     *
     * @return array<string, string>
     */
    public function metaForComment(int $commentId): array
    {
        return $this->metaForComments([$commentId])[$commentId] ?? [];
    }

    /**
     * Meta for many comments in one query — for rendering a whole thread.
     *
     * @param array<int, int> $commentIds
     * @return array<int, array<string, string>> commentId => [key => value]
     */
    public function metaForComments(array $commentIds): array
    {
        $params = [];

        foreach (array_values(array_unique(array_map('intval', $commentIds))) as $index => $id) {
            $params['id_' . $index] = $id;
        }

        if ($params === []) {
            return [];
        }

        $placeholders = implode(', ', array_map(static fn (string $key): string => ':' . $key, array_keys($params)));
        $meta = [];

        foreach ($this->database->fetchAll(
            'SELECT comment_id, meta_key, meta_value FROM ' . $this->metaTable() . " WHERE comment_id IN ({$placeholders}) ORDER BY id ASC",
            $params,
        ) as $row) {
            $meta[(int) $row['comment_id']][(string) $row['meta_key']] = (string) ($row['meta_value'] ?? '');
        }

        return $meta;
    }

    /**
     * Removes meta, reactions, and reports whose comment no longer exists
     * — deleting a whole post drops its comments in one bulk statement
     * that never passes through delete(). Safe to run any time.
     *
     * @return int rows removed
     */
    public function deleteOrphanedCommentData(): int
    {
        $removed = 0;

        foreach (['comment_meta', 'comment_reactions', 'comment_reports'] as $suffix) {
            $removed += $this->database->execute(
                'DELETE FROM ' . $this->tablePrefix . $suffix . ' WHERE comment_id NOT IN (SELECT id FROM ' . $this->table() . ')',
            );
        }

        return $removed;
    }

    public function metaValue(int $commentId, string $key): ?string
    {
        return $this->metaForComment($commentId)[$key] ?? null;
    }

    /**
     * Sets one meta value; null removes the key. Keys are trimmed and
     * capped at 191 characters (the indexed column's length).
     */
    public function setMeta(int $commentId, string $key, ?string $value): void
    {
        $key = mb_substr(trim($key), 0, 191);

        if ($key === '') {
            return;
        }

        $this->database->transaction(function () use ($commentId, $key, $value): void {
            $this->database->execute(
                'DELETE FROM ' . $this->metaTable() . ' WHERE comment_id = :comment_id AND meta_key = :meta_key',
                ['comment_id' => $commentId, 'meta_key' => $key],
            );

            if ($value !== null) {
                $this->database->execute(
                    'INSERT INTO ' . $this->metaTable() . ' (comment_id, meta_key, meta_value) VALUES (:comment_id, :meta_key, :meta_value)',
                    ['comment_id' => $commentId, 'meta_key' => $key, 'meta_value' => $value],
                );
            }
        });
    }

    public function findById(int $id): ?Comment
    {
        $row = $this->database->fetchOne('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Every comment (any status, Posts and Pages, newest first) posted
     * under $email — case-insensitive, matching guest_email's stored
     * value whether the commenter was a guest or a signed-in user (already
     * the real address either way, see this class's own docblock). Backs
     * the Settings > Privacy "Comment Data Requests" GDPR export/erasure
     * tools.
     *
     * @return array<int, Comment>
     */
    public function findAllByEmail(string $email): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . ' WHERE LOWER(guest_email) = LOWER(:guest_email) ORDER BY created_at DESC',
            ['guest_email' => $email],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * GDPR erasure: strips personal identifiers (name, email, website, IP,
     * user agent) from every comment matching $email while keeping the
     * comment content and thread structure intact — an outright delete()
     * would orphan every reply the same way it already does for a single
     * comment, a much larger effect than an erasure request calls for.
     * user_id is left untouched deliberately: unlinking a still-existing
     * account from its own comment history is a "delete my account"
     * concern, not this one. The replacement email is unique per comment
     * (not one shared placeholder) so hasPreviouslyApprovedComment()/
     * flood control/etc. never treat two unrelated erased commenters as
     * the same person afterward.
     *
     * @return int how many comments were anonymized
     */
    public function anonymizeByEmail(string $email): int
    {
        $matches = $this->findAllByEmail($email);

        foreach ($matches as $match) {
            $this->database->execute(
                'UPDATE ' . $this->table() . ' SET guest_name = :guest_name, guest_email = :guest_email, guest_url = NULL, ip_address = NULL, user_agent = NULL, updated_at = :updated_at WHERE id = :id',
                [
                    'guest_name' => __('Anonymous'),
                    'guest_email' => 'anonymized-' . $match->id . '@removed.invalid',
                    'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'id' => $match->id,
                ],
            );
        }

        return count($matches);
    }

    /**
     * Permanently deletes every comment currently at the given status via
     * delete(), so each one gets the same reply-orphaning cleanup a
     * single per-comment delete would — not a bare bulk DELETE. Unlike
     * Categories/Posts/Pages, Trash here is a status value rather than a
     * trashed_at column (see this class's own docblock on Comment status).
     * Backs both emptyTrash() (Trash) and the Empty Spam action (Spam) —
     * there is nothing Trash-specific about this implementation.
     *
     * @return int how many comments were removed
     */
    public function emptyByStatus(CommentStatus $status): int
    {
        $matchingIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->database->fetchAll(
                'SELECT id FROM ' . $this->table() . ' WHERE status = :status',
                ['status' => $status->value],
            ),
        );

        $removed = 0;

        foreach ($matchingIds as $matchingId) {
            if ($this->delete($matchingId)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @return int how many comments were removed
     */
    public function emptyTrash(): int
    {
        return $this->emptyByStatus(CommentStatus::Trash);
    }

    public function countByStatus(CommentStatus $status): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE status = :status',
            ['status' => $status->value],
        );
    }

    /**
     * Non-trashed comments posted per day over the last $days days,
     * oldest first, zero-filled for a day with none — mirrors the
     * Visitor Stats plugin's own PostViewService::dailyTotals() shape.
     *
     * @return array<int, array{date: string, count: int}>
     */
    public function dailyTotals(int $days): array
    {
        $since = date('Y-m-d', strtotime('-' . max(0, $days - 1) . ' days'));

        $rows = $this->database->fetchAll(
            'SELECT DATE(created_at) AS day, COUNT(*) AS count FROM ' . $this->table() . "
                WHERE created_at >= :since AND status != 'trash'
             GROUP BY DATE(created_at)",
            ['since' => $since . ' 00:00:00'],
        );

        $byDate = [];

        foreach ($rows as $row) {
            $byDate[(string) $row['day']] = (int) $row['count'];
        }

        $totals = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = date('Y-m-d', strtotime('-' . $offset . ' days'));
            $totals[] = ['date' => $date, 'count' => $byDate[$date] ?? 0];
        }

        return $totals;
    }

    /**
     * The most frequent commenters over the last $days days, by
     * guest_email — already the real address either way, registered
     * account or guest, per SiteController's own guest_email assignment
     * (see CommentModerationService's identical reasoning). MAX(guest_name)
     * rather than a bare guest_name, since a name isn't functionally
     * dependent on the email it's grouped by (the same person could have
     * typed their name slightly differently across comments).
     *
     * @return array<int, array{name: string, email: string, count: int}>
     */
    public function topCommenters(int $days, int $limit = 10): array
    {
        $since = date('Y-m-d H:i:s', strtotime('-' . max(0, $days) . ' days'));

        $rows = $this->database->fetchAll(
            'SELECT MAX(guest_name) AS name, guest_email AS email, COUNT(*) AS count FROM ' . $this->table() . "
                WHERE created_at >= :since AND status != 'trash'
             GROUP BY guest_email
             ORDER BY count DESC, name ASC
             LIMIT " . (int) $limit,
            ['since' => $since],
        );

        return array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'count' => (int) $row['count'],
        ], $rows);
    }

    /**
     * The posts/pages with the most comments over the last $days days.
     *
     * @return array<int, array{postId: ?int, pageId: ?int, count: int}>
     */
    public function topCommentedContent(int $days, int $limit = 10): array
    {
        $since = date('Y-m-d H:i:s', strtotime('-' . max(0, $days) . ' days'));

        $rows = $this->database->fetchAll(
            'SELECT post_id, page_id, COUNT(*) AS count FROM ' . $this->table() . "
                WHERE created_at >= :since AND status != 'trash'
             GROUP BY post_id, page_id
             ORDER BY count DESC
             LIMIT " . (int) $limit,
            ['since' => $since],
        );

        return array_map(static fn (array $row): array => [
            'postId' => $row['post_id'] !== null ? (int) $row['post_id'] : null,
            'pageId' => $row['page_id'] !== null ? (int) $row['page_id'] : null,
            'count' => (int) $row['count'],
        ], $rows);
    }

    public function countForPost(int $postId, CommentStatus $status = CommentStatus::Approved): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE post_id = :post_id AND status = :status',
            ['post_id' => $postId, 'status' => $status->value],
        );
    }

    /**
     * Batched countForPost() for a listing page — one query for every
     * post shown rather than one per row. Missing/zero-comment post ids
     * are simply absent from the result rather than present with 0, so
     * callers should read it via ($counts[$postId] ?? 0).
     *
     * @param array<int, int> $postIds
     * @return array<int, int>
     */
    public function countsForPosts(array $postIds, CommentStatus $status = CommentStatus::Approved): array
    {
        $postIds = array_values(array_unique(array_map('intval', $postIds)));

        if ($postIds === []) {
            return [];
        }

        $placeholders = [];
        $params = ['status' => $status->value];

        foreach ($postIds as $i => $postId) {
            $key = "post_id_{$i}";
            $placeholders[] = ':' . $key;
            $params[$key] = $postId;
        }

        $rows = $this->database->fetchAll(
            'SELECT post_id, COUNT(*) AS comment_count FROM ' . $this->table()
            . ' WHERE post_id IN (' . implode(',', $placeholders) . ') AND status = :status'
            . ' GROUP BY post_id',
            $params,
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['post_id']] = (int) $row['comment_count'];
        }

        return $counts;
    }

    /**
     * Mirrors countForPost() exactly, for Pages.
     */
    public function countForPage(int $pageId, CommentStatus $status = CommentStatus::Approved): int
    {
        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE page_id = :page_id AND status = :status',
            ['page_id' => $pageId, 'status' => $status->value],
        );
    }

    /**
     * All comments for the admin moderation screen, newest first, each
     * paired with the title/slug of whichever post or page it belongs
     * to (one LEFT JOIN per content type rather than one lookup per
     * row — LEFT, not INNER, since exactly one of post_id/page_id is
     * ever set per comment, see Comment's own docblock).
     *
     * $search matches comment content, guest name, or guest email
     * (substring, case-insensitive per the column collation). $userId
     * matches only comments from that registered user — a guest comment
     * always has a NULL user_id, so this filter naturally excludes every
     * guest comment while it's active, not a bug. $ipAddress is an exact
     * match, the same convention the IP Blacklist field already uses.
     * $dateFrom/$dateTo are inclusive 'Y-m-d' bounds on created_at.
     *
     * @return array{comments: array<int, array{comment: Comment, contentTitle: string, contentSlug: string, contentType: string}>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateForAdmin(
        int $page = 1,
        int $perPage = 20,
        ?CommentStatus $statusFilter = null,
        ?string $search = null,
        ?int $userId = null,
        ?string $ipAddress = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        bool $reportedOnly = false,
    ): array {
        $page = max(1, $page);

        // Matches PostService::paginateForAdmin()'s "All excludes Trash"
        // convention, so the Comments screen's "All" tab count can be
        // stated as a sum of the other three tabs' own status labels.
        $conditions = [$statusFilter !== null ? 'c.status = :status' : "c.status != 'trash'"];
        $params = $statusFilter !== null ? ['status' => $statusFilter->value] : [];

        if ($search !== null && $search !== '') {
            // Three distinct placeholders for the same value: MySQL's real
            // prepared statements (PDO::ATTR_EMULATE_PREPARES => false)
            // reject a repeated named placeholder.
            $conditions[] = '(c.content LIKE :search_content OR c.guest_name LIKE :search_name OR c.guest_email LIKE :search_email)';
            $params['search_content'] = '%' . $search . '%';
            $params['search_name'] = '%' . $search . '%';
            $params['search_email'] = '%' . $search . '%';
        }

        if ($userId !== null) {
            $conditions[] = 'c.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        if ($ipAddress !== null && $ipAddress !== '') {
            $conditions[] = 'c.ip_address = :ip_address';
            $params['ip_address'] = $ipAddress;
        }

        if ($dateFrom !== null && $dateFrom !== '') {
            $conditions[] = 'c.created_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null && $dateTo !== '') {
            $conditions[] = 'c.created_at <= :date_to';
            $params['date_to'] = $dateTo . ' 23:59:59';
        }

        if ($reportedOnly) {
            $conditions[] = 'c.id IN (SELECT comment_id FROM ' . $this->tablePrefix . 'comment_reports)';
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $total = (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' c ' . $where,
            $params,
        );

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $rows = $this->database->fetchAll(
            'SELECT c.*, p.title AS post_title, p.slug AS post_slug, pg.title AS page_title, pg.slug AS page_slug
               FROM ' . $this->table() . ' c
               LEFT JOIN ' . $this->postsTable() . ' p ON p.id = c.post_id
               LEFT JOIN ' . $this->pagesTable() . ' pg ON pg.id = c.page_id
               ' . $where . '
              ORDER BY c.created_at DESC, c.id DESC
              LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params,
        );

        return [
            'comments' => array_map($this->hydrateWithContent(...), $rows),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * @return array<int, array{comment: Comment, contentTitle: string, contentSlug: string, contentType: string}>
     */
    public function recentForAdmin(int $limit = 5): array
    {
        $rows = $this->database->fetchAll(
            'SELECT c.*, p.title AS post_title, p.slug AS post_slug, pg.title AS page_title, pg.slug AS page_slug
               FROM ' . $this->table() . ' c
               LEFT JOIN ' . $this->postsTable() . ' p ON p.id = c.post_id
               LEFT JOIN ' . $this->pagesTable() . ' pg ON pg.id = c.page_id
              ORDER BY c.created_at DESC, c.id DESC
              LIMIT ' . (int) $limit,
        );

        return array_map($this->hydrateWithContent(...), $rows);
    }

    /**
     * The most recent approved comments site-wide (for the Recent
     * Comments widget and the site-wide comments feed) — unlike
     * recentForAdmin(), which intentionally includes every status for
     * moderators, this must never surface a pending/spam/trashed comment
     * to public site visitors. Also excludes a comment whose post/page is
     * itself not publicly visible (trashed, draft, scheduled-not-yet-due,
     * or Private) — trashing a post doesn't delete its comments (only a
     * permanent delete does, see PostService::delete()), so without this
     * a private or trashed post's approved comments would otherwise still
     * leak here even though the post itself is correctly gated elsewhere.
     *
     * @return array<int, array{comment: Comment, contentTitle: string, contentSlug: string, contentType: string}>
     */
    public function recentApproved(int $limit = 5): array
    {
        $rows = $this->database->fetchAll(
            'SELECT c.*, p.title AS post_title, p.slug AS post_slug, pg.title AS page_title, pg.slug AS page_slug
               FROM ' . $this->table() . ' c
               LEFT JOIN ' . $this->postsTable() . ' p ON p.id = c.post_id
               LEFT JOIN ' . $this->pagesTable() . ' pg ON pg.id = c.page_id
              WHERE c.status = :status
                AND (
                    (c.post_id IS NOT NULL AND p.status = :post_status AND p.visibility = :post_visibility)
                    OR (c.page_id IS NOT NULL AND pg.status = :page_status AND pg.visibility = :page_visibility)
                )
              ORDER BY c.created_at DESC, c.id DESC
              LIMIT ' . (int) $limit,
            [
                'status' => CommentStatus::Approved->value,
                'post_status' => PostStatus::Published->value,
                'post_visibility' => PostVisibility::Public->value,
                'page_status' => PageStatus::Published->value,
                'page_visibility' => PageVisibility::Public->value,
            ],
        );

        return array_map($this->hydrateWithContent(...), $rows);
    }

    /**
     * Approved comments for a post, built into a nested reply tree.
     * Oldest first within each level, matching the classic default
     * comment order.
     *
     * @return array<int, array{comment: Comment, children: array<mixed>}>
     */
    public function publicTreeForPost(int $postId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . '
                WHERE post_id = :post_id AND status = :status
             ORDER BY created_at ASC, id ASC',
            ['post_id' => $postId, 'status' => CommentStatus::Approved->value],
        );

        $comments = array_map($this->hydrate(...), $rows);

        return $this->buildTree($comments, null);
    }

    /**
     * Mirrors publicTreeForPost() exactly, for Pages.
     *
     * @return array<int, array{comment: Comment, children: array<mixed>}>
     */
    public function publicTreeForPage(int $pageId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . '
                WHERE page_id = :page_id AND status = :status
             ORDER BY created_at ASC, id ASC',
            ['page_id' => $pageId, 'status' => CommentStatus::Approved->value],
        );

        $comments = array_map($this->hydrate(...), $rows);

        return $this->buildTree($comments, null);
    }

    /**
     * Approved comments for a post as a paginated, orderable thread list. Pagination counts
     * top-level threads (a comment plus its nested replies is one page entry), not raw row
     * count, so a reply is never split from its parent mid-thread. When $threaded is false,
     * nesting is ignored and every comment paginates as its own flat entry.
     *
     * @return array{comments: array<int, array{comment: Comment, children: array<mixed>}>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateForPost(int $postId, int $page = 1, int $perPage = 50, string $order = 'asc', bool $threaded = true): array
    {
        $direction = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . "
                WHERE post_id = :post_id AND status = :status
             ORDER BY created_at {$direction}, id {$direction}",
            ['post_id' => $postId, 'status' => CommentStatus::Approved->value],
        );

        $comments = array_map($this->hydrate(...), $rows);

        $entries = $threaded
            ? $this->buildTree($comments, null)
            : array_map(static fn (Comment $comment): array => ['comment' => $comment, 'children' => []], $comments);

        $total = count($entries);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);

        return [
            'comments' => array_slice($entries, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * Mirrors paginateForPost() exactly, for Pages.
     *
     * @return array{comments: array<int, array{comment: Comment, children: array<mixed>}>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function paginateForPage(int $pageId, int $page = 1, int $perPage = 50, string $order = 'asc', bool $threaded = true): array
    {
        $direction = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $rows = $this->database->fetchAll(
            'SELECT * FROM ' . $this->table() . "
                WHERE page_id = :page_id AND status = :status
             ORDER BY created_at {$direction}, id {$direction}",
            ['page_id' => $pageId, 'status' => CommentStatus::Approved->value],
        );

        $comments = array_map($this->hydrate(...), $rows);

        $entries = $threaded
            ? $this->buildTree($comments, null)
            : array_map(static fn (Comment $comment): array => ['comment' => $comment, 'children' => []], $comments);

        $total = count($entries);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);

        return [
            'comments' => array_slice($entries, ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * "Comment author must have a previously approved comment" — the
     * lightweight, standard trust signal used to auto-approve without
     * requiring a login system for public commenters.
     */
    public function hasPreviouslyApprovedComment(?int $userId, ?string $guestEmail): bool
    {
        if ($userId !== null) {
            return $this->database->fetchOne(
                'SELECT id FROM ' . $this->table() . ' WHERE user_id = :user_id AND status = :status',
                ['user_id' => $userId, 'status' => CommentStatus::Approved->value],
            ) !== null;
        }

        if ($guestEmail === null || $guestEmail === '') {
            return false;
        }

        return $this->database->fetchOne(
            'SELECT id FROM ' . $this->table() . ' WHERE LOWER(guest_email) = LOWER(:guest_email) AND status = :status',
            ['guest_email' => $guestEmail, 'status' => CommentStatus::Approved->value],
        ) !== null;
    }

    /**
     * Basic flood control: rejects a submission if this IP has posted a
     * comment (any status) within the last $windowSeconds.
     */
    public function recentCommentFromIpExists(string $ipAddress, int $windowSeconds): bool
    {
        $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);

        return $this->database->fetchOne(
            'SELECT id FROM ' . $this->table() . ' WHERE ip_address = :ip_address AND created_at >= :window_start',
            ['ip_address' => $ipAddress, 'window_start' => $windowStart],
        ) !== null;
    }

    /**
     * Same window as recentCommentFromIpExists(), but a count rather than
     * a single-window existence check — for a graduated posting-frequency
     * signal (used by anti-spam plugins) rather than a flat yes/no.
     */
    public function countRecentFromIp(string $ipAddress, int $windowSeconds): int
    {
        $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);

        return (int) $this->database->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE ip_address = :ip_address AND created_at >= :window_start',
            ['ip_address' => $ipAddress, 'window_start' => $windowStart],
        );
    }

    /**
     * Mirrors hasPreviouslyApprovedComment()'s exact user_id/guest_email
     * preference and case-insensitive email match, checking Spam status
     * instead of Approved — a prior-spam trust signal for anti-spam
     * plugins.
     */
    public function hasPreviousSpamHistory(?int $userId, ?string $guestEmail): bool
    {
        if ($userId !== null) {
            return $this->database->fetchOne(
                'SELECT id FROM ' . $this->table() . ' WHERE user_id = :user_id AND status = :status',
                ['user_id' => $userId, 'status' => CommentStatus::Spam->value],
            ) !== null;
        }

        if ($guestEmail === null || $guestEmail === '') {
            return false;
        }

        return $this->database->fetchOne(
            'SELECT id FROM ' . $this->table() . ' WHERE LOWER(guest_email) = LOWER(:guest_email) AND status = :status',
            ['guest_email' => $guestEmail, 'status' => CommentStatus::Spam->value],
        ) !== null;
    }

    /**
     * Whether this exact content string already exists as some other
     * comment (any status, any post/page) — classic copy-pasted-spam
     * behavior. Not scoped to "a *different* post" specifically: the
     * 'comment_is_spam' filter's signature carries no post/page ID at
     * all, so a plugin calling this from that filter has no way to
     * exclude "the post being commented on right now" in the first
     * place — an exact byte-for-byte match already present anywhere is
     * suspicious enough on its own for a brand-new submission.
     */
    public function identicalContentExists(string $content): bool
    {
        return $this->database->fetchOne(
            'SELECT id FROM ' . $this->table() . ' WHERE content = :content',
            ['content' => $content],
        ) !== null;
    }

    /**
     * @param array<int, Comment> $comments
     * @return array<int, array{comment: Comment, children: array<mixed>}>
     */
    private function buildTree(array $comments, ?int $parentId): array
    {
        $branch = [];

        foreach ($comments as $comment) {
            if ($comment->parentId !== $parentId) {
                continue;
            }

            $branch[] = [
                'comment' => $comment,
                'children' => $this->buildTree($comments, $comment->id),
            ];
        }

        return $branch;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Comment
    {
        return new Comment(
            id: (int) $row['id'],
            postId: $row['post_id'] !== null ? (int) $row['post_id'] : null,
            parentId: $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            userId: $row['user_id'] !== null ? (int) $row['user_id'] : null,
            guestName: (string) $row['guest_name'],
            guestEmail: (string) $row['guest_email'],
            guestUrl: $row['guest_url'] !== null ? (string) $row['guest_url'] : null,
            content: (string) $row['content'],
            status: CommentStatus::from((string) $row['status']),
            ipAddress: $row['ip_address'] !== null ? (string) $row['ip_address'] : null,
            userAgent: $row['user_agent'] !== null ? (string) $row['user_agent'] : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
            pageId: isset($row['page_id']) && $row['page_id'] !== null ? (int) $row['page_id'] : null,
            editedAt: isset($row['edited_at']) ? new DateTimeImmutable((string) $row['edited_at']) : null,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{comment: Comment, contentTitle: string, contentSlug: string, contentType: string}
     */
    private function hydrateWithContent(array $row): array
    {
        $isPage = $row['page_id'] !== null;

        return [
            'comment' => $this->hydrate($row),
            'contentTitle' => (string) ($isPage ? $row['page_title'] : $row['post_title']),
            'contentSlug' => (string) ($isPage ? $row['page_slug'] : $row['post_slug']),
            'contentType' => $isPage ? 'page' : 'post',
        ];
    }

    private function table(): string
    {
        return $this->tablePrefix . 'comments';
    }

    private function metaTable(): string
    {
        return $this->tablePrefix . 'comment_meta';
    }

    private function postsTable(): string
    {
        return $this->tablePrefix . 'posts';
    }

    private function pagesTable(): string
    {
        return $this->tablePrefix . 'pages';
    }
}
