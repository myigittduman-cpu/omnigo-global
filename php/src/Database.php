<?php
declare(strict_types=1);
namespace Omnigo;

final class Database
{
    public readonly \PDO $pdo;
    public function __construct(array $config)
    {
        $this->pdo = new \PDO($config['dsn'], $config['user'] ?? null, $config['password'] ?? null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if ($this->mysql()) $this->pdo->exec("SET time_zone = '+00:00'");
        else $this->pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL;');
    }
    public function mysql(): bool { return $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql'; }
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }
    public function one(string $sql, array $params = []): ?array { return $this->run($sql, $params)->fetch() ?: null; }
    public function all(string $sql, array $params = []): array { return $this->run($sql, $params)->fetchAll(); }
    public function insertId(): int { return (int)$this->pdo->lastInsertId(); }
    public function lock(): string { return $this->mysql() ? ' FOR UPDATE' : ''; }
    public function transaction(callable $action): mixed
    {
        $this->pdo->beginTransaction();
        try { $result = $action(); $this->pdo->commit(); return $result; }
        catch (\Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
    }
}
