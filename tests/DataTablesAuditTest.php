<?php

namespace Vdu\TisLogging\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Vdu\TisLogging\Http\Middleware\LogPageViews;

class DataTablesAuditTest extends TestCase
{
    /**
     * Tipinis yajra/laravel-datatables atsakymas.
     */
    protected function dataTablesResponse(int $returned = 25, int $total = 1847, ?int $filtered = null): Response
    {
        $data = [];
        for ($i = 0; $i < $returned; $i++) {
            $data[] = ['id' => $i + 1, 'name' => 'Vartotojas '.($i + 1)];
        }

        $response = new Response(json_encode([
            'draw' => 3,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered ?? $total,
            'data' => $data,
        ]));

        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    protected function pass(Request $request, ?Response $response = null)
    {
        $middleware = new LogPageViews();
        $response = $response ?: $this->dataTablesResponse();

        return $middleware->handle($request, function () use ($response) {
            return $response;
        });
    }

    /** @test */
    public function datatables_requests_are_detected_automatically_without_any_route_list()
    {
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.json_routes' => [],
        ]);

        $this->pass(Request::create('/admin/users/data', 'GET', [
            'draw' => 3,
            'start' => 0,
            'length' => 25,
        ]));

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('view', $decoded['context']['category']);
        $this->assertSame('datatables', $decoded['context']['context']['source']);
        $this->assertStringContainsString('DataTables', $decoded['message']);
    }

    /** @test */
    public function it_records_how_many_rows_were_returned_and_how_many_exist()
    {
        config(['audit.log_page_views.mode' => 'all']);

        $this->pass(
            Request::create('/admin/users/data', 'GET', ['draw' => 1, 'start' => 0, 'length' => 25]),
            $this->dataTablesResponse(25, 1847)
        );

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame(25, $decoded['context']['context']['records_returned']);
        $this->assertSame(1847, $decoded['context']['context']['records_total']);
    }

    /** @test */
    public function it_records_the_search_term()
    {
        // Auditui vertingiausia dalis - ko konkrečiai buvo ieškoma.
        config(['audit.log_page_views.mode' => 'all']);

        $this->pass(
            Request::create('/admin/users/data', 'GET', [
                'draw' => 1,
                'start' => 0,
                'length' => 25,
                'search' => ['value' => 'Jonaitis', 'regex' => 'false'],
            ]),
            $this->dataTablesResponse(2, 1847, 2)
        );

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('Jonaitis', $decoded['context']['context']['search']);
        $this->assertSame(2, $decoded['context']['context']['records_filtered']);
    }

    /** @test */
    public function it_calculates_the_page_number_from_start_and_length()
    {
        config(['audit.log_page_views.mode' => 'all']);

        $this->pass(Request::create('/admin/users/data', 'GET', [
            'draw' => 5,
            'start' => 50,
            'length' => 25,
        ]));

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame(3, $decoded['context']['context']['page']);
    }

    /** @test */
    public function datatables_are_logged_even_in_whitelist_mode_without_being_listed()
    {
        config([
            'audit.log_page_views.mode' => 'whitelist',
            'audit.log_page_views.routes' => [],
            'audit.log_page_views.json_routes' => [],
        ]);

        $this->pass(Request::create('/admin/users/data', 'GET', ['draw' => 1]));

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('datatables', $decoded['context']['context']['source']);
    }

    /** @test */
    public function repeated_identical_requests_are_merged_within_the_dedup_window()
    {
        // DataTables siunčia užklausą kiekvienam paieškos simboliui -
        // be sujungimo vienas paieškos veiksmas duotų dešimtis įrašų.
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.datatables_dedup_seconds' => 10,
        ]);

        $params = ['start' => 0, 'length' => 25, 'search' => ['value' => 'Jon']];

        $this->pass(Request::create('/admin/users/data', 'GET', $params + ['draw' => 1]));
        $this->pass(Request::create('/admin/users/data', 'GET', $params + ['draw' => 2]));
        $this->pass(Request::create('/admin/users/data', 'GET', $params + ['draw' => 3]));

        $lines = array_values(array_filter(explode("\n", trim(
            file_get_contents($this->findLogFile('audit'))
        ))));

        // "draw" kinta, bet paieška ir lapas tie patys - vienas įrašas.
        $this->assertCount(1, $lines);
    }

    /** @test */
    public function a_different_search_is_logged_separately()
    {
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.datatables_dedup_seconds' => 10,
        ]);

        $this->pass(Request::create('/admin/users/data', 'GET', [
            'draw' => 1, 'start' => 0, 'length' => 25, 'search' => ['value' => 'Jonas'],
        ]));

        $this->pass(Request::create('/admin/users/data', 'GET', [
            'draw' => 2, 'start' => 0, 'length' => 25, 'search' => ['value' => 'Petras'],
        ]));

        $lines = array_values(array_filter(explode("\n", trim(
            file_get_contents($this->findLogFile('audit'))
        ))));

        $this->assertCount(2, $lines);
    }

    /** @test */
    public function detection_can_be_disabled()
    {
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.detect_datatables' => false,
        ]);

        $this->pass(Request::create('/admin/users/data', 'GET', ['draw' => 1]));

        // JSON be json_routes ir be DataTables aptikimo - nefiksuojama.
        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function ordinary_json_is_not_mistaken_for_datatables()
    {
        config(['audit.log_page_views.mode' => 'all']);

        $response = new Response(json_encode(['ok' => true, 'data' => [1, 2, 3]]));
        $response->headers->set('Content-Type', 'application/json');

        $this->pass(Request::create('/ai-chat/status', 'GET'), $response);

        // Nėra "draw"/"recordsTotal" - ne DataTables, tad praleidžiama.
        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function json_routes_still_work_for_non_datatables_ajax()
    {
        // Pačių išvardinti AJAX endpoint'ai fiksuojami net jei tai ne
        // DataTables - pvz. autocomplete su vartotojų sąrašais.
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.json_routes' => ['admin/user-autocomplete'],
        ]);

        $response = new Response(json_encode([['id' => 1, 'name' => 'Jonas']]));
        $response->headers->set('Content-Type', 'application/json');

        $this->pass(Request::create('/admin/user-autocomplete', 'GET', ['q' => 'jon']), $response);

        $decoded = $this->lastLogEntry('audit');

        $this->assertStringContainsString('admin/user-autocomplete', $decoded['message']);
        $this->assertArrayNotHasKey('source', $decoded['context']['context']);
    }
}
