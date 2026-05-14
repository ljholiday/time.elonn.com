<?php

declare(strict_types=1);

namespace Elonn\Time;

use PDO;

final class Database
{
    private function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array{driver:string, host:string, port:int, name:string, username:string, password:string, charset:string} $config
     */
    public static function connect(array $config): self
    {
        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            $config['driver'],
            $config['host'],
            $config['port'],
            $config['name'],
            $config['charset']
        );

        return new self(new PDO($dsn, $config['username'], $config['password'], [
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
