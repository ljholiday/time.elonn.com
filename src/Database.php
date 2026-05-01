<?php

declare(strict_types=1);

namespace Elonn\Time;

use PDO;

final class Database
{
    private function __construct(private PDO $pdo)
    {
    }

    public static function fromEnv(string $path): self
    {
        $env = Env::load($path);

        $host = $env['DB_HOST'] ?? '127.0.0.1';
        $port = $env['DB_PORT'] ?? '3306';
        $name = $env['DB_NAME'] ?? 'elonn_time';
        $user = $env['DB_USER'] ?? '';
        $pass = $env['DB_PASS'] ?? '';
        $charset = $env['DB_CHARSET'] ?? 'utf8mb4';

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);

        return new self(new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]));
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
