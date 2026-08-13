<?php

namespace Vdu\TisLogging\Tests;

class InstallCommandTest extends TestCase
{
    protected $envPath;
    protected $envBackup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envPath = base_path('.env');
        $this->envBackup = file_exists($this->envPath) ? file_get_contents($this->envPath) : null;

        file_put_contents($this->envPath, "APP_NAME=TestApp\n");
    }

    protected function tearDown(): void
    {
        if ($this->envBackup !== null) {
            file_put_contents($this->envPath, $this->envBackup);
        } elseif (file_exists($this->envPath)) {
            unlink($this->envPath);
        }

        parent::tearDown();
    }

    /** @test */
    public function it_appends_missing_env_variables()
    {
        $this->artisan('audit:install')->assertExitCode(0);

        $content = file_get_contents($this->envPath);

        $this->assertStringContainsString('AUDIT_LOG_APP_NAME=', $content);
        $this->assertStringContainsString('AUDIT_LOG_BASE_PATH=/home/logs', $content);
        $this->assertStringContainsString('AUDIT_LOG_AUDIT_FILENAME=audit.log', $content);
        $this->assertStringContainsString('AUDIT_LOG_ERROR_FILENAME=error.log', $content);
        $this->assertStringContainsString('AUDIT_LOG_RETENTION_DAYS=90', $content);
    }

    /** @test */
    public function it_does_not_duplicate_existing_env_variables()
    {
        file_put_contents($this->envPath, "APP_NAME=TestApp\nAUDIT_LOG_APP_NAME=jau-nustatyta\n");

        $this->artisan('audit:install')->assertExitCode(0);

        $content = file_get_contents($this->envPath);
        $occurrences = substr_count($content, 'AUDIT_LOG_APP_NAME=');

        $this->assertSame(1, $occurrences, 'AUDIT_LOG_APP_NAME neturi būti dubliuotas');
        $this->assertStringContainsString('AUDIT_LOG_APP_NAME=jau-nustatyta', $content);
    }

    /** @test */
    public function it_creates_audit_and_error_log_directories()
    {
        $this->artisan('audit:install')->assertExitCode(0);

        // .env pakeitimas testinėje aplinkoje nepaveikia env() realiuoju laiku
        // (Dotenv jo iš naujo neskaito vidury proceso), tad komanda naudoja
        // config('audit.base_path') - saugų testinį laikiną katalogą, ne
        // realų /home/logs. Tai patvirtina, kad katalogo kūrimo logika veikia,
        // NEDARANT poveikio realiam serveriui vykdant testus.
        $dir = rtrim(config('audit.base_path'), '/').'/'.config('audit.app_name');

        $this->assertDirectoryExists($dir.'/audit');
        $this->assertDirectoryExists($dir.'/error');
    }
}
