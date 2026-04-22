<?php
declare(strict_types=1);

require_once __DIR__ . '/access.php';

function fetch_messages_since(PDO $conn, int $cursor): array
{
    $stmt = $conn->prepare(
        "SELECT id, user_id, timestamp, message FROM messages WHERE id > ? ORDER BY id ASC"
    );
    $stmt->execute([$cursor]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function insert_message(PDO $conn, string $message, string $user_id): int
{
    $stmt = $conn->prepare(
        "INSERT INTO messages (message, user_id, timestamp) VALUES (?, ?, ?)"
    );
    $stmt->execute([$message, $user_id, date('Y-m-d H:i:s')]);
    return (int) $conn->lastInsertId();
}

function max_message_id(PDO $conn): int
{
    $stmt = $conn->query("SELECT COALESCE(MAX(id), 0) FROM messages");
    return (int) $stmt->fetchColumn();
}
