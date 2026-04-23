<?php
declare(strict_types=1);

require_once __DIR__ . '/access.php';

const SESSION_TTL_MINUTES = 15;
const MESSAGE_MAX_LENGTH = 200;
const COOLDOWN_BASE_SECONDS = 3;

function session_cookie_secure(): bool
{
    return getenv('APP_ENV') === 'prod';
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
    $stmt = $conn->prepare(
        "INSERT INTO messages (message, user_id, timestamp) VALUES (?, ?, ?)"
    );
    $stmt->execute([$message, $user_id, date('Y-m-d H:i:s')]);
    return (int) $conn->lastInsertId();
}

function max_message_id(PDO $conn): int
{
    $stmt = $conn->query("SELECT COALESCE(MAX(id), 0) FROM messages");
    return (int) $stmt->fetchColumn();
}

function validate_nickname(string $nickname): ?string
{
    return preg_match('/^[a-zA-Z0-9_-]{2,20}$/', $nickname) === 1
        ? null
        : 'invalid_nickname';
}

function validate_message(string $message): array
{
    $trimmed = trim($message);

    if ($trimmed === '') {
        return ['ok' => false, 'error' => 'empty', 'trimmed' => ''];
    }
    if (mb_strlen($trimmed, 'UTF-8') > MESSAGE_MAX_LENGTH) {
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

function create_session(PDO $conn, string $nickname): string
{
    $sid = bin2hex(random_bytes(32));
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        "INSERT INTO sessions (id, nickname, created_at, last_seen_at)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$sid, $nickname, $now, $now]);
    return $sid;
}

function get_session(PDO $conn, string $sid): ?array
{
    $stmt = $conn->prepare(
        "SELECT id, nickname, cooldown_attempts, send_blocked_until, created_at, last_seen_at
         FROM sessions
         WHERE id = ? AND last_seen_at >= NOW() - INTERVAL ? MINUTE"
    );
    $stmt->execute([$sid, SESSION_TTL_MINUTES]);
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
    $stmt = $conn->prepare(
        "DELETE FROM sessions WHERE last_seen_at < NOW() - INTERVAL ? MINUTE"
    );
    $stmt->execute([SESSION_TTL_MINUTES]);
    return $stmt->rowCount();
}

function advance_cooldown(PDO $conn, string $sid): array
{
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
            $update->execute([COOLDOWN_BASE_SECONDS, $sid]);
            $conn->commit();
            return ['allowed' => true, 'wait_seconds' => 0];
        }

        $newAttempts = (int) $row['cooldown_attempts'] + 1;
        $waitSeconds = COOLDOWN_BASE_SECONDS * $newAttempts;

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
