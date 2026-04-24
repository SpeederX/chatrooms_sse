<?php
declare(strict_types=1);

require_once __DIR__ . '/../../chatService.php';

use PHPUnit\Framework\TestCase;

final class ConfigBoundsTest extends TestCase
{
    private const SEEDED_KEYS = [
        'message_max_length',
        'cooldown_base_seconds',
        'history_size',
        'nickname_min_length',
        'nickname_max_length',
        'session_ttl_minutes',
        'active_user_window_minutes',
    ];

    public function testBoundsContainAllSeededKeys(): void
    {
        $bounds = config_bounds();
        foreach (self::SEEDED_KEYS as $key) {
            $this->assertArrayHasKey($key, $bounds, "missing bounds for {$key}");
            $this->assertArrayHasKey('min', $bounds[$key]);
            $this->assertArrayHasKey('max', $bounds[$key]);
            $this->assertLessThanOrEqual(
                $bounds[$key]['max'],
                $bounds[$key]['min'],
                "inverted bounds for {$key}"
            );
        }
    }

    public function testBoundsReturnsExactlySeededKeys(): void
    {
        $this->assertEqualsCanonicalizing(
            self::SEEDED_KEYS,
            array_keys(config_bounds())
        );
    }

    public function testNicknameInvariantRejectsMinAboveMax(): void
    {
        $this->expectException(InvalidArgumentException::class);
        check_config_invariants([
            'nickname_min_length' => '10',
            'nickname_max_length' => '5',
        ]);
    }

    public function testNicknameInvariantAllowsMinEqualsMax(): void
    {
        check_config_invariants([
            'nickname_min_length' => '5',
            'nickname_max_length' => '5',
        ]);
        $this->addToAssertionCount(1);
    }

    public function testActiveWindowInvariantRejectsWindowAboveTtl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        check_config_invariants([
            'active_user_window_minutes' => '20',
            'session_ttl_minutes' => '15',
        ]);
    }

    public function testActiveWindowInvariantAllowsWindowEqualsTtl(): void
    {
        check_config_invariants([
            'active_user_window_minutes' => '15',
            'session_ttl_minutes' => '15',
        ]);
        $this->addToAssertionCount(1);
    }

    public function testInvariantsIgnorePartialMaps(): void
    {
        check_config_invariants(['nickname_min_length' => '5']);
        check_config_invariants(['session_ttl_minutes' => '15']);
        check_config_invariants([]);
        $this->addToAssertionCount(3);
    }
}
