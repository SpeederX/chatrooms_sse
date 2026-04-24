<?php
declare(strict_types=1);

require_once __DIR__ . '/chatService.php';

start_admin_session();

if (admin_is_authenticated()) {
    header('Location: /adminPanel.php');
    exit;
}

$hasError = isset($_GET['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin login</title>
    <link rel="stylesheet" href="/assets/adminStyles.css">
</head>
<body class="admin admin--login">
    <main>
        <h1>Admin</h1>
        <?php if ($hasError): ?>
            <p id="login_error" class="admin__error">Invalid credentials.</p>
        <?php endif; ?>
        <form method="POST" action="/authenticateAdmin.php">
            <label>
                Password:
                <input type="password" name="password" required autofocus>
            </label>
            <button type="submit">Sign in</button>
        </form>
    </main>
</body>
</html>
