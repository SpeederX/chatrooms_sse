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

$sid = $_COOKIE['sid'] ?? '';
$session = $sid !== '' ? get_session($conn, $sid) : null;
if ($session === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'no_session']);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body) || !isset($body['message']) || !is_string($body['message'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'empty']);
    exit;
}

$validation = validate_message($body['message']);
if (!$validation['ok']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $validation['error']]);
    exit;
}

$cooldown = advance_cooldown($conn, $sid);
if (!$cooldown['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . $cooldown['wait_seconds']);
    echo json_encode([
        'ok' => false,
        'error' => 'cooldown',
        'wait_seconds' => $cooldown['wait_seconds'],
    ]);
    exit;
}

try {
    touch_session($conn, $sid);
    $id = insert_message($conn, $validation['trimmed'], $session['nickname']);
    echo json_encode(['ok' => true, 'id' => $id]);
} catch (PDOException) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
