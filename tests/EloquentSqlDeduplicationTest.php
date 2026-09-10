<?php

namespace Vdu\TisLogging\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vdu\TisLogging\Support\PendingQueryLog;
use Vdu\TisLogging\Tests\Fixtures\TestArticle;

class EloquentSqlDeduplicationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['audit.log_queries' => true]);

        Schema::create('test_articles', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->string('secret_code')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_articles');
        parent::tearDown();
    }

    /**
     * Atidėtas SQL įrašas įvertinamas atėjus kitai užklausai arba
     * užklausos pabaigoje - testuose paskubiname rankiniu flush().
     */
    protected function flushPending(): void
    {
        app(PendingQueryLog::class)->flush();
    }

    protected function logLines(): array
    {
        $file = $this->findLogFile('audit');

        if ($file === null) {
            return [];
        }

        return array_values(array_filter(explode("\n", trim(file_get_contents($file)))));
    }

    /** @test */
    public function an_eloquent_update_produces_only_one_entry()
    {
        $article = TestArticle::create(['title' => 'Pradinis']);
        $this->flushPending();

        $before = count($this->logLines());

        $article->update(['title' => 'Pakeistas']);
        $this->flushPending();

        $new = array_slice($this->logLines(), $before);

        // Vienas irasas, ne du ("update" + "db_update").
        $this->assertCount(1, $new);

        $decoded = json_decode($new[0], true);
        $this->assertSame('update', $decoded['context']['category']);
    }

    /** @test */
    public function the_eloquent_entry_is_kept_not_the_sql_one()
    {
        // Eloquent irasas vertingesnis - turi subject_type ir subject_id.
        $article = TestArticle::create(['title' => 'Pradinis']);
        $article->update(['title' => 'Pakeistas']);
        $this->flushPending();

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('update', $decoded['context']['category']);
        $this->assertSame(TestArticle::class, $decoded['context']['subject_type']);
        $this->assertSame($article->id, $decoded['context']['subject_id']);
    }

    /** @test */
    public function raw_db_table_updates_are_still_logged()
    {
        // Butent del siu SQL mechanizmas ir egzistuoja - Eloquent ju nemato.
        DB::table('test_articles')->insert(['title' => 'Per query builder']);
        $this->flushPending();

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('db_insert', $decoded['context']['category']);
        $this->assertSame('test_articles', $decoded['context']['context']['table']);
    }

    /** @test */
    public function a_raw_update_to_a_different_table_is_not_suppressed()
    {
        Schema::create('test_widgets', function ($table) {
            $table->increments('id');
            $table->string('title');
        });

        // Eloquent uzfiksuoja test_articles, bet test_widgets - ne.
        TestArticle::create(['title' => 'Eloquent irasas']);
        DB::table('test_widgets')->insert(['title' => 'Raw irasas']);
        $this->flushPending();

        $content = implode("\n", $this->logLines());

        $this->assertStringContainsString('"category":"create"', $content);
        $this->assertStringContainsString('test_widgets', $content);

        Schema::dropIfExists('test_widgets');
    }

    /** @test */
    public function deduplication_can_be_disabled()
    {
        config(['audit.skip_queries_recorded_by_eloquent' => false]);

        $article = TestArticle::create(['title' => 'Pradinis']);
        $this->flushPending();

        $before = count($this->logLines());

        $article->update(['title' => 'Pakeistas']);
        $this->flushPending();

        $new = array_slice($this->logLines(), $before);

        // Isjungus - abu irasai.
        $this->assertCount(2, $new);
    }

    /** @test */
    public function deferred_entries_keep_their_original_timestamp()
    {
        // Atidejus irasa, laikas turi likti ivykio, ne rasymo momento -
        // kitaip zurnale sutriktu chronologija.
        DB::table('test_articles')->insert(['title' => 'Testas']);

        $occurredAt = now()->toIso8601String();

        sleep(1);
        $this->flushPending();

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame(
            substr($occurredAt, 0, 19),
            substr($decoded['context']['occurred_at'], 0, 19)
        );
    }
}
