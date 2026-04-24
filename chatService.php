<?php
declare(strict_types=1);

require_once __DIR__ . '/access.php';

function session_cookie_secure(): bool
{
    return getenv('APP_ENV') === 'prod';
}

const ADMIN_SESSION_IDLE_SECONDS = 3600;

function start_admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('admin_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => session_cookie_secure(),
    ]);
    session_start();
}

function verify_admin_password(string $candidate): bool
{
    $hash = (string) getenv('ADMIN_PASSWORD_HASH');
    if ($hash === '') {
        return false;
    }
    return password_verify($candidate, $hash);
}

function authenticate_admin_request(string $submittedPassword): bool
{
    if (!verify_admin_password($submittedPassword)) {
        return false;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['admin_authenticated'] = true;
    $_SESSION['admin_last_activity'] = time();
    $_SESSION['admin_csrf_token']    = bin2hex(random_bytes(32));
    return true;
}

function admin_is_authenticated(): bool
{
    if (empty($_SESSION['admin_authenticated'])) {
        return false;
    }
    $lastActivity = (int) ($_SESSION['admin_last_activity'] ?? 0);
    if (time() - $lastActivity > ADMIN_SESSION_IDLE_SECONDS) {
        return false;
    }
    $_SESSION['admin_last_activity'] = time();
    return true;
}

function require_admin_auth(): void
{
    if (admin_is_authenticated()) {
        return;
    }
    admin_logout();
    header('Location: /adminLogin.php');
    exit;
}

function admin_csrf_token(): string
{
    if (empty($_SESSION['admin_csrf_token'])) {
        $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['admin_csrf_token'];
}

function verify_admin_csrf(string $submitted): bool
{
    $expected = (string) ($_SESSION['admin_csrf_token'] ?? '');
    if ($expected === '') {
        return false;
    }
    return hash_equals($expected, $submitted);
}

function admin_logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function config_bounds(): array
{
    return [
        'message_max_length'         => ['min' => 10, 'max' => 2000],
        'cooldown_base_seconds'      => ['min' => 0,  'max' => 60],
        'history_size'               => ['min' => 0,  'max' => 500],
        'nickname_min_length'        => ['min' => 1,  'max' => 30],
        'nickname_max_length'        => ['min' => 1,  'max' => 30],
        'session_ttl_minutes'        => ['min' => 1,  'max' => 1440],
        'active_user_window_minutes' => ['min' => 1,  'max' => 1440],
    ];
}

function check_config_invariants(array $config): void
{
    if (isset($config['nickname_min_length'], $config['nickname_max_length'])) {
        $min = (int) $config['nickname_min_length'];
        $max = (int) $config['nickname_max_length'];
        if ($min > $max) {
            throw new InvalidArgumentException(
                "invariant: nickname_min_length ({$min}) > nickname_max_length ({$max})"
            );
        }
    }
    if (isset($config['active_user_window_minutes'], $config['session_ttl_minutes'])) {
        $window = (int) $config['active_user_window_minutes'];
        $ttl = (int) $config['session_ttl_minutes'];
        if ($window > $ttl) {
            throw new InvalidArgumentException(
                "invariant: active_user_window_minutes ({$window}) > session_ttl_minutes ({$ttl})"
            );
        }
    }
}

function get_config(PDO $conn, string $key, int|string $default): int|string
{
    $stmt = $conn->prepare("SELECT value FROM config WHERE `key` = ?");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    if ($value === false) {
        return $default;
    }
    return is_int($default) ? (int) $value : (string) $value;
}

function get_all_config(PDO $conn): array
{
    $stmt = $conn->query("SELECT `key`, value FROM config");
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[$row['key']] = $row['value'];
    }
    return $result;
}

function set_all_config(PDO $conn, array $values): void
{
    $bounds = config_bounds();
    $sanitized = [];
    foreach ($values as $key => $value) {
        if (!isset($bounds[$key])) {
            throw new InvalidArgumentException("unknown config key: {$key}");
        }
        if (!is_string($value) || !preg_match('/^\d+$/', $value)) {
            $shown = is_string($value) ? $value : gettype($value);
            throw new InvalidArgumentException(
                "config value must be a non-negative integer string: {$key}={$shown}"
            );
        }
        $intValue = (int) $value;
        $b = $bounds[$key];
        if ($intValue < $b['min'] || $intValue > $b['max']) {
            throw new InvalidArgumentException(
                "config value out of bounds: {$key}={$intValue} (allowed {$b['min']}..{$b['max']})"
            );
        }
        $sanitized[$key] = (string) $intValue;
    }

    $resolved = array_merge(get_all_config($conn), $sanitized);
    check_config_invariants($resolved);

    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare(
            "INSERT INTO config (`key`, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );
        foreach ($sanitized as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        $conn->commit();
    } catch (\Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

function set_config(PDO $conn, string $key, string $value): void
{
    set_all_config($conn, [$key => $value]);
}

function fetch_messages_since(PDO $conn, int $cursor): array
{
    $stmt = $conn->prepare(
        "SELECT id, user_id, timestamp, message FROM messages WHERE id > ? ORDER BY id ASC"
    );
    $stmt->execute([$cursor]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function insert_message(PDO $conn, string $message, string $user_id): int
{
    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare(
            "INSERT INTO messages (message, user_id, timestamp) VALUES (?, ?, ?)"
        );
        $stmt->execute([$message, $user_id, date('Y-m-d H:i:s')]);
        $id = (int) $conn->lastInsertId();

        $length = mb_strlen($message, 'UTF-8');
        $update = $conn->prepare(
            "UPDATE stats
             SET total_messages = total_messages + 1,
                 total_chars = total_chars + ?
             WHERE id = 1"
        );
        $update->execute([$length]);

        $conn->commit();
        return $id;
    } catch (\Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

function max_message_id(PDO $conn): int
{
    $stmt = $conn->query("SELECT COALESCE(MAX(id), 0) FROM messages");
    return (int) $stmt->fetchColumn();
}

function fetch_last_n_messages(PDO $conn, int $n): array
{
    $stmt = $conn->prepare(
        "SELECT id, user_id, timestamp, message FROM messages
         ORDER BY id DESC LIMIT ?"
    );
    $stmt->bindValue(1, $n, PDO::PARAM_INT);
    $stmt->execute();
    return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function cleanup_message_history(PDO $conn): int
{
    return (int) $conn->exec("DELETE FROM messages");
}

function validate_nickname(string $nickname, int $minLen, int $maxLen): ?string
{
    $len = mb_strlen($nickname, 'UTF-8');
    if ($len < $minLen || $len > $maxLen) {
        return 'invalid_nickname';
    }
    if (preg_match('/^[a-zA-Z0-9_-]+$/', $nickname) !== 1) {
        return 'invalid_nickname';
    }
    return null;
}

function validate_message(string $message, int $maxLength): array
{
    $trimmed = trim($message);

    if ($trimmed === '') {
        return ['ok' => false, 'error' => 'empty', 'trimmed' => ''];
    }
    if (mb_strlen($trimmed, 'UTF-8') > $maxLength) {
        return ['ok' => false, 'error' => 'too_long', 'trimmed' => $trimmed];
    }
    if (!mb_check_encoding($trimmed, 'UTF-8')) {
        return ['ok' => false, 'error' => 'invalid_chars', 'trimmed' => $trimmed];
    }
    if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $trimmed) === 1) {
        return ['ok' => false, 'error' => 'invalid_chars', 'trimmed' => $trimmed];
    }

    return ['ok' => true, 'error' => null, 'trimmed' => $trimmed];
}

function rejoin_or_create_session(PDO $conn, string $nickname, string $existingSid): string
{
    if ($existingSid !== '') {
        $same = $conn->prepare(
            "SELECT 1 FROM sessions WHERE id = ? AND nickname = ?"
        );
        $same->execute([$existingSid, $nickname]);
        if ($same->fetchColumn() !== false) {
            touch_session($conn, $existingSid);
            return $existingSid;
        }

        $del = $conn->prepare("DELETE FROM sessions WHERE id = ?");
        $del->execute([$existingSid]);
    }

    return create_session($conn, $nickname);
}

function create_session(PDO $conn, string $nickname): string
{
    $sid = bin2hex(random_bytes(32));
    $now = date('Y-m-d H:i:s');

    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare(
            "INSERT INTO sessions (id, nickname, created_at, last_seen_at)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$sid, $nickname, $now, $now]);

        $seen = $conn->prepare(
            "INSERT IGNORE INTO seen_users (nickname, first_seen_at) VALUES (?, ?)"
        );
        $seen->execute([$nickname, $now]);

        if ($seen->rowCount() > 0) {
            $conn->exec("UPDATE stats SET total_users = total_users + 1 WHERE id = 1");
        }

        $conn->commit();
        return $sid;
    } catch (\Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}

function get_session(PDO $conn, string $sid): ?array
{
    $ttl = (int) get_config($conn, 'session_ttl_minutes', 15);
    $stmt = $conn->prepare(
        "SELECT id, nickname, cooldown_attempts, send_blocked_until, created_at, last_seen_at
         FROM sessions
         WHERE id = ? AND last_seen_at >= NOW() - INTERVAL ? MINUTE"
    );
    $stmt->execute([$sid, $ttl]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function touch_session(PDO $conn, string $sid): void
{
    $stmt = $conn->prepare("UPDATE sessions SET last_seen_at = NOW() WHERE id = ?");
    $stmt->execute([$sid]);
}

function cleanup_expired_sessions(PDO $conn): int
{
    $ttl = (int) get_config($conn, 'session_ttl_minutes', 15);
    $stmt = $conn->prepare(
        "DELETE FROM sessions WHERE last_seen_at < NOW() - INTERVAL ? MINUTE"
    );
    $stmt->execute([$ttl]);
    return $stmt->rowCount();
}

function get_stats(PDO $conn): array
{
    $stmt = $conn->query(
        "SELECT total_messages, total_chars, total_users FROM stats WHERE id = 1"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? ['total_messages' => 0, 'total_chars' => 0, 'total_users' => 0] : $row;
}

function active_users_now(PDO $conn): int
{
    $window = (int) get_config($conn, 'active_user_window_minutes', 12);
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM sessions WHERE last_seen_at >= NOW() - INTERVAL ? MINUTE"
    );
    $stmt->execute([$window]);
    return (int) $stmt->fetchColumn();
}

function advance_cooldown(PDO $conn, string $sid): array
{
    $base = (int) get_config($conn, 'cooldown_base_seconds', 3);
    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare(
            "SELECT send_blocked_until, cooldown_attempts
             FROM sessions WHERE id = ? FOR UPDATE"
        );
        $stmt->execute([$sid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $conn->rollBack();
            throw new RuntimeException("session not found: {$sid}");
        }

        $blockedUntil = $row['send_blocked_until'];
        $now = time();
        $blocked = $blockedUntil !== null && strtotime($blockedUntil) > $now;

        if (!$blocked) {
            $update = $conn->prepare(
                "UPDATE sessions
                 SET send_blocked_until = NOW() + INTERVAL ? SECOND,
                     cooldown_attempts = 0
                 WHERE id = ?"
            );
            $update->execute([$base, $sid]);
            $conn->commit();
            return ['allowed' => true, 'wait_seconds' => 0];
        }

        $newAttempts = (int) $row['cooldown_attempts'] + 1;
        $waitSeconds = $base * $newAttempts;

        $update = $conn->prepare(
            "UPDATE sessions
             SET send_blocked_until = NOW() + INTERVAL ? SECOND,
                 cooldown_attempts = ?
             WHERE id = ?"
        );
        $update->execute([$waitSeconds, $newAttempts, $sid]);
        $conn->commit();

        return ['allowed' => false, 'wait_seconds' => $waitSeconds];
    } catch (\Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}
