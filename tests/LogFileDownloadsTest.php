<?php

namespace Vdu\TisLogging\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vdu\TisLogging\Http\Middleware\LogFileDownloads;

class LogFileDownloadsTest extends TestCase
{
    /** @test */
    public function it_logs_binary_file_response_even_without_content_disposition_header()
    {
        // Realus pilotinio diegimo metu pastebėtas atvejis - kai kurie
        // kontroleriai sukuria BinaryFileResponse tiesiogiai, praleisdami
        // disposition parametrą (pvz. new BinaryFileResponse($path) be
        // penkto konstruktoriaus argumento) - Content-Disposition antraštė
        // tokiu atveju NĖRA automatiškai nustatoma. Pats BinaryFileResponse
        // tipas jau reiškia "siunčiamas failas", tad turi būti fiksuojamas
        // besąlygiškai.
        $tmpFile = tempnam(sys_get_temp_dir(), 'vdu-test-');
        file_put_contents($tmpFile, 'testinis turinys');

        $middleware = new LogFileDownloads();
        $request = Request::create('/raw-download', 'GET');

        $response = new BinaryFileResponse($tmpFile);
        // SĄMONINGAI netikriname Content-Disposition - tikriname atvejį,
        // kai jos nėra.

        $middleware->handle($request, function () use ($response) {
            return $response;
        });

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('download', $decoded['context']['category']);
        $this->assertSame(basename($tmpFile), $decoded['context']['context']['filename']);

        unlink($tmpFile);
    }

    /** @test */
    public function it_logs_a_file_download_with_attachment_disposition()
    {
        $middleware = new LogFileDownloads();
        $request = Request::create('/export/users.xlsx', 'GET');

        $response = new StreamedResponse(function () {});
        $response->headers->set('Content-Disposition', 'attachment; filename="users.xlsx"');
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $middleware->handle($request, function () use ($response) {
            return $response;
        });

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('download', $decoded['context']['category']);
        $this->assertStringContainsString('users.xlsx', $decoded['message']);
    }

    /** @test */
    public function it_logs_plain_response_downloads_like_laravel_dompdf_returns()
    {
        // barryvdh/laravel-dompdf grąžina PAPRASTĄ Illuminate\Http\Response
        // (ne BinaryFileResponse/StreamedResponse) su rankomis nustatyta
        // Content-Disposition antrašte - būtent tokį atvejį šis testas
        // ir patikrina, nes tai buvo reali klaida ankstesnėje versijoje.
        $middleware = new LogFileDownloads();
        $request = Request::create('/invoices/5/pdf', 'GET');

        $response = new Response('%PDF-1.4 binary content here');
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="invoice-5.pdf"');

        $middleware->handle($request, function () use ($response) {
            return $response;
        });

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('download', $decoded['context']['category']);
        $this->assertStringContainsString('invoice-5.pdf', $decoded['message']);
    }

    /** @test */
    public function it_extracts_filename_and_url_into_context()
    {
        $middleware = new LogFileDownloads();
        $request = Request::create('/reports/generate.pdf', 'GET');

        $response = new StreamedResponse(function () {});
        $response->headers->set('Content-Disposition', 'attachment; filename="ataskaita-2026.pdf"');

        $middleware->handle($request, function () use ($response) {
            return $response;
        });

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('ataskaita-2026.pdf', $decoded['context']['context']['filename']);
        $this->assertStringContainsString('/reports/generate.pdf', $decoded['context']['context']['url']);
    }

    /** @test */
    public function it_does_not_log_regular_html_responses()
    {
        $middleware = new LogFileDownloads();
        $request = Request::create('/dashboard', 'GET');

        $middleware->handle($request, function () {
            return response('<html>puslapis</html>');
        });

        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function it_does_not_log_responses_without_content_disposition()
    {
        $middleware = new LogFileDownloads();
        $request = Request::create('/stream', 'GET');

        $response = new StreamedResponse(function () {});
        // Jokios Content-Disposition antraštės - ne failo atsisiuntimas.

        $middleware->handle($request, function () use ($response) {
            return $response;
        });

        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function it_sets_no_cache_headers_on_logged_downloads_by_default()
    {
        $middleware = new LogFileDownloads();
        $request = Request::create('/export/report.xlsx', 'GET');

        $response = new StreamedResponse(function () {});
        $response->headers->set('Content-Disposition', 'attachment; filename="report.xlsx"');

        $result = $middleware->handle($request, function () use ($response) {
            return $response;
        });

        $this->assertStringContainsString('no-store', $result->headers->get('Cache-Control'));
    }

    /** @test */
    public function it_does_not_set_no_cache_headers_when_disabled_via_config()
    {
        config(['audit.prevent_download_caching' => false]);

        $middleware = new LogFileDownloads();
        $request = Request::create('/export/public-report.xlsx', 'GET');

        $response = new StreamedResponse(function () {});
        $response->headers->set('Content-Disposition', 'attachment; filename="public-report.xlsx"');

        $result = $middleware->handle($request, function () use ($response) {
            return $response;
        });

        $this->assertNotSame('no-store, no-cache, must-revalidate, max-age=0', $result->headers->get('Cache-Control'));
    }

    /** @test */
    public function it_deduplicates_repeated_downloads_of_the_same_url_within_the_window()
    {
        $middleware = new LogFileDownloads();
        $request = Request::create('/user/download_portfolio/report.pdf', 'GET');

        $makeResponse = function () {
            $response = new StreamedResponse(function () {});
            $response->headers->set('Content-Disposition', 'attachment; filename="report.pdf"');
            return $response;
        };

        // Pirmas kvietimas - turi būti užfiksuotas.
        $middleware->handle($request, $makeResponse);
        // Antras kvietimas TAI PAČIAI URL iš karto po pirmo - turi būti
        // laikomas tuo pačiu veiksmu (peržiūra + atsisiuntimas) ir
        // NEBEFIKSUOJAMAS antrą kartą.
        $middleware->handle($request, $makeResponse);

        $file = $this->findLogFile('audit');
        $lines = array_values(array_filter(explode("\n", trim(file_get_contents($file)))));

        $this->assertCount(1, $lines, 'Turėjo būti tik VIENAS įrašas, ne du dubliuoti');
    }

    /** @test */
    public function it_logs_the_message_as_viewed_or_downloaded_since_the_two_cannot_be_reliably_distinguished()
    {
        $middleware = new LogFileDownloads();
        $request = Request::create('/user/download_portfolio/dokumentas.pdf', 'GET');

        $response = new StreamedResponse(function () {});
        $response->headers->set('Content-Disposition', 'attachment; filename="dokumentas.pdf"');

        $middleware->handle($request, function () use ($response) {
            return $response;
        });

        $decoded = $this->lastLogEntry('audit');

        $this->assertStringContainsString('peržiūrėtas/atsisiųstas', $decoded['message']);
    }

    /** @test */
    public function it_logs_again_after_the_dedup_window_expires()
    {
        config(['audit.download_dedup_seconds' => 0]);

        $middleware = new LogFileDownloads();
        $request = Request::create('/user/download_portfolio/report2.pdf', 'GET');

        $makeResponse = function () {
            $response = new StreamedResponse(function () {});
            $response->headers->set('Content-Disposition', 'attachment; filename="report2.pdf"');
            return $response;
        };

        $middleware->handle($request, $makeResponse);
        $middleware->handle($request, $makeResponse);

        $file = $this->findLogFile('audit');
        $lines = array_values(array_filter(explode("\n", trim(file_get_contents($file)))));

        $this->assertCount(2, $lines, 'Kai dedup=0, kiekvienas kvietimas turi būti fiksuojamas atskirai');
    }

    /** @test */
    public function different_users_downloading_the_same_url_are_not_deduplicated_together()
    {
        $middleware = new LogFileDownloads();
        $request1 = Request::create('/user/download_portfolio/shared.pdf', 'GET');
        $request1->server->set('REMOTE_ADDR', '10.0.0.1');

        $request2 = Request::create('/user/download_portfolio/shared.pdf', 'GET');
        $request2->server->set('REMOTE_ADDR', '10.0.0.2');

        $makeResponse = function () {
            $response = new StreamedResponse(function () {});
            $response->headers->set('Content-Disposition', 'attachment; filename="shared.pdf"');
            return $response;
        };

        $middleware->handle($request1, $makeResponse);
        $middleware->handle($request2, $makeResponse);

        $file = $this->findLogFile('audit');
        $lines = array_values(array_filter(explode("\n", trim(file_get_contents($file)))));

        $this->assertCount(2, $lines, 'Skirtingi IP (neprisijungę vartotojai) neturi būti sujungiami');
    }

    /** @test */
    public function a_logging_error_never_breaks_the_actual_response()
    {
        // Net jei EventLogger viduje kažkas nepavyktų, middleware turi
        // grąžinti originalų atsakymą vartotojui be klaidos.
        $middleware = new LogFileDownloads();
        $request = Request::create('/export/broken.csv', 'GET');

        $response = new StreamedResponse(function () {});
        $response->headers->set('Content-Disposition', 'attachment; filename="broken.csv"');

        $result = $middleware->handle($request, function () use ($response) {
            return $response;
        });

        $this->assertSame($response, $result);
    }
}
