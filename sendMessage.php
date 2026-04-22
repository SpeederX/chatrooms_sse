<?php
declare(strict_types=1);

require_once __DIR__ . '/chatService.php';

header('Content-Type: application/json');

try {
    $conn = new PDO(
        "mysql:host={$servername};dbname={$dbname};charset=utf8mb4",
        $username,
        $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body) || !isset($body['message'], $body['user_id'])) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

try {
    $id = insert_message($conn, (string) $body['message'], (string) $body['user_id']);
    echo json_encode(['ok' => true, 'id' => $id]);
} catch (PDOException) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
