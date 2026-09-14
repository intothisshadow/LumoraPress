<?php

/**
 * Stores PHP session data in the database instead of the filesystem.
 *
 * @package LumoraPress
 * @subpackage Security
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.15.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Security;

use LumoraPress\Core\Database\Database;
use SessionHandlerInterface;
use Throwable;

/**
 * A database-backed session store, registered via session_set_save_handler()
 * by SessionManager. Storing session data in the database rather than
 * storage/sessions/ means cleanup is driven entirely by PHP's own gc()
 * callback (called per session.gc_probability/gc_divisor, the same ini
 * settings SessionManager already forces on) instead of depending on the
 * host's filesystem/cron setup at all — the file-based approach broke on
 * Debian/Ubuntu-family hosts, which sweep only their own default session
 * path, never a custom one an app redirects to.
 *
 * Every method fails safe (a logged, silent default) rather than throwing:
 * a session read/write is infrastructure, not an auth decision, so a
 * transient database error should degrade to "no session this request"
 * rather than crash the page.
 */
final class DatabaseSessionHandler implements SessionHandlerInterface
{
    public function __construct(
        private readonly Database $database,
        private readonly string $tablePrefix,
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        try {
            $row = $this->database->fetchOne(
                'SELECT data FROM ' . $this->table() . ' WHERE id = :id',
                ['id' => $id],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return '';
        }

        return (string) ($row['data'] ?? '');
    }

    public function write(string $id, string $data): bool
    {
        $now = date('Y-m-d H:i:s');

        try {
            $updated = $this->database->execute(
                'UPDATE ' . $this->table() . ' SET data = :data, last_activity = :last_activity WHERE id = :id',
                ['id' => $id, 'data' => $data, 'last_activity' => $now],
            );

            if ($updated === 0) {
                $this->database->execute(
                    'INSERT INTO ' . $this->table() . ' (id, data, last_activity) VALUES (:id, :data, :last_activity)',
                    ['id' => $id, 'data' => $data, 'last_activity' => $now],
                );
            }
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return false;
        }

        return true;
    }

    public function destroy(string $id): bool
    {
        try {
            $this->database->execute('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return false;
        }

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            return $this->database->execute(
                'DELETE FROM ' . $this->table() . ' WHERE last_activity < :cutoff',
                ['cutoff' => date('Y-m-d H:i:s', time() - $max_lifetime)],
            );
        } catch (Throwable $exception) {
            $this->logFailure($exception);

            return false;
        }
    }

    private function logFailure(Throwable $exception): void
    {
        error_log('[DatabaseSessionHandler] ' . $exception::class . ': ' . $exception->getMessage());
    }

    private function table(): string
    {
        return $this->tablePrefix . 'sessions';
    }
}
