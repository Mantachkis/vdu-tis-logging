<?php

namespace Vdu\TisLogging\Tests;

use Illuminate\Support\Facades\Schema;
use Vdu\TisLogging\Tests\Fixtures\TestArticle;
use Vdu\TisLogging\Tests\Fixtures\TestPost;

class GlobalModelAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('test_articles', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->string('secret_code')->nullable();
            $table->timestamps();
        });

        Schema::create('test_posts', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->string('password')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_articles');
        Schema::dropIfExists('test_posts');
        parent::tearDown();
    }

    /** @test */
    public function it_automatically_logs_creation_of_a_model_without_the_auditable_trait()
    {
        TestArticle::create(['title' => 'Automatiškai audituotas įrašas']);

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('create', $decoded['context']['category']);
        $this->assertSame(TestArticle::class, $decoded['context']['subject_type']);
        $this->assertSame('Automatiškai audituotas įrašas', $decoded['context']['new_values']['title']);
    }

    /** @test */
    public function it_automatically_logs_updates_with_only_changed_fields()
    {
        $article = TestArticle::create(['title' => 'Senas pavadinimas']);
        $article->update(['title' => 'Naujas pavadinimas']);

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('update', $decoded['context']['category']);
        $this->assertSame(['title'], array_keys($decoded['context']['old_values']));
        $this->assertSame('Senas pavadinimas', $decoded['context']['old_values']['title']);
        $this->assertSame('Naujas pavadinimas', $decoded['context']['new_values']['title']);
    }

    /** @test */
    public function it_automatically_logs_deletion()
    {
        $article = TestArticle::create(['title' => 'Bus ištrintas']);
        $article->delete();

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('delete', $decoded['context']['category']);
        $this->assertSame('Bus ištrintas', $decoded['context']['old_values']['title']);
    }

    /** @test */
    public function models_with_auditable_trait_are_not_logged_twice()
    {
        // TestPost JAU naudoja Auditable trait - globalus listener'is turi
        // ji PRALEISTI, kad įvykis nebūtų užfiksuotas du kartus (vieną
        // kartą per AuditObserver, antrą - per globalų listener'į).
        TestPost::create(['title' => 'Testinis postas']);

        $file = $this->findLogFile('audit');
        $lines = array_values(array_filter(explode("\n", trim(file_get_contents($file)))));

        $this->assertCount(1, $lines, 'TestPost su Auditable trait neturi būti audituojamas du kartus');
    }

    /** @test */
    public function models_listed_in_exclude_models_config_are_not_logged()
    {
        config(['audit.exclude_models' => [TestArticle::class]]);

        TestArticle::create(['title' => 'Neturi būti audituotas']);

        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function nothing_is_logged_when_audit_all_models_is_disabled()
    {
        config(['audit.audit_all_models' => false]);

        TestArticle::create(['title' => 'Neturi būti audituotas']);

        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function auditexclude_on_a_model_still_filters_fields_in_global_mode()
    {
        // Net be Auditable trait, modelis vis tiek gali apibrėžti
        // auditExclude() metodą - globalus listener'is jį gerbia.
        $article = new class extends TestArticle {
            public function auditExclude(): array
            {
                return ['secret_code'];
            }
        };
        $article->setTable('test_articles');

        $article->title = 'Su paslaptimi';
        $article->secret_code = 'SLAPTAS-123';
        $article->save();

        $decoded = $this->lastLogEntry('audit');

        $this->assertArrayNotHasKey('secret_code', $decoded['context']['new_values']);
        $this->assertSame('Su paslaptimi', $decoded['context']['new_values']['title']);
    }
}
