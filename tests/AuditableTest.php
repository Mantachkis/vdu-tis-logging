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
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_posts');
        parent::tearDown();
    }

    /**
     * Grąžina paskutinės žurnalo eilutės (JSON) turinį, dekoduotą į masyvą.
     *
     * Naudojame paskutinę eilutę, o ne visą failą, kad galėtume atskirti
     * kelis to pačio testo veiksmus (pvz. create+update) tarp savęs.
     */
    protected function lastLogEntry(string $filename): array
    {
        $lines = array_values(array_filter(
            explode("\n", trim(file_get_contents($this->logDir().'/'.$filename)))
        ));

        return json_decode(end($lines), true);
    }

    /** @test */
    public function creating_a_model_logs_the_new_values()
    {
        TestPost::create(['title' => 'Pirmas įrašas', 'password' => 'slaptas']);

        $decoded = $this->lastLogEntry('audit.log');

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
        $decoded = $this->lastLogEntry('audit.log');

        $this->assertSame('update', $decoded['context']['category']);
        $this->assertSame('Senas pavadinimas', $decoded['context']['old_values']['title']);
        $this->assertSame('Naujas pavadinimas', $decoded['context']['new_values']['title']);
    }

    /** @test */
    public function deleting_a_model_logs_the_old_values()
    {
        $post = TestPost::create(['title' => 'Bus ištrintas']);
        $post->delete();

        $decoded = $this->lastLogEntry('audit.log');

        $this->assertSame('delete', $decoded['context']['category']);
        $this->assertSame('Bus ištrintas', $decoded['context']['old_values']['title']);
    }
}
