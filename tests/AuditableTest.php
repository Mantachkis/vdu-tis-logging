<?php

namespace Vdu\TisLogging\Tests;

use Illuminate\Support\Facades\Schema;
use Vdu\TisLogging\Tests\Fixtures\TestPost;

class AuditableTest extends TestCase
{
    protected $logDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDir = sys_get_temp_dir().'/vdu-tis-logging-tests/testapp';
        $this->cleanUpLogDir();

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
    public function creating_a_model_logs_the_new_values()
    {
        TestPost::create(['title' => 'Pirmas įrašas', 'password' => 'slaptas']);

        $content = file_get_contents($this->logDir.'/audit.log');
        $decoded = json_decode(trim($content), true);

        $this->assertSame('create', $decoded['context']['category']);
        $this->assertSame('Pirmas įrašas', $decoded['context']['new_values']['title']);
        // Jautrus laukas turi būti pašalintas net jei jis buvo užpildytas.
        $this->assertArrayNotHasKey('password', $decoded['context']['new_values']);
    }

    /** @test */
    public function updating_a_model_logs_old_and_new_values()
    {
        $post = TestPost::create(['title' => 'Senas pavadinimas']);

        // Pirmas įrašas faile yra "create" - nusivalome, kad testuotume tik update.
        $this->cleanUpLogDir();

        $post->update(['title' => 'Naujas pavadinimas']);

        $content = file_get_contents($this->logDir.'/audit.log');
        $decoded = json_decode(trim($content), true);

        $this->assertSame('update', $decoded['context']['category']);
        $this->assertSame('Senas pavadinimas', $decoded['context']['old_values']['title']);
        $this->assertSame('Naujas pavadinimas', $decoded['context']['new_values']['title']);
    }

    /** @test */
    public function deleting_a_model_logs_the_old_values()
    {
        $post = TestPost::create(['title' => 'Bus ištrintas']);
        $this->cleanUpLogDir();

        $post->delete();

        $content = file_get_contents($this->logDir.'/audit.log');
        $decoded = json_decode(trim($content), true);

        $this->assertSame('delete', $decoded['context']['category']);
        $this->assertSame('Bus ištrintas', $decoded['context']['old_values']['title']);
    }
}
