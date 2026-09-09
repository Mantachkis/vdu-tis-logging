<?php

namespace Vdu\TisLogging\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Vdu\TisLogging\EventLogger;
use Vdu\TisLogging\Support\QueueContext;

class QueueContextTest extends TestCase
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

    protected function context(): QueueContext
    {
        return app(QueueContext::class);
    }

    /** @test */
    public function it_captures_the_current_user_when_a_job_is_dispatched()
    {
        $this->be($this->fakeUser(33131, 'mantas.garliauskas@vdu.lt'));

        $captured = $this->context()->capture();

        $this->assertSame(33131, $captured['user_id']);
        $this->assertSame('mantas.garliauskas@vdu.lt', $captured['user_identifier']);
    }

    /** @test */
    public function events_logged_inside_a_job_get_the_dispatching_users_identity()
    {
        // Imituojame darbuotojo procesą: jokio prisijungusio vartotojo,
        // bet kontekstas atkurtas iš darbo payload'o.
        $this->context()->set([
            'user_id' => 33131,
            'user_identifier' => 'mantas.garliauskas@vdu.lt',
            'ip_address' => '193.219.38.75',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0)',
        ]);

        app(EventLogger::class)->info('mail_sent', 'Išsiųstas naujienlaiškis');

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame(33131, $decoded['context']['user_id']);
        $this->assertSame('mantas.garliauskas@vdu.lt', $decoded['context']['user_identifier']);
        $this->assertSame('193.219.38.75', $decoded['context']['ip_address']);
    }

    /** @test */
    public function without_queue_context_the_user_stays_null()
    {
        // Be konteksto - toks pat elgesys kaip anksčiau: "kažkas" atliko
        // veiksmą. Tai patvirtina, kad mechanizmas realiai kažką pakeičia.
        $this->context()->clear();

        app(EventLogger::class)->info('mail_sent', 'Išsiųstas naujienlaiškis');

        $decoded = $this->lastLogEntry('audit');

        $this->assertNull($decoded['context']['user_id']);
        $this->assertNull($decoded['context']['user_identifier']);
    }

    /** @test */
    public function an_authenticated_user_takes_precedence_over_queue_context()
    {
        // Jei kontekstas kažkodėl liko iš ankstesnio darbo, realiai
        // prisijungęs vartotojas turi pirmenybę - kitaip veiksmas būtų
        // priskirtas ne tam žmogui.
        $this->context()->set([
            'user_id' => 999,
            'user_identifier' => 'senas@vdu.lt',
        ]);

        $this->be($this->fakeUser(33131, 'mantas.garliauskas@vdu.lt'));

        app(EventLogger::class)->info('update', 'Pakeitimas');

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame(33131, $decoded['context']['user_id']);
        $this->assertSame('mantas.garliauskas@vdu.lt', $decoded['context']['user_identifier']);
    }

    /** @test */
    public function explicit_data_takes_precedence_over_everything()
    {
        $this->context()->set(['user_id' => 999, 'user_identifier' => 'queue@vdu.lt']);

        app(EventLogger::class)->security('login_failed', 'Nepavykęs bandymas', [
            'user_identifier' => 'bandytas@vdu.lt',
        ]);

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('bandytas@vdu.lt', $decoded['context']['user_identifier']);
    }

    /** @test */
    public function clearing_the_context_prevents_leaking_between_jobs()
    {
        // Darbuotojo procesas gyvuoja ilgai ir apdoroja daug darbų -
        // neišvalius konteksto, kito vartotojo darbas būtų priskirtas
        // ankstesniam.
        $this->context()->set(['user_id' => 33131, 'user_identifier' => 'pirmas@vdu.lt']);
        $this->assertTrue($this->context()->has());

        $this->context()->clear();

        $this->assertFalse($this->context()->has());
        $this->assertNull($this->context()->get('user_id'));
    }

    /** @test */
    public function capture_returns_empty_when_there_is_no_user_or_request()
    {
        $captured = $this->context()->capture();

        $this->assertArrayNotHasKey('user_id', $captured);
    }
}
