<?php

namespace Vdu\TisLogging\Tests;

class ClientEventTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // Endpoint'as registruojamas boot() metu, tad įjungti reikia
        // ČIA, o ne setUp() - kitaip maršrutas nebūtų užregistruotas.
        $app['config']->set('audit.client_events.enabled', true);
        $app['config']->set('audit.client_events.middleware', []);
    }

    /** @test */
    public function it_logs_a_client_reported_export()
    {
        $response = $this->postJson('/audit/client-event', [
            'category' => 'export',
            'description' => 'Excel eksportas: reports.xlsx',
            'context' => ['filename' => 'reports.xlsx', 'rows' => 42],
        ]);

        $response->assertStatus(200);

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('export', $decoded['context']['category']);
        $this->assertStringContainsString('reports.xlsx', $decoded['message']);
        $this->assertSame('reports.xlsx', $decoded['context']['context']['filename']);
        $this->assertSame(42, $decoded['context']['context']['rows']);
    }

    /** @test */
    public function every_client_event_is_marked_as_coming_from_the_browser()
    {
        // Auditą peržiūrintis asmuo turi matyti, kad tai naršyklės
        // pranešimas, o ne serverio užfiksuotas faktas.
        $this->postJson('/audit/client-event', [
            'category' => 'export',
            'description' => 'Eksportas',
        ]);

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('client', $decoded['context']['context']['source']);
    }

    /** @test */
    public function it_rejects_categories_outside_the_whitelist()
    {
        $response = $this->postJson('/audit/client-event', [
            'category' => 'login',
            'description' => 'Bandymas suklastoti prisijungimo irasa',
        ]);

        $response->assertStatus(422);
        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function it_rejects_empty_descriptions()
    {
        $response = $this->postJson('/audit/client-event', [
            'category' => 'export',
            'description' => '   ',
        ]);

        $response->assertStatus(422);
        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function it_truncates_overly_long_descriptions()
    {
        $this->postJson('/audit/client-event', [
            'category' => 'export',
            'description' => str_repeat('a', 500),
        ]);

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame(200, mb_strlen($decoded['message']));
    }

    /** @test */
    public function it_drops_non_scalar_context_values()
    {
        $this->postJson('/audit/client-event', [
            'category' => 'export',
            'description' => 'Eksportas',
            'context' => [
                'ok' => 'reiksme',
                'blogas' => ['giliai' => ['dar' => 'giliau']],
            ],
        ]);

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('reiksme', $decoded['context']['context']['ok']);
        $this->assertArrayNotHasKey('blogas', $decoded['context']['context']);
    }

    /** @test */
    public function it_limits_the_number_of_context_keys()
    {
        $context = [];
        for ($i = 0; $i < 50; $i++) {
            $context['raktas'.$i] = 'reiksme';
        }

        $this->postJson('/audit/client-event', [
            'category' => 'export',
            'description' => 'Eksportas',
            'context' => $context,
        ]);

        $decoded = $this->lastLogEntry('audit');

        // 10 leidžiamų raktų + "source" + "page", kuriuos prideda pats
        // kontroleris.
        $this->assertLessThanOrEqual(12, count($decoded['context']['context']));
    }
}
