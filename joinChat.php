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
if (!is_array($body) || !isset($body['nickname']) || !is_string($body['nickname'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_nickname']);
    exit;
}

$nickname = $body['nickname'];
$err = validate_nickname($nickname);
if ($err !== null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $err]);
    exit;
}

try {
    cleanup_expired_sessions($conn);
    $sid = create_session($conn, $nickname);
} catch (PDOException $e) {
    if (($e->errorInfo[1] ?? 0) === 1062) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'nickname_taken']);
        exit;
    }
    http_response_code(500);
    echo json_encode(['ok' => false]);
    exit;
}

setcookie('sid', $sid, [
    'httponly' => true,
    'samesite' => 'Strict',
    'path' => '/',
    'secure' => session_cookie_secure(),
]);
echo json_encode(['ok' => true]);
