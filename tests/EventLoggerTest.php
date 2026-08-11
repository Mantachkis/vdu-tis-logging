<?php

namespace Vdu\TisLogging\Tests;

use Vdu\TisLogging\EventLogger;

class EventLoggerTest extends TestCase
{
    protected $logDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logDir = sys_get_temp_dir().'/vdu-tis-logging-tests/testapp';
        $this->cleanUpLogDir();
    }

    protected function tearDown(): void
    {
        $this->cleanUpLogDir();
        parent::tearDown();
    }

    protected function cleanUpLogDir(): void
    {
        if (is_dir($this->logDir)) {
            array_map('unlink', glob($this->logDir.'/*'));
            @rmdir($this->logDir);
        }
    }

    /** @test */
    public function it_creates_the_log_directory_automatically()
    {
        $this->app->make(EventLogger::class);

        $this->assertDirectoryExists($this->logDir);
    }

    /** @test */
    public function info_events_go_to_audit_log()
    {
        $logger = $this->app->make(EventLogger::class);

        $logger->info('login', 'Vartotojas prisijungė', ['user_id' => 1, 'user_identifier' => 'jonas@vdu.lt']);

        $auditContent = file_get_contents($this->logDir.'/audit.log');

        $this->assertStringContainsString('Vartotojas prisijungė', $auditContent);
        $this->assertStringContainsString('jonas@vdu.lt', $auditContent);
        $this->assertFileDoesNotExist($this->logDir.'/error.log');
    }

    /** @test */
    public function security_and_system_events_go_to_audit_log()
    {
        $logger = $this->app->make(EventLogger::class);

        $logger->security('login', 'Saugumo įvykis');
        $logger->system('cron', 'Sisteminis įvykis');

        $auditContent = file_get_contents($this->logDir.'/audit.log');

        $this->assertStringContainsString('Saugumo įvykis', $auditContent);
        $this->assertStringContainsString('Sisteminis įvykis', $auditContent);
    }

    /** @test */
    public function warning_and_error_events_go_to_error_log()
    {
        $logger = $this->app->make(EventLogger::class);

        $logger->warning('login_blocked', 'Bandymas prisijungti prie nepatvirtintos paskyros');
        $logger->error('exception', 'Nepagauta klaida');

        $errorContent = file_get_contents($this->logDir.'/error.log');

        $this->assertStringContainsString('Bandymas prisijungti prie nepatvirtintos paskyros', $errorContent);
        $this->assertStringContainsString('Nepagauta klaida', $errorContent);
    }

    /** @test */
    public function each_log_entry_is_valid_json()
    {
        $logger = $this->app->make(EventLogger::class);

        $logger->info('view', 'Peržiūrėtas įrašas', [
            'subject_type' => 'App\\Models\\Invoice',
            'subject_id' => 42,
        ]);

        $line = trim(file_get_contents($this->logDir.'/audit.log'));
        $decoded = json_decode($line, true);

        $this->assertNotNull($decoded, 'Žurnalo eilutė turi būti validus JSON');
        $this->assertSame('view', $decoded['context']['category']);
        $this->assertSame('App\\Models\\Invoice', $decoded['context']['subject_type']);
        $this->assertSame(42, $decoded['context']['subject_id']);
    }
}
