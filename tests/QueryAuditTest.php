<?php

namespace Vdu\TisLogging\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QueryAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['audit.log_queries' => true]);

        Schema::create('test_widgets', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->integer('sort')->default(0);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_widgets');
        parent::tearDown();
    }

    /** @test */
    public function it_logs_db_table_updates_that_bypass_eloquent()
    {
        DB::table('test_widgets')->insert(['title' => 'Pradinis', 'sort' => 1]);

        DB::table('test_widgets')->where('id', 1)->update(['title' => 'Pakeistas per query builder']);

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('db_update', $decoded['context']['category']);
        $this->assertStringContainsString('update', strtolower($decoded['context']['context']['sql']));
        $this->assertContains('Pakeistas per query builder', $decoded['context']['context']['bindings']);
    }

    /** @test */
    public function it_logs_db_table_inserts()
    {
        DB::table('test_widgets')->insert(['title' => 'Naujas įrašas', 'sort' => 5]);

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('db_insert', $decoded['context']['category']);
        $this->assertContains('Naujas įrašas', $decoded['context']['context']['bindings']);
    }

    /** @test */
    public function it_logs_db_table_deletes()
    {
        DB::table('test_widgets')->insert(['title' => 'Bus ištrintas', 'sort' => 1]);
        DB::table('test_widgets')->where('id', 1)->delete();

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('db_delete', $decoded['context']['category']);
    }

    /** @test */
    public function it_does_not_log_select_queries()
    {
        DB::table('test_widgets')->insert(['title' => 'Testas', 'sort' => 1]);

        $file = $this->findLogFile('audit');
        $countBefore = count(array_filter(explode("\n", trim(file_get_contents($file)))));

        DB::table('test_widgets')->where('id', 1)->get();
        DB::table('test_widgets')->count();

        $countAfter = count(array_filter(explode("\n", trim(file_get_contents($file)))));

        $this->assertSame($countBefore, $countAfter, 'SELECT užklausos neturi būti fiksuojamos');
    }

    /** @test */
    public function it_does_not_log_when_disabled()
    {
        config(['audit.log_queries' => false]);

        DB::table('test_widgets')->insert(['title' => 'Neturi būti fiksuotas', 'sort' => 1]);

        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function it_skips_excluded_tables()
    {
        config(['audit.exclude_query_tables' => ['test_widgets']]);

        DB::table('test_widgets')->insert(['title' => 'Praleistas', 'sort' => 1]);

        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function it_redacts_password_hashes_in_bindings()
    {
        DB::table('test_widgets')->insert([
            'title' => '$2y$10$abcdefghijklmnopqrstuvwxyz123456789',
            'sort' => 1,
        ]);

        $decoded = $this->lastLogEntry('audit');

        $this->assertContains('[REDACTED]', $decoded['context']['context']['bindings']);
    }

    /** @test */
    public function it_truncates_very_long_binding_values()
    {
        DB::table('test_widgets')->insert([
            'title' => str_repeat('a', 800),
            'sort' => 1,
        ]);

        $decoded = $this->lastLogEntry('audit');

        $found = false;
        foreach ($decoded['context']['context']['bindings'] as $binding) {
            if (is_string($binding) && strpos($binding, '[TRUNCATED]') !== false) {
                $found = true;
            }
        }

        $this->assertTrue($found, 'Ilgos reikšmės turi būti trumpinamos');
    }
}
