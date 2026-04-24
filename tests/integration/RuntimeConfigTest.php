<?php
declare(strict_types=1);

require_once __DIR__ . '/../../chatService.php';

use PHPUnit\Framework\TestCase;

final class RuntimeConfigTest extends TestCase
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
        $this->conn->exec('TRUNCATE TABLE config');
        $this->conn->exec(
            "INSERT INTO config (`key`, value) VALUES
                ('message_max_length',          '200'),
                ('cooldown_base_seconds',       '3'),
                ('history_size',                '50'),
                ('nickname_min_length',         '2'),
                ('nickname_max_length',         '20'),
                ('session_ttl_minutes',         '15'),
                ('active_user_window_minutes',  '12')"
        );
    }

    public function testDefaultsAreSeeded(): void
    {
        $all = get_all_config($this->conn);
        $this->assertSame('200', $all['message_max_length']);
        $this->assertSame('3', $all['cooldown_base_seconds']);
        $this->assertSame('50', $all['history_size']);
        $this->assertSame('2', $all['nickname_min_length']);
        $this->assertSame('20', $all['nickname_max_length']);
        $this->assertSame('15', $all['session_ttl_minutes']);
        $this->assertSame('12', $all['active_user_window_minutes']);
    }

    public function testGetConfigReturnsIntWhenDefaultIsInt(): void
    {
        $value = get_config($this->conn, 'history_size', 0);
        $this->assertSame(50, $value);
        $this->assertIsInt($value);
    }

    public function testGetConfigReturnsStringWhenDefaultIsString(): void
    {
        $value = get_config($this->conn, 'history_size', '');
        $this->assertSame('50', $value);
        $this->assertIsString($value);
    }

    public function testGetConfigFallsBackToDefaultOnMissingRow(): void
    {
        $this->conn->exec("DELETE FROM config WHERE `key` = 'history_size'");
        $this->assertSame(99, get_config($this->conn, 'history_size', 99));
    }

    public function testSetConfigUpdatesRow(): void
    {
        set_config($this->conn, 'history_size', '100');
        $this->assertSame(100, get_config($this->conn, 'history_size', 0));
    }

    public function testSetConfigRejectsOutOfBoundsHigh(): void
    {
        $this->expectException(InvalidArgumentException::class);
        set_config($this->conn, 'history_size', '9999');
    }

    public function testSetConfigRejectsOutOfBoundsLow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        set_config($this->conn, 'session_ttl_minutes', '0');
    }

    public function testSetConfigRejectsNonInteger(): void
    {
        $this->expectException(InvalidArgumentException::class);
        set_config($this->conn, 'history_size', 'fifty');
    }

    public function testSetConfigRejectsUnknownKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        set_config($this->conn, 'not_a_real_key', '1');
    }

    public function testSetConfigEnforcesNicknameInvariant(): void
    {
        $this->expectException(InvalidArgumentException::class);
        set_config($this->conn, 'nickname_min_length', '25');
    }

    public function testSetConfigEnforcesActiveWindowInvariant(): void
    {
        $this->expectException(InvalidArgumentException::class);
        set_config($this->conn, 'active_user_window_minutes', '20');
    }

    public function testSetAllConfigRollsBackOnBoundsViolation(): void
    {
        $before = get_all_config($this->conn);
        try {
            set_all_config($this->conn, [
                'history_size' => '75',
                'cooldown_base_seconds' => '999',
            ]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame($before, get_all_config($this->conn));
    }

    public function testSetAllConfigRollsBackOnInvariantViolation(): void
    {
        $before = get_all_config($this->conn);
        try {
            set_all_config($this->conn, [
                'session_ttl_minutes' => '10',
                'active_user_window_minutes' => '15',
            ]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame($before, get_all_config($this->conn));
    }

    public function testSetAllConfigAppliesCoherentLoweringAtomically(): void
    {
        set_all_config($this->conn, [
            'session_ttl_minutes' => '30',
            'active_user_window_minutes' => '25',
        ]);
        set_all_config($this->conn, [
            'session_ttl_minutes' => '15',
            'active_user_window_minutes' => '12',
        ]);
        $this->assertSame(15, get_config($this->conn, 'session_ttl_minutes', 0));
        $this->assertSame(12, get_config($this->conn, 'active_user_window_minutes', 0));
    }
}
