<?php

declare(strict_types=1);

use Elonn\Time\Database;

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Elonn\\Time\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $path = BASE_PATH . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$command = $argv[1] ?? 'up';
if (!in_array($command, ['up', 'status'], true)) {
    fwrite(STDERR, "Usage: php scripts/migrate.php [up|status]\n");
    exit(1);
}

$pdo = Database::fromEnv(BASE_PATH . '/config/.env')->pdo();
$migrationsPath = BASE_PATH . '/migrations';

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(255) NOT NULL UNIQUE,
        checksum CHAR(64) NOT NULL,
        executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$applied = [];
$statement = $pdo->query('SELECT migration, checksum FROM schema_migrations ORDER BY migration');
foreach ($statement->fetchAll() as $row) {
    $applied[$row['migration']] = $row['checksum'];
}

$files = glob($migrationsPath . '/*.sql') ?: [];
sort($files, SORT_STRING);

if ($command === 'status') {
    if ($files === []) {
        echo "No migration files found.\n";
        exit(0);
    }

    foreach ($files as $file) {
        $migration = basename($file);
        $status = isset($applied[$migration]) ? 'applied' : 'pending';
        echo sprintf("%-10s %s\n", $status, $migration);
    }

    exit(0);
}

$ran = 0;

foreach ($files as $file) {
    $migration = basename($file);
    $sql = trim((string) file_get_contents($file));
    $checksum = hash_file('sha256', $file);

    if (isset($applied[$migration])) {
        if ($applied[$migration] !== $checksum) {
            fwrite(STDERR, "Checksum mismatch for applied migration: {$migration}\n");
            exit(1);
        }

        continue;
    }

    if ($sql === '') {
        echo "Skipping empty migration: {$migration}\n";
        continue;
    }

    echo "Applying {$migration}...\n";
    $pdo->exec($sql);

    $record = $pdo->prepare(
        'INSERT INTO schema_migrations (migration, checksum) VALUES (:migration, :checksum)'
    );
    $record->execute([
        'migration' => $migration,
        'checksum' => $checksum,
    ]);

    $ran++;
}

echo $ran === 0 ? "No pending migrations.\n" : "Applied {$ran} migration(s).\n";
