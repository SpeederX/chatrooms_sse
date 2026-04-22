<?php
declare(strict_types=1);

require_once __DIR__ . '/chatService.php';

@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    @ob_end_clean();
}
ob_implicit_flush(true);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

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

$cursor = isset($_SERVER['HTTP_LAST_EVENT_ID'])
    ? (int) $_SERVER['HTTP_LAST_EVENT_ID']
    : max_message_id($conn);

// Flush headers eagerly so EventSource.onopen fires before the first real event.
echo ": connected\n\n";

$deadline = time() + 60;
while (time() < $deadline) {
    foreach (fetch_messages_since($conn, $cursor) as $rs) {
        echo "id: {$rs['id']}\n";
        echo "data: {$rs['timestamp']} - {$rs['user_id']}: {$rs['message']}\n\n";
        $cursor = (int) $rs['id'];
    }
    if (connection_aborted()) {
        break;
    }
    sleep(1);
}
