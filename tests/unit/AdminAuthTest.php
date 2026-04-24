<?php
declare(strict_types=1);

require_once __DIR__ . '/../../chatService.php';

use PHPUnit\Framework\TestCase;

final class AdminAuthTest extends TestCase
{
    private ?string $originalHash;

    protected function setUp(): void
    {
        $val = getenv('ADMIN_PASSWORD_HASH');
        $this->originalHash = $val === false ? null : $val;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ($this->originalHash === null) {
            putenv('ADMIN_PASSWORD_HASH');
        } else {
            putenv('ADMIN_PASSWORD_HASH=' . $this->originalHash);
        }
        $_SESSION = [];
    }

    public function testVerifyAdminPasswordAcceptsCorrect(): void
    {
        putenv('ADMIN_PASSWORD_HASH=' . password_hash('secret', PASSWORD_BCRYPT));
        $this->assertTrue(verify_admin_password('secret'));
    }

    public function testVerifyAdminPasswordRejectsWrong(): void
    {
        putenv('ADMIN_PASSWORD_HASH=' . password_hash('secret', PASSWORD_BCRYPT));
        $this->assertFalse(verify_admin_password('wrong'));
    }

    public function testVerifyAdminPasswordReturnsFalseWhenHashMissing(): void
    {
        putenv('ADMIN_PASSWORD_HASH');
        $this->assertFalse(verify_admin_password('anything'));
    }

    public function testVerifyAdminPasswordReturnsFalseWhenHashEmpty(): void
    {
        putenv('ADMIN_PASSWORD_HASH=');
        $this->assertFalse(verify_admin_password('anything'));
    }

    public function testAuthenticateAdminRequestSetsSessionOnSuccess(): void
    {
        putenv('ADMIN_PASSWORD_HASH=' . password_hash('secret', PASSWORD_BCRYPT));

        $this->assertTrue(authenticate_admin_request('secret'));
        $this->assertTrue($_SESSION['admin_authenticated']);
        $this->assertIsInt($_SESSION['admin_last_activity']);
        $this->assertGreaterThan(0, $_SESSION['admin_last_activity']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $_SESSION['admin_csrf_token']);
    }

    public function testAuthenticateAdminRequestLeavesSessionOnFailure(): void
    {
        putenv('ADMIN_PASSWORD_HASH=' . password_hash('secret', PASSWORD_BCRYPT));

        $this->assertFalse(authenticate_admin_request('wrong'));
        $this->assertArrayNotHasKey('admin_authenticated', $_SESSION);
        $this->assertArrayNotHasKey('admin_csrf_token', $_SESSION);
    }

    public function testAdminIsAuthenticatedTrueWhenFresh(): void
    {
        $_SESSION = [
            'admin_authenticated' => true,
            'admin_last_activity' => time(),
        ];
        $this->assertTrue(admin_is_authenticated());
    }

    public function testAdminIsAuthenticatedRefreshesLastActivity(): void
    {
        $old = time() - 10;
        $_SESSION = [
            'admin_authenticated' => true,
            'admin_last_activity' => $old,
        ];
        admin_is_authenticated();
        $this->assertGreaterThan($old, $_SESSION['admin_last_activity']);
    }

    public function testAdminIsAuthenticatedFalseWhenStale(): void
    {
        $_SESSION = [
            'admin_authenticated' => true,
            'admin_last_activity' => time() - ADMIN_SESSION_IDLE_SECONDS - 1,
        ];
        $this->assertFalse(admin_is_authenticated());
    }

    public function testAdminIsAuthenticatedFalseWhenEmpty(): void
    {
        $_SESSION = [];
        $this->assertFalse(admin_is_authenticated());
    }

    public function testAdminCsrfTokenGeneratesIfMissing(): void
    {
        $_SESSION = [];
        $token = admin_csrf_token();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame($token, $_SESSION['admin_csrf_token']);
    }

    public function testAdminCsrfTokenReturnsExistingIfSet(): void
    {
        $_SESSION = ['admin_csrf_token' => 'prefilled_token_value'];
        $this->assertSame('prefilled_token_value', admin_csrf_token());
    }

    public function testVerifyAdminCsrfAcceptsMatch(): void
    {
        $_SESSION = ['admin_csrf_token' => 'token123'];
        $this->assertTrue(verify_admin_csrf('token123'));
    }

    public function testVerifyAdminCsrfRejectsMismatch(): void
    {
        $_SESSION = ['admin_csrf_token' => 'token123'];
        $this->assertFalse(verify_admin_csrf('wrong'));
    }

    public function testVerifyAdminCsrfRejectsWhenSessionMissingToken(): void
    {
        $_SESSION = [];
        $this->assertFalse(verify_admin_csrf('any'));
    }

    public function testAdminLogoutClearsSession(): void
    {
        $_SESSION = [
            'admin_authenticated' => true,
            'admin_csrf_token' => 'x',
            'other' => 'keep-nothing',
        ];
        admin_logout();
        $this->assertSame([], $_SESSION);
    }
}
