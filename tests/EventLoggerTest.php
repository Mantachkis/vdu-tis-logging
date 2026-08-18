<?php

namespace Vdu\TisLogging\Tests;

use Vdu\TisLogging\EventLogger;

class EventLoggerTest extends TestCase
{
    /** @test */
    public function it_creates_audit_and_error_subdirectories_automatically()
    {
        $this->app->make(EventLogger::class);

        $this->assertDirectoryExists($this->logDir().'/audit');
        $this->assertDirectoryExists($this->logDir().'/error');
    }

    /** @test */
    public function info_events_go_to_audit_channel()
    {
        $logger = $this->app->make(EventLogger::class);

        $logger->info('login', 'Vartotojas prisijungė', ['user_id' => 1, 'user_identifier' => 'jonas@vdu.lt']);

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('Vartotojas prisijungė', $decoded['message']);
        $this->assertSame('jonas@vdu.lt', $decoded['context']['user_identifier']);
        $this->assertNull($this->findLogFile('error'));
    }

    /** @test */
    public function security_and_system_events_go_to_audit_channel()
    {
        $logger = $this->app->make(EventLogger::class);

        $logger->security('login', 'Saugumo įvykis');
        $decoded = $this->lastLogEntry('audit');
        $this->assertSame('Saugumo įvykis', $decoded['message']);

        $logger->system('cron', 'Sisteminis įvykis');
        $decoded = $this->lastLogEntry('audit');
        $this->assertSame('Sisteminis įvykis', $decoded['message']);
    }

    /** @test */
    public function warning_and_error_events_go_to_error_channel()
    {
        $logger = $this->app->make(EventLogger::class);

        $logger->warning('login_blocked', 'Bandymas prisijungti prie nepatvirtintos paskyros');
        $decoded = $this->lastLogEntry('error');
        $this->assertSame('Bandymas prisijungti prie nepatvirtintos paskyros', $decoded['message']);

        $logger->error('exception', 'Nepagauta klaida');
        $decoded = $this->lastLogEntry('error');
        $this->assertSame('Nepagauta klaida', $decoded['message']);
    }

    /** @test */
    public function log_filename_contains_todays_date()
    {
        $logger = $this->app->make(EventLogger::class);
        $logger->info('view', 'Testinis įrašas');

        $file = $this->findLogFile('audit');
        $today = now()->format('Y-m-d');

        $this->assertStringContainsString($today, basename($file));
    }

    /** @test */
    public function each_log_entry_is_valid_json()
    {
        $logger = $this->app->make(EventLogger::class);

        $logger->info('view', 'Peržiūrėtas įrašas', [
            'subject_type' => 'App\\Models\\Invoice',
            'subject_id' => 42,
        ]);

        $decoded = $this->lastLogEntry('audit');

        $this->assertNotNull($decoded, 'Žurnalo eilutė turi būti validus JSON');
        $this->assertSame('view', $decoded['context']['category']);
        $this->assertSame('App\\Models\\Invoice', $decoded['context']['subject_type']);
        $this->assertSame(42, $decoded['context']['subject_id']);
    }

    /** @test */
    public function it_resolves_authenticated_user_from_a_non_default_guard()
    {
        // Simuliuojame projektą su keliais guard'ais (kaip pilotiniame
        // projekte: 'web' + custom 'espUser'), kur prisijungusio vartotojo
        // sesija yra NE numatytajame guard'e.
        config(['auth.defaults.guard' => 'other_default_guard_with_nobody']);
        config(['auth.guards.espUser_test' => ['driver' => 'session', 'provider' => 'users']]);

        $user = new class implements \Illuminate\Contracts\Auth\Authenticatable {
            public $id = 55;
            public $email = 'espuser@vdu.lt';
            public function getAuthIdentifierName() { return 'id'; }
            public function getAuthIdentifier() { return $this->id; }
            public function getAuthPassword() { return 'hash'; }
            public function getRememberToken() { return null; }
            public function setRememberToken($value) {}
            public function getRememberTokenName() { return 'remember_token'; }
        };

        \Illuminate\Support\Facades\Auth::guard('espUser_test')->login($user);

        $logger = $this->app->make(EventLogger::class);
        $logger->info('login', 'Prisijungta per espUser guard');

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame(55, $decoded['context']['user_id']);
        $this->assertSame('espuser@vdu.lt', $decoded['context']['user_identifier']);
    }
}
