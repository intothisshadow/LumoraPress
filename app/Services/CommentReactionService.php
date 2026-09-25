<?php

/**
 * Likes and emoji reactions on comments, one per person per comment.
 *
 * @package LumoraPress
 * @subpackage Services
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.18.0
 */

declare(strict_types=1);

namespace LumoraPress\Services;

use DateTimeImmutable;
use LumoraPress\Core\Database\Database;
use LumoraPress\Core\Hooks\HookManager;
use LumoraPress\Core\PressConfig;
use PDOException;

/**
 * A voter is identified by a key rather than a raw user id or cookie, so
 * signed-in users and guests share one uniqueness rule: "u:{id}" for an
 * account, "g:{sha256 of a random cookie}" for a guest. The unique
 * (comment_id, voter_key) index is what actually prevents duplicates;
 * reacting again with the same reaction removes it, and choosing a
 * different one switches it.
 */
final class CommentReactionService
{
    public const MODE_OFF = 'off';

    public const MODE_LIKE = 'like';

    public const MODE_REACTIONS = 'reactions';

    /**
     * Built-in reaction types, key => emoji and label. 'like' doubles as
     * the single button in like-only mode. Plugins add, remove, or
     * reorder types through the 'comment_reaction_types' filter.
     */
    public const DEFAULT_TYPES = [
        'like' => ['emoji' => '👍', 'label' => 'Like'],
        'love' => ['emoji' => '❤️', 'label' => 'Love'],
        'laugh' => ['emoji' => '😂', 'label' => 'Haha'],
        'wow' => ['emoji' => '😮', 'label' => 'Wow'],
        'sad' => ['emoji' => '😢', 'label' => 'Sad'],
    ];

    /** @var array<string, array{emoji: string, label: string}>|null */
    private ?array $types = null;

    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
        private readonly PressConfig $config,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    /**
     * Every reaction type offered in emoji-reactions mode, after the
     * 'comment_reaction_types' filter. Entries with an invalid key (not
     * 1–20 lowercase letters, digits, or underscores, the width of the
     * stored column) or a missing emoji/label are dropped.
     *
     * @return array<string, array{emoji: string, label: string}>
     */
    public function types(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }

        $filtered = $this->hooks?->applyFilters('comment_reaction_types', self::DEFAULT_TYPES) ?? self::DEFAULT_TYPES;
        $types = [];

        foreach (is_array($filtered) ? $filtered : self::DEFAULT_TYPES as $key => $definition) {
            $emoji = is_array($definition) ? trim((string) ($definition['emoji'] ?? '')) : '';
            $label = is_array($definition) ? trim((string) ($definition['label'] ?? '')) : '';

            if (is_string($key) && preg_match('/^[a-z0-9_]{1,20}$/', $key) === 1 && $emoji !== '' && $label !== '') {
                $types[$key] = ['emoji' => $emoji, 'label' => $label];
            }
        }

        return $this->types = $types;
    }

    public function mode(): string
    {
        $mode = (string) $this->config->option('comment_reactions_mode', self::MODE_OFF);

        return in_array($mode, [self::MODE_LIKE, self::MODE_REACTIONS], true) ? $mode : self::MODE_OFF;
    }

    public function isEnabled(): bool
    {
        return $this->mode() !== self::MODE_OFF;
    }

    public function requiresLogin(): bool
    {
        return $this->config->option('comment_reactions_registered_only', '0') === '1';
    }

    /**
     * The reactions offered right now, in display order. Like-only mode
     * always uses the built-in Like, whatever the filter did to it.
     *
     * @return array<string, array{emoji: string, label: string}>
     */
    public function available(): array
    {
        return match ($this->mode()) {
            self::MODE_LIKE => ['like' => self::DEFAULT_TYPES['like']],
            self::MODE_REACTIONS => $this->types(),
            default => [],
        };
    }

    public static function userVoterKey(int $userId): string
    {
        return 'u:' . $userId;
    }

    public static function guestVoterKey(string $cookieToken): string
    {
        return 'g:' . hash('sha256', $cookieToken);
    }

    /**
     * Applies $voterKey's click on $reaction and returns the voter's
     * reaction afterward (null when the click removed it).
     */
    public function toggle(int $commentId, string $reaction, string $voterKey, ?int $userId): ?string
    {
        if (!array_key_exists($reaction, $this->available())) {
            return $this->currentFor($commentId, $voterKey);
        }

        $current = $this->currentFor($commentId, $voterKey);

        if ($current === $reaction) {
            $this->database->execute(
                'DELETE FROM ' . $this->table() . ' WHERE comment_id = :comment_id AND voter_key = :voter_key',
                ['comment_id' => $commentId, 'voter_key' => $voterKey],
            );

            return null;
        }

        if ($current !== null) {
            $this->database->execute(
                'UPDATE ' . $this->table() . ' SET reaction = :reaction WHERE comment_id = :comment_id AND voter_key = :voter_key',
                ['reaction' => $reaction, 'comment_id' => $commentId, 'voter_key' => $voterKey],
            );

            return $reaction;
        }

        try {
            $this->database->execute(
                'INSERT INTO ' . $this->table() . ' (comment_id, reaction, voter_key, user_id, created_at) VALUES (:comment_id, :reaction, :voter_key, :user_id, :created_at)',
                [
                    'comment_id' => $commentId,
                    'reaction' => $reaction,
                    'voter_key' => $voterKey,
                    'user_id' => $userId,
                    'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (PDOException) {
            // A simultaneous click from the same voter already inserted a row.
            return $this->currentFor($commentId, $voterKey);
        }

        return $reaction;
    }

    public function currentFor(int $commentId, string $voterKey): ?string
    {
        $value = $this->database->fetchColumn(
            'SELECT reaction FROM ' . $this->table() . ' WHERE comment_id = :comment_id AND voter_key = :voter_key',
            ['comment_id' => $commentId, 'voter_key' => $voterKey],
        );

        return $value !== null ? (string) $value : null;
    }

    /**
     * Counts for every comment in one query, so a thread of any length
     * costs the same.
     *
     * @param array<int, int> $commentIds
     * @return array<int, array<string, int>> commentId => [reaction => count]
     */
    public function countsFor(array $commentIds): array
    {
        [$placeholders, $params] = self::inList($commentIds);

        if ($placeholders === '') {
            return [];
        }

        $counts = [];

        foreach ($this->database->fetchAll(
            'SELECT comment_id, reaction, COUNT(*) AS total FROM ' . $this->table() . " WHERE comment_id IN ({$placeholders}) GROUP BY comment_id, reaction",
            $params,
        ) as $row) {
            $counts[(int) $row['comment_id']][(string) $row['reaction']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @param array<int, int> $commentIds
     * @return array<int, string> commentId => this voter's reaction
     */
    public function choicesFor(array $commentIds, string $voterKey): array
    {
        [$placeholders, $params] = self::inList($commentIds);

        if ($placeholders === '') {
            return [];
        }

        $params['voter_key'] = $voterKey;
        $choices = [];

        foreach ($this->database->fetchAll(
            'SELECT comment_id, reaction FROM ' . $this->table() . " WHERE voter_key = :voter_key AND comment_id IN ({$placeholders})",
            $params,
        ) as $row) {
            $choices[(int) $row['comment_id']] = (string) $row['reaction'];
        }

        return $choices;
    }

    public function deleteForComment(int $commentId): int
    {
        return $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE comment_id = :comment_id', ['comment_id' => $commentId]);
    }

    /**
     * One named placeholder per id — MySQL's real prepared statements
     * reject a repeated placeholder name.
     *
     * @param array<int, int> $ids
     * @return array{0: string, 1: array<string, int>}
     */
    private static function inList(array $ids): array
    {
        $params = [];

        foreach (array_values(array_unique(array_map('intval', $ids))) as $index => $id) {
            $params['id_' . $index] = $id;
        }

        return [implode(', ', array_map(static fn (string $key): string => ':' . $key, array_keys($params))), $params];
    }

    private function table(): string
    {
        return $this->tablePrefix . 'comment_reactions';
    }
}
