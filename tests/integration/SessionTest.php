<?php
declare(strict_types=1);

require_once __DIR__ . '/../../chatService.php';

use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    private PDO $conn;

    protected function setUp(): void
    {
        $this->conn = new PDO(
            sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                getenv('DB_HOST'),
                getenv('DB_NAME')
            ),
            (string) getenv('DB_USER'),
            (string) getenv('DB_PASS')
        );
        $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->conn->exec('TRUNCATE TABLE sessions');
    }

    public function testCreateSessionReturnsOpaqueHex(): void
    {
        $sid = create_session($this->conn, 'alice');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sid);

        $row = $this->conn
            ->query("SELECT nickname FROM sessions WHERE id = " . $this->conn->quote($sid))
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('alice', $row['nickname']);
    }

    public function testCreateSessionRejectsDuplicateNickname(): void
    {
        create_session($this->conn, 'alice');

        $this->expectException(PDOException::class);
        create_session($this->conn, 'alice');
    }

    public function testCleanupExpiredSessionsDeletesOldRows(): void
    {
        $fresh = create_session($this->conn, 'fresh_user');
        $stale = create_session($this->conn, 'stale_user');

        $this->conn->exec(
            "UPDATE sessions SET last_seen_at = NOW() - INTERVAL 16 MINUTE WHERE id = "
            . $this->conn->quote($stale)
        );

        $deleted = cleanup_expired_sessions($this->conn);
        $this->assertSame(1, $deleted);

        $this->assertNotNull(get_session($this->conn, $fresh));
        $this->assertNull(get_session($this->conn, $stale));
    }

    public function testCleanupThenCreateFreesAbandonedNickname(): void
    {
        $sid = create_session($this->conn, 'alice');
        $this->conn->exec(
            "UPDATE sessions SET last_seen_at = NOW() - INTERVAL 16 MINUTE WHERE id = "
            . $this->conn->quote($sid)
        );

        cleanup_expired_sessions($this->conn);

        $newSid = create_session($this->conn, 'alice');
        $this->assertNotSame($sid, $newSid);
    }

    public function testGetSessionReturnsNullWhenExpired(): void
    {
        $sid = create_session($this->conn, 'alice');
        $this->conn->exec(
            "UPDATE sessions SET last_seen_at = NOW() - INTERVAL 16 MINUTE WHERE id = "
            . $this->conn->quote($sid)
        );

        $this->assertNull(get_session($this->conn, $sid));
    }

    public function testGetSessionReturnsRowWhenFresh(): void
    {
        $sid = create_session($this->conn, 'alice');

        $row = get_session($this->conn, $sid);
        $this->assertNotNull($row);
        $this->assertSame('alice', $row['nickname']);
        $this->assertSame(0, (int) $row['cooldown_attempts']);
        $this->assertNull($row['send_blocked_until']);
    }

    public function testTouchSessionUpdatesLastSeen(): void
    {
        $sid = create_session($this->conn, 'alice');
        $this->conn->exec(
            "UPDATE sessions SET last_seen_at = NOW() - INTERVAL 10 MINUTE WHERE id = "
            . $this->conn->quote($sid)
        );

        touch_session($this->conn, $sid);

        $row = get_session($this->conn, $sid);
        $this->assertNotNull($row);
        $elapsed = time() - strtotime($row['last_seen_at']);
        $this->assertLessThan(5, $elapsed);
    }

    public function testAdvanceCooldownAllowsFirstSend(): void
    {
        $sid = create_session($this->conn, 'alice');

        $result = advance_cooldown($this->conn, $sid);

        $this->assertTrue($result['allowed']);
        $this->assertSame(0, $result['wait_seconds']);

        $row = get_session($this->conn, $sid);
        $this->assertSame(0, (int) $row['cooldown_attempts']);
        $this->assertNotNull($row['send_blocked_until']);

        $blockedUntil = strtotime($row['send_blocked_until']);
        $this->assertEqualsWithDelta(time() + 3, $blockedUntil, 2);
    }

    public function testAdvanceCooldownBlocksSecondSendWithin3s(): void
    {
        $sid = create_session($this->conn, 'alice');
        advance_cooldown($this->conn, $sid);

        $result = advance_cooldown($this->conn, $sid);

        $this->assertFalse($result['allowed']);
        $this->assertSame(3, $result['wait_seconds']);

        $row = get_session($this->conn, $sid);
        $this->assertSame(1, (int) $row['cooldown_attempts']);
    }

    public function testAdvanceCooldownEscalatesPenaltyLinearly(): void
    {
        $sid = create_session($this->conn, 'alice');
        advance_cooldown($this->conn, $sid);

        $first = advance_cooldown($this->conn, $sid);
        $second = advance_cooldown($this->conn, $sid);
        $third = advance_cooldown($this->conn, $sid);

        $this->assertSame(3, $first['wait_seconds']);
        $this->assertSame(6, $second['wait_seconds']);
        $this->assertSame(9, $third['wait_seconds']);

        $row = get_session($this->conn, $sid);
        $this->assertSame(3, (int) $row['cooldown_attempts']);
    }

    public function testRejoinWithSameNicknameViaCookieReusesSession(): void
    {
        $sid = create_session($this->conn, 'alice');
        $this->conn->exec(
            "UPDATE sessions SET last_seen_at = NOW() - INTERVAL 30 SECOND WHERE id = "
            . $this->conn->quote($sid)
        );

        $returned = rejoin_or_create_session($this->conn, 'alice', $sid);
        $this->assertSame($sid, $returned);

        $count = (int) $this->conn
            ->query("SELECT COUNT(*) FROM sessions WHERE nickname = 'alice'")
            ->fetchColumn();
        $this->assertSame(1, $count);

        $elapsed = (int) $this->conn
            ->query("SELECT TIMESTAMPDIFF(SECOND, last_seen_at, NOW()) FROM sessions WHERE id = "
                . $this->conn->quote($sid))
            ->fetchColumn();
        $this->assertLessThan(5, $elapsed);
    }

    public function testRejoinWithDifferentNicknameDropsOldSession(): void
    {
        $aliceSid = create_session($this->conn, 'alice');

        $bobSid = rejoin_or_create_session($this->conn, 'bob', $aliceSid);
        $this->assertNotSame($aliceSid, $bobSid);

        $aliceCount = (int) $this->conn
            ->query("SELECT COUNT(*) FROM sessions WHERE nickname = 'alice'")
            ->fetchColumn();
        $this->assertSame(0, $aliceCount, 'old alice session should be dropped');

        $bobCount = (int) $this->conn
            ->query("SELECT COUNT(*) FROM sessions WHERE nickname = 'bob'")
            ->fetchColumn();
        $this->assertSame(1, $bobCount);
    }

    public function testRejoinWithoutCookieCreatesFreshSession(): void
    {
        $sid = rejoin_or_create_session($this->conn, 'alice', '');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sid);
    }

    public function testRejoinWithStaleCookieCreatesFreshSession(): void
    {
        $staleSid = str_repeat('0', 64);
        $sid = rejoin_or_create_session($this->conn, 'alice', $staleSid);
        $this->assertNotSame($staleSid, $sid);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sid);
    }

    public function testRejoinStillRejectsNicknameHeldByAnotherSession(): void
    {
        create_session($this->conn, 'alice');

        $this->expectException(PDOException::class);
        rejoin_or_create_session($this->conn, 'alice', '');
    }

    public function testAdvanceCooldownResetsAfterExpiry(): void
    {
        $sid = create_session($this->conn, 'alice');
        advance_cooldown($this->conn, $sid);
        advance_cooldown($this->conn, $sid); // attempts=1

        $this->conn->exec(
            "UPDATE sessions SET send_blocked_until = NOW() - INTERVAL 1 SECOND WHERE id = "
            . $this->conn->quote($sid)
        );

        $result = advance_cooldown($this->conn, $sid);

        $this->assertTrue($result['allowed']);
        $this->assertSame(0, $result['wait_seconds']);

        $row = get_session($this->conn, $sid);
        $this->assertSame(0, (int) $row['cooldown_attempts']);
    }
}
