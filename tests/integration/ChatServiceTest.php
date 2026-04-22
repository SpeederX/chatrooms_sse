<?php
declare(strict_types=1);

require_once __DIR__ . '/../../chatService.php';

use PHPUnit\Framework\TestCase;

final class ChatServiceTest extends TestCase
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
    }

    public function testMaxMessageIdOnEmptyTable(): void
    {
        $this->assertSame(0, max_message_id($this->conn));
    }

    public function testInsertMessageWritesRow(): void
    {
        $id = insert_message($this->conn, 'hello', '12-34-56');
        $this->assertGreaterThan(0, $id);

        $stmt = $this->conn->prepare('SELECT message, user_id FROM messages WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('hello', $row['message']);
        $this->assertSame('12-34-56', $row['user_id']);
    }

    public function testFetchMessagesSinceReturnsOnlyNewer(): void
    {
        $id1 = insert_message($this->conn, 'first', 'a');
        $id2 = insert_message($this->conn, 'second', 'b');
        $id3 = insert_message($this->conn, 'third', 'c');

        $newer = fetch_messages_since($this->conn, $id1);

        $this->assertCount(2, $newer);
        $this->assertSame($id2, (int) $newer[0]['id']);
        $this->assertSame($id3, (int) $newer[1]['id']);
        $this->assertSame('second', $newer[0]['message']);
        $this->assertSame('third', $newer[1]['message']);
    }

    public function testFetchMessagesSinceEmptyWhenCursorAtLatest(): void
    {
        $id = insert_message($this->conn, 'only', 'x');
        $this->assertSame([], fetch_messages_since($this->conn, $id));
    }
}
