<?php
declare(strict_types=1);

require_once __DIR__ . '/chatService.php';

start_admin_session();
require_admin_auth();

try {
    $conn = new PDO(
        "mysql:host={$servername};dbname={$dbname};charset=utf8mb4",
        $username,
        $password
    );
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException) {
    http_response_code(500);
    echo 'Database unavailable.';
    exit;
}

$stats     = get_stats($conn);
$activeNow = active_users_now($conn);
$config    = get_all_config($conn);
$bounds    = config_bounds();
$csrf      = admin_csrf_token();

$totalMessages = (int) ($stats['total_messages'] ?? 0);
$totalChars    = (int) ($stats['total_chars'] ?? 0);
$totalUsers    = (int) ($stats['total_users'] ?? 0);
$avgLen        = $totalMessages > 0 ? round($totalChars / $totalMessages, 2) : 0;

$flashSuccess = isset($_GET['success']) ? (string) $_GET['success'] : null;
$flashError   = isset($_GET['error'])   ? (string) $_GET['error']   : null;

$successMap = [
    'config'  => 'Configuration saved.',
    'cleanup' => 'Message history cleared.',
];
$errorMap = [
    'config'  => 'Invalid configuration value — nothing was saved.',
    'cleanup' => 'History cleanup failed.',
    'csrf'    => 'Session expired. Please retry.',
];

$keyOrder = [
    'message_max_length',
    'cooldown_base_seconds',
    'history_size',
    'nickname_min_length',
    'nickname_max_length',
    'session_ttl_minutes',
    'active_user_window_minutes',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin panel</title>
    <link rel="stylesheet" href="/assets/adminStyles.css">
</head>
<body class="admin">
    <main>
        <header class="admin__header">
            <h1>Admin panel</h1>
            <form method="POST" action="/adminLogout.php">
                <button type="submit">Log out</button>
            </form>
        </header>

        <?php if ($flashSuccess !== null && isset($successMap[$flashSuccess])): ?>
            <p id="admin_success" class="admin__success">
                <?= htmlspecialchars($successMap[$flashSuccess], ENT_QUOTES) ?>
            </p>
        <?php endif; ?>
        <?php if ($flashError !== null && isset($errorMap[$flashError])): ?>
            <p id="admin_error" class="admin__error">
                <?= htmlspecialchars($errorMap[$flashError], ENT_QUOTES) ?>
            </p>
        <?php endif; ?>

        <h2>Stats</h2>
        <div class="admin__stats">
            <div class="admin__stat">
                <div class="admin__stat-label">Total messages</div>
                <div id="stat_total_messages" class="admin__stat-value"><?= $totalMessages ?></div>
            </div>
            <div class="admin__stat">
                <div class="admin__stat-label">Total characters</div>
                <div id="stat_total_chars" class="admin__stat-value"><?= $totalChars ?></div>
            </div>
            <div class="admin__stat">
                <div class="admin__stat-label">Avg message length</div>
                <div id="stat_avg_message_length" class="admin__stat-value"><?= $avgLen ?></div>
            </div>
            <div class="admin__stat">
                <div class="admin__stat-label">Total unique users</div>
                <div id="stat_total_users" class="admin__stat-value"><?= $totalUsers ?></div>
            </div>
            <div class="admin__stat">
                <div class="admin__stat-label">Active users now</div>
                <div id="stat_active_users_now" class="admin__stat-value"><?= $activeNow ?></div>
            </div>
        </div>

        <h2>Runtime configuration</h2>
        <form id="config_form" class="admin__form" method="POST" action="/adminUpdateConfig.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <div class="admin__form-grid">
                <?php foreach ($keyOrder as $key): ?>
                    <?php
                    $value = $config[$key] ?? '';
                    $min   = $bounds[$key]['min'] ?? 0;
                    $max   = $bounds[$key]['max'] ?? 0;
                    ?>
                    <label>
                        <?= htmlspecialchars($key, ENT_QUOTES) ?>
                        <input type="number"
                               name="<?= htmlspecialchars($key, ENT_QUOTES) ?>"
                               value="<?= htmlspecialchars((string) $value, ENT_QUOTES) ?>"
                               min="<?= $min ?>"
                               max="<?= $max ?>"
                               required>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="admin__actions">
                <button type="submit">Save configuration</button>
            </div>
        </form>

        <h2>Message history</h2>
        <form id="cleanup_form"
              class="admin__form"
              method="POST"
              action="/adminCleanupHistory.php"
              onsubmit="return confirm('Delete every chat message? Stats counters are preserved.');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <p>
                Clears the <code>messages</code> table. The three stats counters
                (<code>total_messages</code>, <code>total_chars</code>,
                <code>total_users</code>) stay intact.
            </p>
            <div class="admin__actions">
                <button type="submit" class="admin__button--danger">Clean up history</button>
            </div>
        </form>
    </main>
</body>
</html>
