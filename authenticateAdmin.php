<?php
declare(strict_types=1);

require_once __DIR__ . '/chatService.php';

start_admin_session();

$password = (string) ($_POST['password'] ?? '');
if (authenticate_admin_request($password)) {
    header('Location: /adminPanel.php', true, 302);
    exit;
}

header('Location: /adminLogin.php?error=1', true, 303);
exit;
