<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Façade PDO volontairement petite : toutes les données dynamiques sont liées.
 */
final class Database
{
    private ?PDO $pdo = null;

    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $charset = (string) ($this->settings['charset'] ?? 'utf8mb4');
        $socket = (string) ($this->settings['socket'] ?? '');

        if ($socket !== '') {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $socket, $this->settings['database'], $charset);
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->settings['host'] ?? '127.0.0.1',
                (int) ($this->settings['port'] ?? 3306),
                $this->settings['database'] ?? '',
                $charset
            );
        }

        $this->pdo = new PDO($dsn, (string) ($this->settings['username'] ?? ''), (string) ($this->settings['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        return $this->pdo;
    }

    /**
     * @param array<int|string, scalar|null> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $statement = $this->run($sql, $bindings);
        $rows = $statement->fetchAll();
        $statement->closeCursor();
        return $rows;
    }

    /** @param array<int|string, scalar|null> $bindings */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $statement = $this->run($sql, $bindings);
        $row = $statement->fetch();
        $statement->closeCursor();
        return $row === false ? null : $row;
    }

    /** @param array<int|string, scalar|null> $bindings */
    public function execute(string $sql, array $bindings = []): int
    {
        $statement = $this->run($sql, $bindings);
        $affected = $statement->rowCount();
        $statement->closeCursor();
        return $affected;
    }

    /** @param array<int|string, scalar|null> $bindings */
    public function insert(string $sql, array $bindings = []): string
    {
        $this->execute($sql, $bindings);
        return $this->pdo()->lastInsertId();
    }

    /**
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $callback($this);
        }

        $pdo->beginTransaction();
        try {
            $result = $callback($this);
            $pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param array<int|string, scalar|null> $bindings
     */
    private function run(string $sql, array $bindings): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);

        foreach ($bindings as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($parameter, $value, $type);
        }

        $statement->execute();
        return $statement;
    }
}
