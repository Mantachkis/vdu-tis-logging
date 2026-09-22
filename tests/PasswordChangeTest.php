<?php

namespace Vdu\TisLogging\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vdu\TisLogging\Support\PendingQueryLog;
use Vdu\TisLogging\Tests\Fixtures\TestPost;

class PasswordChangeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['audit.log_queries' => true]);

        Schema::create('test_posts', function ($table) {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->string('password')->nullable();
            $table->string('pass')->nullable();
            $table->string('remember_token')->nullable();
            $table->string('pers_code')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_posts');
        parent::tearDown();
    }

    protected function logContent(): string
    {
        app(PendingQueryLog::class)->flush();

        $file = $this->findLogFile('audit');

        return $file ? file_get_contents($file) : '';
    }

    /** @test */
    public function an_eloquent_password_change_is_recorded_as_a_security_event()
    {
        $post = TestPost::create(['title' => 'Vartotojas']);
        $post->update(['password' => '$2y$10$naujashashasnaujashashasnaujas']);

        $content = $this->logContent();

        $this->assertStringContainsString('"category":"password_changed"', $content);
        $this->assertStringContainsString('"event_type":"security"', $content);
    }

    /** @test */
    public function the_password_value_is_never_written()
    {
        $post = TestPost::create(['title' => 'Vartotojas']);
        $post->update(['password' => 'ATVIRAS-SLAPTAZODIS-123']);

        $content = $this->logContent();

        $this->assertStringNotContainsString('ATVIRAS-SLAPTAZODIS-123', $content);
    }

    /** @test */
    public function the_record_whose_password_changed_is_identified()
    {
        $post = TestPost::create(['title' => 'Vartotojas']);
        $post->update(['pass' => 'naujas']);

        app(PendingQueryLog::class)->flush();
        $lines = array_values(array_filter(explode("\n", trim(
            file_get_contents($this->findLogFile('audit'))
        ))));

        $passwordEntry = null;
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if ($decoded['context']['category'] === 'password_changed') {
                $passwordEntry = $decoded;
            }
        }

        $this->assertNotNull($passwordEntry);
        $this->assertSame(TestPost::class, $passwordEntry['context']['subject_type']);
        $this->assertSame($post->id, $passwordEntry['context']['subject_id']);
        $this->assertSame(['pass'], $passwordEntry['context']['context']['fields']);
    }

    /** @test */
    public function other_fields_changed_together_with_the_password_are_still_logged()
    {
        $post = TestPost::create(['title' => 'Senas']);
        $post->update(['title' => 'Naujas', 'password' => 'x']);

        $content = $this->logContent();

        $this->assertStringContainsString('"category":"password_changed"', $content);
        $this->assertStringContainsString('"category":"update"', $content);
        $this->assertStringContainsString('Naujas', $content);
    }

    /** @test */
    public function clearing_remember_token_on_logout_is_not_a_password_change()
    {
        // Laravel keicia remember_token kiekvieno atsijungimo metu.
        $post = TestPost::create(['title' => 'Vartotojas']);
        $post->update(['remember_token' => 'naujas-tokenas']);

        $content = $this->logContent();

        $this->assertStringNotContainsString('password_changed', $content);
    }

    /** @test */
    public function a_db_table_password_change_is_recorded_too()
    {
        // Slaptazodzio keitimas per query builder, apeinant Eloquent.
        DB::table('test_posts')->insert(['title' => 'Vartotojas']);
        app(PendingQueryLog::class)->flush();

        DB::table('test_posts')->where('id', 1)->update(['pass' => 'naujas-hash']);

        $content = $this->logContent();

        $this->assertStringContainsString('"category":"password_changed"', $content);
        $this->assertStringNotContainsString('naujas-hash', $content);
    }

    /** @test */
    public function a_password_change_via_eloquent_is_not_duplicated_at_sql_level()
    {
        $post = TestPost::create(['title' => 'Vartotojas']);
        app(PendingQueryLog::class)->flush();

        $post->update(['password' => 'naujas']);

        $content = $this->logContent();

        $this->assertSame(1, substr_count($content, '"category":"password_changed"'));
    }

    /** @test */
    public function a_password_only_eloquent_change_is_not_duplicated_at_sql_level()
    {
        // Kai pasikeicia TIK slaptazodis, Eloquent irašo password_changed,
        // bet ne update - lentele vis tiek turi buti pazymeta, kad SQL
        // lygmuo neuzfiksuotu antra karta.
        $post = TestPost::create(['title' => 'Vartotojas']);
        app(PendingQueryLog::class)->flush();

        $post->update(['pass' => 'tik-slaptazodis']);

        $content = $this->logContent();

        $this->assertSame(1, substr_count($content, '"category":"password_changed"'));
        $this->assertStringNotContainsString('"category":"db_update"', $content);
    }

    /** @test */
    public function creating_a_user_with_a_password_is_not_a_password_change()
    {
        // Registracija - ne keitimas.
        TestPost::create(['title' => 'Naujas vartotojas', 'password' => 'pradinis']);

        $content = $this->logContent();

        $this->assertStringNotContainsString('password_changed', $content);
    }
}
