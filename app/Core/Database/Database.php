<?php

/**
 * Thin, reusable PDO wrapper; every query in the application goes through prepared statements here.
 *
 * @package LumoraPress
 * @subpackage Database
 * @author Ariane
 * @copyright Copyright (c) 2026 Ariane
 * @license GPL-3.0-or-later
 * @link https://coding.unloved-heart.net/scripts/lumorapress
 * @source https://github.com/intothisshadow/LumoraPress
 * @since 0.5.0
 */

declare(strict_types=1);

namespace LumoraPress\Core\Database;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Thin, reusable PDO wrapper. All queries go through prepared statements;
 * no raw user input should ever be concatenated into SQL passed here.
 */
final class Database
{
    private function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * Connects to a MySQL/MariaDB server over PDO. This is the constructor
     * used everywhere in the running application.
     */
    public static function connect(
        string $host,
        string $database,
        string $username,
        string $password,
        string $charset = 'utf8mb4',
        int $port = 3306,
    ): self {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);

        try {
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new DatabaseConnectionException('Unable to connect to the database.', previous: $exception);
        }

        return new self($pdo);
    }

    /**
     * Wraps an already-open PDO connection (e.g. an in-memory SQLite
     * connection in tests) without going through connect()'s network
     * connection. Production code never calls this directly.
     */
    public static function fromPdo(PDO $pdo): self
    {
        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function fetchColumn(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * @param array<string, mixed> $params
     */
    public function insertGetId(string $sql, array $params = []): string
    {
        $this->query($sql, $params);

        return $this->pdo->lastInsertId();
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }
    }

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }
}
