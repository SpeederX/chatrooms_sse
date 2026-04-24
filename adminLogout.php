<?php
declare(strict_types=1);

require_once __DIR__ . '/chatService.php';

start_admin_session();
admin_logout();

header('Location: /adminLogin.php', true, 303);
exit;
