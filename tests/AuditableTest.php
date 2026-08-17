<?php

namespace Vdu\TisLogging\Tests;

use Illuminate\Support\Facades\Schema;
use Vdu\TisLogging\Tests\Fixtures\TestPost;

class AuditableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('test_posts', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->string('password')->nullable();
            $table->string('pass')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_posts');
        parent::tearDown();
    }

    /** @test */
    public function creating_a_model_logs_the_new_values()
    {
        TestPost::create(['title' => 'Pirmas įrašas', 'password' => 'slaptas']);

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('create', $decoded['context']['category']);
        $this->assertSame('Pirmas įrašas', $decoded['context']['new_values']['title']);
        // Jautrus laukas turi būti pašalintas net jei jis buvo užpildytas.
        $this->assertArrayNotHasKey('password', $decoded['context']['new_values']);
    }

    /** @test */
    public function updating_a_model_logs_old_and_new_values()
    {
        $post = TestPost::create(['title' => 'Senas pavadinimas']);
        $post->update(['title' => 'Naujas pavadinimas']);

        // Paskutinė eilutė faile - tai "update" įrašas (create buvo prieš tai).
        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('update', $decoded['context']['category']);
        $this->assertSame('Senas pavadinimas', $decoded['context']['old_values']['title']);
        $this->assertSame('Naujas pavadinimas', $decoded['context']['new_values']['title']);
    }

    /** @test */
    public function old_values_only_contains_changed_fields_not_the_whole_record()
    {
        // Duomenų minimizavimo testas (BDAR 5.1.c) - old_values neturi
        // atskleisti nepakeistų laukų (pvz. id, timestamps, kitų stulpelių).
        $post = TestPost::create(['title' => 'Pradinis pavadinimas']);
        $post->update(['title' => 'Pakeistas pavadinimas']);

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame(['title'], array_keys($decoded['context']['old_values']));
        $this->assertArrayNotHasKey('id', $decoded['context']['old_values']);
        $this->assertArrayNotHasKey('created_at', $decoded['context']['old_values']);
        $this->assertArrayNotHasKey('updated_at', $decoded['context']['old_values']);
    }

    /** @test */
    public function pass_field_variant_is_excluded_by_default()
    {
        // Kai kurie projektai/lentelės naudoja "pass" vietoj "password" -
        // patikriname, kad exclude sąrašas apima ir šį variantą.
        TestPost::create(['title' => 'Su pass lauku', 'pass' => 'slaptazodis']);

        $decoded = $this->lastLogEntry('audit');

        $this->assertArrayNotHasKey('pass', $decoded['context']['new_values']);
    }

    /** @test */
    public function deleting_a_model_logs_the_old_values()
    {
        $post = TestPost::create(['title' => 'Bus ištrintas']);
        $post->delete();

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('delete', $decoded['context']['category']);
        $this->assertSame('Bus ištrintas', $decoded['context']['old_values']['title']);
    }
}
