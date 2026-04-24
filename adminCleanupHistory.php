<?php
declare(strict_types=1);

require_once __DIR__ . '/chatService.php';

start_admin_session();
require_admin_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$submittedToken = (string) ($_POST['csrf_token'] ?? '');
if (!verify_admin_csrf($submittedToken)) {
    header('Location: /adminPanel.php?error=csrf', true, 303);
    exit;
}

try {
    $conn = new PDO(
        "mysql:host={$servername};dbname={$dbname};charset=utf8mb4",
        $username,
        $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    cleanup_message_history($conn);
    header('Location: /adminPanel.php?success=cleanup', true, 303);
    exit;
} catch (PDOException) {
    header('Location: /adminPanel.php?error=cleanup', true, 303);
    exit;
}
