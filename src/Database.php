<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private PDO $pdo;

    public function __construct(private readonly string $path)
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->pdo = new PDO('sqlite:' . $this->path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON;');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function migrate(string $schemaFile): void
    {
        $schema = file_get_contents($schemaFile);
        if ($schema === false) {
            throw new \RuntimeException('Failed to read schema: ' . $schemaFile);
        }
        $this->pdo->exec($schema);
    }
}
