<?php

namespace Vdu\TisLogging\Tests;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vdu\TisLogging\Http\Middleware\LogFileDownloads;

class LogFileDownloadsTest extends TestCase
{
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
