<?php
declare(strict_types=1);

require_once __DIR__ . '/../../chatService.php';

use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    public function testValidateNicknameAcceptsCanonical(): void
    {
        $this->assertNull(validate_nickname('alice_01'));
        $this->assertNull(validate_nickname('Bob-2'));
        $this->assertNull(validate_nickname('xy'));
        $this->assertNull(validate_nickname(str_repeat('a', 20)));
    }

    public function testValidateNicknameRejectsTooShort(): void
    {
        $this->assertSame('invalid_nickname', validate_nickname('a'));
        $this->assertSame('invalid_nickname', validate_nickname(''));
    }

    public function testValidateNicknameRejectsTooLong(): void
    {
        $this->assertSame('invalid_nickname', validate_nickname(str_repeat('a', 21)));
    }

    public function testValidateNicknameRejectsBadChars(): void
    {
        $this->assertSame('invalid_nickname', validate_nickname('a b'));
        $this->assertSame('invalid_nickname', validate_nickname('a.b'));
        $this->assertSame('invalid_nickname', validate_nickname('a/b'));
        $this->assertSame('invalid_nickname', validate_nickname('a!b'));
        $this->assertSame('invalid_nickname', validate_nickname("a\nb"));
    }

    public function testValidateMessageTrimsAndAccepts(): void
    {
        $result = validate_message('  hi  ');
        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
        $this->assertSame('hi', $result['trimmed']);
    }

    public function testValidateMessageRejectsEmpty(): void
    {
        $empty = validate_message('');
        $this->assertFalse($empty['ok']);
        $this->assertSame('empty', $empty['error']);

        $whitespace = validate_message("   \t ");
        $this->assertFalse($whitespace['ok']);
        $this->assertSame('empty', $whitespace['error']);
    }

    public function testValidateMessageRejects201Chars(): void
    {
        $result = validate_message(str_repeat('a', 201));
        $this->assertFalse($result['ok']);
        $this->assertSame('too_long', $result['error']);
    }

    public function testValidateMessageAccepts200Chars(): void
    {
        $result = validate_message(str_repeat('a', 200));
        $this->assertTrue($result['ok']);
    }

    public function testValidateMessageRejectsCRLF(): void
    {
        $this->assertSame('invalid_chars', validate_message("a\nb")['error']);
        $this->assertSame('invalid_chars', validate_message("a\rb")['error']);
        $this->assertSame('invalid_chars', validate_message("a\r\nb")['error']);
    }

    public function testValidateMessageRejectsControlChars(): void
    {
        $this->assertSame('invalid_chars', validate_message("a\x00b")['error']);
        $this->assertSame('invalid_chars', validate_message("a\x07b")['error']);
        $this->assertSame('invalid_chars', validate_message("a\x1Fb")['error']);
        $this->assertSame('invalid_chars', validate_message("a\x7Fb")['error']);
    }

    public function testValidateMessageRejectsInvalidUtf8(): void
    {
        $this->assertSame('invalid_chars', validate_message("\xC3\x28")['error']);
    }

    public function testValidateMessageAcceptsValidUtf8(): void
    {
        $result = validate_message('ciao 👋 мир');
        $this->assertTrue($result['ok']);
    }
}
