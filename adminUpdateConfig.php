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

$expectedKeys = array_keys(config_bounds());
$values = [];
foreach ($expectedKeys as $key) {
    if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
        header('Location: /adminPanel.php?error=config', true, 303);
        exit;
    }
    $values[$key] = $_POST[$key];
}

try {
    $conn = new PDO(
        "mysql:host={$servername};dbname={$dbname};charset=utf8mb4",
        $username,
        $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    set_all_config($conn, $values);
    header('Location: /adminPanel.php?success=config', true, 303);
    exit;
} catch (InvalidArgumentException) {
    header('Location: /adminPanel.php?error=config', true, 303);
    exit;
} catch (PDOException) {
    header('Location: /adminPanel.php?error=config', true, 303);
    exit;
}
