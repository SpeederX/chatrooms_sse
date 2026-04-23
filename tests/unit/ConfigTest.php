<?php
declare(strict_types=1);

require_once __DIR__ . '/../../chatService.php';

use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private ?string $originalAppEnv;

    protected function setUp(): void
    {
        $val = getenv('APP_ENV');
        $this->originalAppEnv = $val === false ? null : $val;
    }

    protected function tearDown(): void
    {
        if ($this->originalAppEnv === null) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->originalAppEnv);
        }
    }

    public function testSessionCookieSecureTrueInProd(): void
    {
        putenv('APP_ENV=prod');
        $this->assertTrue(session_cookie_secure());
    }

    public function testSessionCookieSecureFalseInLocal(): void
    {
        putenv('APP_ENV=local');
        $this->assertFalse(session_cookie_secure());
    }

    public function testSessionCookieSecureFalseWhenUnset(): void
    {
        putenv('APP_ENV');
        $this->assertFalse(session_cookie_secure());
    }
}
