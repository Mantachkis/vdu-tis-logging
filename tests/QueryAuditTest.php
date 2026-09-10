<?php

namespace Vdu\TisLogging\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vdu\TisLogging\Support\PendingQueryLog;

class QueryAuditTest extends TestCase
{
    /**
     * SQL irasai nuo v2.14.0 trumpam atidedami (zr. PendingQueryLog), kad
     * butu galima patikrinti, ar ju nedubliuoja Eloquent mechanizmas.
     * Testuose paskubiname ta ivertinima, kad galetume iskart skaityti
     * zurnala.
     */
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
    public function it_logs_db_table_updates_with_readable_column_value_pairs()
    {
        DB::table('test_widgets')->insert(['title' => 'Pradinis', 'sort' => 1]);
        DB::table('test_widgets')->where('id', 1)->update(['title' => 'Pakeistas']);

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('db_update', $decoded['context']['category']);
        $this->assertSame('test_widgets', $decoded['context']['context']['table']);
        // Svarbiausia - stulpelis surištas su reikšme, ne atskiras masyvas.
        $this->assertSame('Pakeistas', $decoded['context']['new_values']['title']);
        $this->assertSame(1, $decoded['context']['context']['conditions']['id']);
    }

    /** @test */
    public function it_logs_inserts_with_column_value_pairs()
    {
        DB::table('test_widgets')->insert(['title' => 'Naujas įrašas', 'sort' => 5]);

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('db_insert', $decoded['context']['category']);
        $this->assertSame('Naujas įrašas', $decoded['context']['new_values']['title']);
        $this->assertSame(5, $decoded['context']['new_values']['sort']);
    }

    /** @test */
    public function it_logs_deletes_with_conditions()
    {
        DB::table('test_widgets')->insert(['title' => 'Bus ištrintas', 'sort' => 1]);
        DB::table('test_widgets')->where('id', 1)->delete();

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('db_delete', $decoded['context']['category']);
        $this->assertSame('test_widgets', $decoded['context']['context']['table']);
        $this->assertSame(1, $decoded['context']['context']['conditions']['id']);
    }

    /** @test */
    public function the_table_name_appears_in_the_message()
    {
        DB::table('test_widgets')->insert(['title' => 'Testas', 'sort' => 1]);

        $decoded = $this->lastLogEntry('audit');

        $this->assertStringContainsString('test_widgets', $decoded['message']);
    }

    /** @test */
    public function raw_sql_is_omitted_when_parsing_succeeds()
    {
        DB::table('test_widgets')->insert(['title' => 'Testas', 'sort' => 1]);

        $decoded = $this->lastLogEntry('audit');

        // Kai stulpelius pavyko išanalizuoti, žalias SQL nebereikalingas -
        // visa informacija jau new_values lauke.
        $this->assertArrayNotHasKey('sql', $decoded['context']['context']);
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
    public function it_redacts_password_hashes()
    {
        DB::table('test_widgets')->insert([
            'title' => '$2y$10$abcdefghijklmnopqrstuvwxyz123456789',
            'sort' => 1,
        ]);

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('[REDACTED]', $decoded['context']['new_values']['title']);
    }

    /** @test */
    public function it_replaces_base64_images_with_a_placeholder()
    {
        $html = '<p>Tekstas</p><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUg'
            .str_repeat('AAAA', 50).'=" /><p>Pabaiga</p>';

        DB::table('test_widgets')->insert(['title' => $html, 'sort' => 1]);

        $decoded = $this->lastLogEntry('audit');

        $this->assertStringContainsString('[BASE64_IMAGE]', $decoded['context']['new_values']['title']);
        $this->assertStringNotContainsString('iVBORw0KGgo', $decoded['context']['new_values']['title']);
    }

    /** @test */
    public function it_truncates_very_long_values()
    {
        DB::table('test_widgets')->insert([
            'title' => str_repeat('a', 800),
            'sort' => 1,
        ]);

        $decoded = $this->lastLogEntry('audit');

        $this->assertStringContainsString('[TRUNCATED]', $decoded['context']['new_values']['title']);
    }

    /** @test */
    public function it_captures_old_values_for_updates()
    {
        DB::table('test_widgets')->insert(['title' => 'Sena antraste', 'sort' => 1]);
        DB::table('test_widgets')->where('id', 1)->update(['title' => 'Nauja antraste']);

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('Sena antraste', $decoded['context']['old_values']['title']);
        $this->assertSame('Nauja antraste', $decoded['context']['new_values']['title']);
    }

    /** @test */
    public function it_only_reports_columns_that_actually_changed()
    {
        DB::table('test_widgets')->insert(['title' => 'Antraste', 'sort' => 7]);

        // Forma siuncia VISUS laukus, bet realiai keiciasi tik "sort".
        DB::table('test_widgets')->where('id', 1)->update([
            'title' => 'Antraste',
            'sort' => 9,
        ]);

        $decoded = $this->lastLogEntry('audit');

        $this->assertArrayNotHasKey('title', $decoded['context']['new_values']);
        $this->assertSame(7, (int) $decoded['context']['old_values']['sort']);
        $this->assertSame(9, $decoded['context']['new_values']['sort']);
    }

    /** @test */
    public function updates_that_change_nothing_are_not_logged()
    {
        DB::table('test_widgets')->insert(['title' => 'Nepakis', 'sort' => 1]);

        $file = $this->findLogFile('audit');
        $before = count(array_filter(explode("\n", trim(file_get_contents($file)))));

        DB::table('test_widgets')->where('id', 1)->update(['title' => 'Nepakis', 'sort' => 1]);

        $after = count(array_filter(explode("\n", trim(file_get_contents($file)))));

        $this->assertSame($before, $after, 'UPDATE be realiu pakeitimu neturi buti fiksuojamas');
    }

    /** @test */
    public function it_captures_the_deleted_row_as_old_values()
    {
        DB::table('test_widgets')->insert(['title' => 'Istrinamas', 'sort' => 3]);
        DB::table('test_widgets')->where('id', 1)->delete();

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('db_delete', $decoded['context']['category']);
        $this->assertSame('Istrinamas', $decoded['context']['old_values']['title']);
    }

    /** @test */
    public function old_values_capture_can_be_disabled()
    {
        config(['audit.capture_old_values' => false]);

        DB::table('test_widgets')->insert(['title' => 'Sena', 'sort' => 1]);
        DB::table('test_widgets')->where('id', 1)->update(['title' => 'Nauja']);

        $decoded = $this->lastLogEntry('audit');

        $this->assertNull($decoded['context']['old_values']);
        $this->assertSame('Nauja', $decoded['context']['new_values']['title']);
    }
}
