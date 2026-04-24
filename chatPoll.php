<?php
declare(strict_types=1);

require_once __DIR__ . '/chatService.php';

@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    @ob_end_clean();
}
ob_implicit_flush(true);

try {
    $conn = new PDO(
        "mysql:host={$servername};dbname={$dbname};charset=utf8mb4",
        $username,
        $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException) {
    http_response_code(500);
    exit;
}

$sid = $_COOKIE['sid'] ?? '';
if ($sid === '' || get_session($conn, $sid) === null) {
    http_response_code(401);
    exit;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

$lastEventId = $_SERVER['HTTP_LAST_EVENT_ID'] ?? null;

echo ": connected\n\n";

if ($lastEventId !== null) {
    $cursor = (int) $lastEventId;
} else {
    $historySize = (int) get_config($conn, 'history_size', 50);
    $history = fetch_last_n_messages($conn, $historySize);
    foreach ($history as $rs) {
        echo "id: {$rs['id']}\n";
        echo "data: {$rs['timestamp']} - {$rs['user_id']}: {$rs['message']}\n\n";
    }
    $cursor = !empty($history) ? (int) end($history)['id'] : 0;
}

$deadline = time() + 60;
while (time() < $deadline) {
    foreach (fetch_messages_since($conn, $cursor) as $rs) {
        echo "id: {$rs['id']}\n";
        echo "data: {$rs['timestamp']} - {$rs['user_id']}: {$rs['message']}\n\n";
        $cursor = (int) $rs['id'];
    }
    touch_session($conn, $sid);
    if (connection_aborted()) {
        break;
    }
    sleep(1);
}
