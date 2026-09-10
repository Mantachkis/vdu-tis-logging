<?php

namespace Vdu\TisLogging\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vdu\TisLogging\Support\PendingQueryLog;
use Vdu\TisLogging\Support\SqlStatementParser;
use Vdu\TisLogging\Tests\Fixtures\TestPost;

class SensitiveFieldsInSqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['audit.log_queries' => true]);

        Schema::create('test_posts', function ($table) {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->string('password')->nullable();
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

    protected function findLogFile(string $channel): ?string
    {
        app(PendingQueryLog::class)->flush();

        return parent::findLogFile($channel);
    }

    protected function lastLogEntry(string $channel): array
    {
        app(PendingQueryLog::class)->flush();

        return parent::lastLogEntry($channel);
    }

    /** @test */
    public function remember_token_is_never_written_at_sql_level()
    {
        // Realus atvejis: Laravel atsijungiant isvalo remember_token per
        // query builder. Iki v2.15.0 tas raktas patekdavo i zurnala -
        // o jis leidzia prisijungti kaip tas vartotojas.
        DB::table('test_posts')->insert(['title' => 'Testas', 'remember_token' => 'SLAPTAS-RAKTAS-123']);

        $content = file_get_contents($this->findLogFile('audit'));

        $this->assertStringNotContainsString('SLAPTAS-RAKTAS-123', $content);
    }

    /** @test */
    public function password_is_never_written_at_sql_level()
    {
        DB::table('test_posts')->insert(['title' => 'Testas', 'password' => 'atviras-slaptazodis']);

        $content = file_get_contents($this->findLogFile('audit'));

        $this->assertStringNotContainsString('atviras-slaptazodis', $content);
    }

    /** @test */
    public function pers_code_is_never_written_at_sql_level()
    {
        DB::table('test_posts')->insert(['title' => 'Testas', 'pers_code' => '38711100708']);

        $content = file_get_contents($this->findLogFile('audit'));

        $this->assertStringNotContainsString('38711100708', $content);
    }

    /** @test */
    public function exclusion_is_case_insensitive_for_oracle_style_columns()
    {
        // Oracle stulpelius grazina DIDZIOSIOMIS (REMEMBER_TOKEN), o
        // exclude sarase jie rasomi mazosiomis.
        $parser = new SqlStatementParser();

        $result = $parser->parse(
            'update "SSO_USERS" set "REMEMBER_TOKEN" = ?, "TITLE" = ? where "ID" = ?',
            ['slaptas-raktas', 'Antraste', 5]
        );

        $this->assertSame('slaptas-raktas', $result['values']['REMEMBER_TOKEN']);

        // O listener'is ji pasalins - patikriname per realia uzklausa.
        DB::table('test_posts')->insert(['title' => 'X', 'remember_token' => 'DIDZIOSIOS-TEST']);

        $content = file_get_contents($this->findLogFile('audit'));
        $this->assertStringNotContainsString('DIDZIOSIOS-TEST', $content);
    }

    /** @test */
    public function updates_touching_only_sensitive_fields_are_not_logged_at_all()
    {
        // Fiksuoti "kazkas pasikeite", nenurodant ka, yra beprasmis irasas.
        DB::table('test_posts')->insert(['title' => 'Pradinis']);
        app(PendingQueryLog::class)->flush();

        $before = count(array_filter(explode("\n", trim(
            file_get_contents($this->findLogFile('audit'))
        ))));

        DB::table('test_posts')->where('id', 1)->update(['remember_token' => 'NAUJAS-RAKTAS']);

        $after = count(array_filter(explode("\n", trim(
            file_get_contents($this->findLogFile('audit'))
        ))));

        $this->assertSame($before, $after);
    }

    /** @test */
    public function eloquent_updates_touching_only_sensitive_fields_are_not_logged()
    {
        // Laravel atsijungiant isvalo remember_token - toks irasas butu
        // tuscias (old_values ir new_values po filtravimo lieka tusti).
        $post = TestPost::create(['title' => 'Pradinis']);
        app(PendingQueryLog::class)->flush();

        $before = count(array_filter(explode("\n", trim(
            file_get_contents($this->findLogFile('audit'))
        ))));

        $post->update(['remember_token' => 'NAUJAS-RAKTAS']);

        $after = count(array_filter(explode("\n", trim(
            file_get_contents($this->findLogFile('audit'))
        ))));

        $this->assertSame($before, $after);
    }

    /** @test */
    public function ordinary_fields_are_still_logged_alongside_sensitive_ones()
    {
        DB::table('test_posts')->insert([
            'title' => 'Matoma antraste',
            'remember_token' => 'PASLEPTAS',
        ]);

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('Matoma antraste', $decoded['context']['new_values']['title']);
        $this->assertArrayNotHasKey('remember_token', $decoded['context']['new_values']);
    }
}
