<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Elonn\Time\Database;

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
Dotenv::createImmutable(BASE_PATH)->safeLoad();
$config = require BASE_PATH . '/config/config.php';

final class TimeDatabaseTest
{
    private \PDO $pdo;
    /** @var array<int, array{status: string, message: string}> */
    private array $results = [];

    public function __construct(array $config)
    {
        $this->pdo = Database::connect($config['database'])->pdo();
    }

    public function run(): void
    {
        echo "Running time.elonn.local database tests...\n\n";
        $this->testConnection();
        $this->testSchema();
        $this->testCalendarCrud();
        $this->testEventCrud();
        $this->testSocialImportCrud();
        $this->report();
    }

    private function testConnection(): void
    {
        echo "Testing DB connection... ";
        try {
            $val = $this->pdo->query('SELECT 1')->fetchColumn();
            (int) $val === 1
                ? $this->pass('Connected.')
                : $this->fail('SELECT 1 returned: ' . var_export($val, true));
        } catch (\Throwable $e) {
            $this->fail('Connection failed: ' . $e->getMessage());
        }
    }

    private function testSchema(): void
    {
        echo "Verifying core tables... ";
        $required = ['time_calendars', 'time_events'];
        $missing  = [];
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1'
            );
            foreach ($required as $table) {
                $stmt->execute([':table' => $table]);
                if ($stmt->fetchColumn() === false) {
                    $missing[] = $table;
                }
            }
            $missing === []
                ? $this->pass('All required tables present.')
                : $this->fail('Missing tables: ' . implode(', ', $missing));
        } catch (\Throwable $e) {
            $this->fail('Schema check threw: ' . $e->getMessage());
        }
    }

    private function testCalendarCrud(): void
    {
        echo "Testing time_calendars CRUD... ";
        $userId = 'test_' . bin2hex(random_bytes(8));
        $now    = date('Y-m-d H:i:s');
        try {
            $this->pdo->beginTransaction();

            $insert = $this->pdo->prepare(
                "INSERT INTO time_calendars (identity_user_id, name, status, created_at)
                 VALUES (:uid, :name, 'active', :now)"
            );
            $insert->execute([':uid' => $userId, ':name' => 'My Calendar', ':now' => $now]);
            $calId = (int) $this->pdo->lastInsertId();

            $sel = $this->pdo->prepare('SELECT name, status FROM time_calendars WHERE id = :id');
            $sel->execute([':id' => $calId]);
            $row = $sel->fetch(\PDO::FETCH_ASSOC);
            if ($row === false || $row['name'] !== 'My Calendar' || $row['status'] !== 'active') {
                $this->pdo->rollBack();
                $this->fail('Calendar not found or data mismatch.');
                return;
            }

            $upd = $this->pdo->prepare("UPDATE time_calendars SET name = :name WHERE id = :id");
            $upd->execute([':name' => 'Renamed Calendar', ':id' => $calId]);
            $sel->execute([':id' => $calId]);
            $updated = $sel->fetch(\PDO::FETCH_ASSOC);
            if ($updated === false || $updated['name'] !== 'Renamed Calendar') {
                $this->pdo->rollBack();
                $this->fail('Calendar name update did not persist.');
                return;
            }

            $this->pdo->rollBack();
            $this->pass('Calendar CRUD succeeded (rolled back).');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->fail('Calendar CRUD threw: ' . $e->getMessage());
        }
    }

    private function testEventCrud(): void
    {
        echo "Testing time_events CRUD... ";
        $userId = 'test_' . bin2hex(random_bytes(8));
        $now    = date('Y-m-d H:i:s');
        try {
            $this->pdo->beginTransaction();

            $calInsert = $this->pdo->prepare(
                "INSERT INTO time_calendars (identity_user_id, name, status, created_at)
                 VALUES (:uid, 'Test Cal', 'active', :now)"
            );
            $calInsert->execute([':uid' => $userId, ':now' => $now]);
            $calId = (int) $this->pdo->lastInsertId();

            $evInsert = $this->pdo->prepare(
                "INSERT INTO time_events (identity_user_id, calendar_id, title, starts_at, ends_at, status, created_at)
                 VALUES (:uid, :cal_id, :title, :starts, :ends, 'active', :now)"
            );
            $evInsert->execute([
                ':uid'    => $userId,
                ':cal_id' => $calId,
                ':title'  => 'Test Event',
                ':starts' => date('Y-m-d H:i:s', strtotime('+1 hour')),
                ':ends'   => date('Y-m-d H:i:s', strtotime('+2 hours')),
                ':now'    => $now,
            ]);
            $evId = (int) $this->pdo->lastInsertId();

            $sel = $this->pdo->prepare('SELECT title, calendar_id FROM time_events WHERE id = :id');
            $sel->execute([':id' => $evId]);
            $row = $sel->fetch(\PDO::FETCH_ASSOC);
            if ($row === false || $row['title'] !== 'Test Event' || (int) $row['calendar_id'] !== $calId) {
                $this->pdo->rollBack();
                $this->fail('Event not found or calendar_id mismatch.');
                return;
            }

            $this->pdo->rollBack();
            $this->pass('Event with calendar reference CRUD succeeded (rolled back).');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->fail('Event CRUD threw: ' . $e->getMessage());
        }
    }

    private function testSocialImportCrud(): void
    {
        echo "Testing social import schema... ";
        $userId = 'test_' . bin2hex(random_bytes(8));
        $now = date('Y-m-d H:i:s');
        try {
            $this->pdo->beginTransaction();

            $calendarInsert = $this->pdo->prepare(
                "INSERT INTO time_calendars
                    (identity_user_id, name, color, timezone, status, source_service, source_object_type, source_object_id, source_url, created_at)
                 VALUES
                    (:uid, 'Social events', '#7c9cff', NULL, 'active', 'social', 'event_feed', 'default', 'https://social.elonn.com/social/events', :now)"
            );
            $calendarInsert->execute([':uid' => $userId, ':now' => $now]);
            $calendarId = (int) $this->pdo->lastInsertId();

            $eventInsert = $this->pdo->prepare(
                "INSERT INTO time_events
                    (identity_user_id, calendar_id, title, description, location, starts_at, ends_at, timezone, all_day, status, source_service, source_object_type, source_object_id, source_url, created_at)
                 VALUES
                    (:uid, :calendar_id, 'Social event', 'Imported from Social', NULL, NULL, NULL, NULL, 1, 'active', 'social', 'event', 'social-123', 'https://social.elonn.com/social/events/123', :now)"
            );
            $eventInsert->execute([':uid' => $userId, ':calendar_id' => $calendarId, ':now' => $now]);

            $sel = $this->pdo->prepare(
                'SELECT title, source_service, source_object_type, source_object_id
                 FROM time_events WHERE identity_user_id = :uid AND source_object_id = :source_object_id'
            );
            $sel->execute([':uid' => $userId, ':source_object_id' => 'social-123']);
            $row = $sel->fetch(\PDO::FETCH_ASSOC);
            if ($row === false || $row['title'] !== 'Social event' || $row['source_service'] !== 'social') {
                $this->pdo->rollBack();
                $this->fail('Imported social event was not persisted correctly.');
                return;
            }

            $this->pdo->rollBack();
            $this->pass('Social import schema CRUD succeeded (rolled back).');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->fail('Social import schema test threw: ' . $e->getMessage());
        }
    }

    private function pass(string $msg): void
    {
        echo "PASS\n";
        $this->results[] = ['status' => 'PASS', 'message' => $msg];
    }

    private function fail(string $msg): void
    {
        echo "FAIL\n";
        $this->results[] = ['status' => 'FAIL', 'message' => $msg];
    }

    private function report(): void
    {
        echo "\n" . str_repeat('=', 40) . "\n";
        $total  = count($this->results);
        $passed = count(array_filter($this->results, static fn ($r) => $r['status'] === 'PASS'));
        $failed = $total - $passed;
        echo "Total: $total  Passed: $passed  Failed: $failed\n\n";
        foreach ($this->results as $r) {
            echo "  {$r['status']}: {$r['message']}\n";
        }
        echo "\n";
        exit($failed > 0 ? 1 : 0);
    }
}

(new TimeDatabaseTest($config))->run();
