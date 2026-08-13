<?php

namespace Vdu\TisLogging\Tests;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;

class AuthListenersTest extends TestCase
{
    protected function fakeUser(int $id, string $email): Authenticatable
    {
        return new class ($id, $email) implements Authenticatable {
            public $id;
            public $email;

            public function __construct($id, $email)
            {
                $this->id = $id;
                $this->email = $email;
            }

            public function getAuthIdentifierName() { return 'id'; }
            public function getAuthIdentifier() { return $this->id; }
            public function getAuthPassword() { return 'hash'; }
            public function getRememberToken() { return null; }
            public function setRememberToken($value) {}
            public function getRememberTokenName() { return 'remember_token'; }
        };
    }

    /** @test */
    public function successful_login_is_logged_to_audit_channel()
    {
        $user = $this->fakeUser(7, 'jonas@vdu.lt');

        Event::dispatch(new Login('web', $user, false));

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('login', $decoded['context']['category']);
        $this->assertSame('jonas@vdu.lt', $decoded['context']['user_identifier']);
        $this->assertSame(7, $decoded['context']['user_id']);
    }

    /** @test */
    public function logout_is_logged_to_audit_channel()
    {
        $user = $this->fakeUser(7, 'jonas@vdu.lt');

        Event::dispatch(new Logout('web', $user));

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('logout', $decoded['context']['category']);
    }

    /** @test */
    public function failed_login_is_logged_without_password()
    {
        Event::dispatch(new Failed('web', null, ['username' => 'jonas', 'password' => 'slaptas']));

        $decoded = $this->lastLogEntry('audit');
        $rawContent = file_get_contents($this->findLogFile('audit'));

        $this->assertSame('login_failed', $decoded['context']['category']);
        $this->assertSame('jonas', $decoded['context']['user_identifier']);
        $this->assertStringNotContainsString('slaptas', $rawContent);
    }
}
