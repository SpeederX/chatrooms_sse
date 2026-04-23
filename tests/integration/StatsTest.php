<?php
declare(strict_types=1);

require_once __DIR__ . '/../../chatService.php';

use PHPUnit\Framework\TestCase;

final class StatsTest extends TestCase
{
    private PDO $conn;

    protected function setUp(): void
    {
        $this->conn = new PDO(
            sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                getenv('DB_HOST'),
                getenv('DB_NAME')
            ),
            (string) getenv('DB_USER'),
            (string) getenv('DB_PASS')
        );
        $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->conn->exec('TRUNCATE TABLE messages');
        $this->conn->exec('TRUNCATE TABLE sessions');
        $this->conn->exec('TRUNCATE TABLE seen_users');
        $this->conn->exec(
            'UPDATE stats SET total_messages = 0, total_chars = 0, total_users = 0 WHERE id = 1'
        );
    }

    public function testStatsStartAtZero(): void
    {
        $stats = get_stats($this->conn);
        $this->assertSame(0, (int) $stats['total_messages']);
        $this->assertSame(0, (int) $stats['total_chars']);
        $this->assertSame(0, (int) $stats['total_users']);
    }

    public function testGetStatsReturnsSingletonShape(): void
    {
        $stats = get_stats($this->conn);
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_messages', $stats);
        $this->assertArrayHasKey('total_chars', $stats);
        $this->assertArrayHasKey('total_users', $stats);
    }

    public function testActiveUsersNowCountsWithin12Min(): void
    {
        $this->seedSession('fresh1', '0 MINUTE');
        $this->seedSession('fresh2', '5 MINUTE');
        $this->seedSession('stale', '13 MINUTE');

        $this->assertSame(2, active_users_now($this->conn));
    }

    public function testActiveUsersNowIgnoresStaleSessions(): void
    {
        $this->seedSession('stale1', '13 MINUTE');
        $this->seedSession('stale2', '20 MINUTE');

        $this->assertSame(0, active_users_now($this->conn));
    }

    public function testInsertMessageIncrementsCounters(): void
    {
        insert_message($this->conn, 'hello', 'alice');

        $stats = get_stats($this->conn);
        $this->assertSame(1, (int) $stats['total_messages']);
        $this->assertSame(5, (int) $stats['total_chars']);
    }

    public function testInsertMessageAccumulatesAcrossCalls(): void
    {
        insert_message($this->conn, 'abc', 'alice');
        insert_message($this->conn, 'hello world', 'bob');
        insert_message($this->conn, 'x', 'carol');

        $stats = get_stats($this->conn);
        $this->assertSame(3, (int) $stats['total_messages']);
        $this->assertSame(15, (int) $stats['total_chars']);
    }

    public function testCreateSessionIncrementsTotalUsersOnNewNickname(): void
    {
        create_session($this->conn, 'alice');

        $stats = get_stats($this->conn);
        $this->assertSame(1, (int) $stats['total_users']);

        $stmt = $this->conn->query(
            "SELECT COUNT(*) FROM seen_users WHERE nickname = 'alice'"
        );
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testCreateSessionDoesNotIncrementOnRepeatedNickname(): void
    {
        create_session($this->conn, 'alice');
        $this->conn->exec('TRUNCATE TABLE sessions');
        create_session($this->conn, 'alice');

        $stats = get_stats($this->conn);
        $this->assertSame(1, (int) $stats['total_users']);
    }

    private function seedSession(string $nickname, string $minutesAgo): void
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO sessions (id, nickname, created_at, last_seen_at)
             VALUES (?, ?, NOW(), NOW() - INTERVAL {$minutesAgo})"
        );
        $sid = bin2hex(random_bytes(16));
        $stmt->execute([$sid, $nickname]);
    }
}
