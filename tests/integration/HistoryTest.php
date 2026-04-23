<?php
declare(strict_types=1);

require_once __DIR__ . '/../../chatService.php';

use PHPUnit\Framework\TestCase;

final class HistoryTest extends TestCase
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
        $this->conn->exec(
            'UPDATE stats SET total_messages = 0, total_chars = 0, total_users = 0 WHERE id = 1'
        );
    }

    public function testFetchLastNReturnsEmptyOnEmptyTable(): void
    {
        $this->assertSame([], fetch_last_n_messages($this->conn, 50));
    }

    public function testFetchLastNReturnsAllWhenFewerThanN(): void
    {
        insert_message($this->conn, 'first', 'alice');
        insert_message($this->conn, 'second', 'bob');
        insert_message($this->conn, 'third', 'carol');

        $history = fetch_last_n_messages($this->conn, 50);

        $this->assertCount(3, $history);
        $this->assertSame('first', $history[0]['message']);
        $this->assertSame('second', $history[1]['message']);
        $this->assertSame('third', $history[2]['message']);
    }

    public function testFetchLastNReturnsLatestNInAscOrder(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            insert_message($this->conn, "msg{$i}", 'alice');
        }

        $history = fetch_last_n_messages($this->conn, 50);

        $this->assertCount(50, $history);
        $this->assertSame('msg11', $history[0]['message']);
        $this->assertSame('msg60', $history[49]['message']);

        $ids = array_map(fn ($r) => (int) $r['id'], $history);
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
    }

    public function testCleanupMessageHistoryDeletesAll(): void
    {
        insert_message($this->conn, 'first', 'alice');
        insert_message($this->conn, 'second', 'bob');
        insert_message($this->conn, 'third', 'carol');

        $deleted = cleanup_message_history($this->conn);
        $this->assertSame(3, $deleted);

        $count = (int) $this->conn->query('SELECT COUNT(*) FROM messages')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testCleanupPreservesStats(): void
    {
        insert_message($this->conn, 'abc', 'alice');
        insert_message($this->conn, 'defgh', 'bob');

        $before = get_stats($this->conn);
        cleanup_message_history($this->conn);
        $after = get_stats($this->conn);

        $this->assertSame((int) $before['total_messages'], (int) $after['total_messages']);
        $this->assertSame((int) $before['total_chars'], (int) $after['total_chars']);
        $this->assertSame(2, (int) $after['total_messages']);
        $this->assertSame(8, (int) $after['total_chars']);
    }
}
