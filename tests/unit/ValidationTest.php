<?php
declare(strict_types=1);

require_once __DIR__ . '/../../chatService.php';

use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    public function testValidateNicknameAcceptsCanonical(): void
    {
        $this->assertNull(validate_nickname('alice_01', 2, 20));
        $this->assertNull(validate_nickname('Bob-2', 2, 20));
        $this->assertNull(validate_nickname('xy', 2, 20));
        $this->assertNull(validate_nickname(str_repeat('a', 20), 2, 20));
    }

    public function testValidateNicknameRejectsTooShort(): void
    {
        $this->assertSame('invalid_nickname', validate_nickname('a', 2, 20));
        $this->assertSame('invalid_nickname', validate_nickname('', 2, 20));
    }

    public function testValidateNicknameRejectsTooLong(): void
    {
        $this->assertSame('invalid_nickname', validate_nickname(str_repeat('a', 21), 2, 20));
    }

    public function testValidateNicknameRejectsBadChars(): void
    {
        $this->assertSame('invalid_nickname', validate_nickname('a b', 2, 20));
        $this->assertSame('invalid_nickname', validate_nickname('a.b', 2, 20));
        $this->assertSame('invalid_nickname', validate_nickname('a/b', 2, 20));
        $this->assertSame('invalid_nickname', validate_nickname('a!b', 2, 20));
        $this->assertSame('invalid_nickname', validate_nickname("a\nb", 2, 20));
    }

    public function testValidateNicknameHonorsDynamicBounds(): void
    {
        $this->assertSame('invalid_nickname', validate_nickname('abcd', 5, 8));
        $this->assertNull(validate_nickname('abcde', 5, 8));
        $this->assertNull(validate_nickname('abcdefgh', 5, 8));
        $this->assertSame('invalid_nickname', validate_nickname('abcdefghi', 5, 8));
    }

    public function testValidateMessageTrimsAndAccepts(): void
    {
        $result = validate_message('  hi  ', 200);
        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
        $this->assertSame('hi', $result['trimmed']);
    }

    public function testValidateMessageRejectsEmpty(): void
    {
        $empty = validate_message('', 200);
        $this->assertFalse($empty['ok']);
        $this->assertSame('empty', $empty['error']);

        $whitespace = validate_message("   \t ", 200);
        $this->assertFalse($whitespace['ok']);
        $this->assertSame('empty', $whitespace['error']);
    }

    public function testValidateMessageRejects201Chars(): void
    {
        $result = validate_message(str_repeat('a', 201), 200);
        $this->assertFalse($result['ok']);
        $this->assertSame('too_long', $result['error']);
    }

    public function testValidateMessageAccepts200Chars(): void
    {
        $result = validate_message(str_repeat('a', 200), 200);
        $this->assertTrue($result['ok']);
    }

    public function testValidateMessageHonorsDynamicMaxLength(): void
    {
        $this->assertTrue(validate_message(str_repeat('a', 50), 50)['ok']);
        $this->assertSame('too_long', validate_message(str_repeat('a', 51), 50)['error']);
    }

    public function testValidateMessageRejectsCRLF(): void
    {
        $this->assertSame('invalid_chars', validate_message("a\nb", 200)['error']);
        $this->assertSame('invalid_chars', validate_message("a\rb", 200)['error']);
        $this->assertSame('invalid_chars', validate_message("a\r\nb", 200)['error']);
    }

    public function testValidateMessageRejectsControlChars(): void
    {
        $this->assertSame('invalid_chars', validate_message("a\x00b", 200)['error']);
        $this->assertSame('invalid_chars', validate_message("a\x07b", 200)['error']);
        $this->assertSame('invalid_chars', validate_message("a\x1Fb", 200)['error']);
        $this->assertSame('invalid_chars', validate_message("a\x7Fb", 200)['error']);
    }

    public function testValidateMessageRejectsInvalidUtf8(): void
    {
        $this->assertSame('invalid_chars', validate_message("\xC3\x28", 200)['error']);
    }

    public function testValidateMessageAcceptsValidUtf8(): void
    {
        $result = validate_message('ciao 👋 мир', 200);
        $this->assertTrue($result['ok']);
    }
}
