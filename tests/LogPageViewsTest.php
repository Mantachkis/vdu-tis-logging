<?php

namespace Vdu\TisLogging\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vdu\TisLogging\Http\Middleware\LogPageViews;

class LogPageViewsTest extends TestCase
{
    protected function middleware(): LogPageViews
    {
        return new LogPageViews();
    }

    protected function htmlResponse(): Response
    {
        $response = new Response('<html>puslapis</html>');
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');

        return $response;
    }

    protected function pass(LogPageViews $middleware, Request $request, $response = null)
    {
        $response = $response ?: $this->htmlResponse();

        return $middleware->handle($request, function () use ($response) {
            return $response;
        });
    }

    /** @test */
    public function nothing_is_logged_when_mode_is_off()
    {
        config(['audit.log_page_views.mode' => 'off']);

        $this->pass($this->middleware(), Request::create('/admin/userInfoList', 'GET'));

        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function all_mode_logs_every_page_view()
    {
        config(['audit.log_page_views.mode' => 'all']);

        $this->pass($this->middleware(), Request::create('/bet/koks/puslapis', 'GET'));

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('view', $decoded['context']['category']);
        $this->assertStringContainsString('bet/koks/puslapis', $decoded['message']);
    }

    /** @test */
    public function whitelist_mode_logs_only_listed_routes()
    {
        config([
            'audit.log_page_views.mode' => 'whitelist',
            'audit.log_page_views.routes' => ['admin/userInfoList'],
        ]);

        $middleware = $this->middleware();

        // Nėra sąraše - nefiksuojama.
        $this->pass($middleware, Request::create('/admin/kitas', 'GET'));
        $this->assertNull($this->findLogFile('audit'));

        // Yra sąraše - fiksuojama.
        $this->pass($middleware, Request::create('/admin/userInfoList', 'GET'));
        $decoded = $this->lastLogEntry('audit');

        $this->assertStringContainsString('admin/userInfoList', $decoded['message']);
    }

    /** @test */
    public function whitelist_supports_wildcard_patterns()
    {
        config([
            'audit.log_page_views.mode' => 'whitelist',
            'audit.log_page_views.routes' => ['admin/userCompetenceView/*'],
        ]);

        $this->pass($this->middleware(), Request::create('/admin/userCompetenceView/4192', 'GET'));

        $decoded = $this->lastLogEntry('audit');

        $this->assertStringContainsString('4192', $decoded['message']);
    }

    /** @test */
    public function excluded_routes_are_never_logged_even_in_all_mode()
    {
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.exclude' => ['audit/*'],
        ]);

        $this->pass($this->middleware(), Request::create('/audit/client-event', 'GET'));

        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function post_requests_are_not_logged_as_views()
    {
        // Duomenų keitimą jau padengia modelio ir SQL mechanizmai.
        config(['audit.log_page_views.mode' => 'all']);

        $this->pass($this->middleware(), Request::create('/admin/update_news', 'POST'));

        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function downloads_are_not_logged_twice()
    {
        // Atsisiuntimus fiksuoja LogFileDownloads - čia nedubliuojame.
        config(['audit.log_page_views.mode' => 'all']);

        $response = new StreamedResponse(function () {});
        $response->headers->set('Content-Disposition', 'attachment; filename="failas.pdf"');

        $this->pass($this->middleware(), Request::create('/admin/download/failas.pdf', 'GET'), $response);

        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function json_responses_are_not_logged()
    {
        config(['audit.log_page_views.mode' => 'all']);

        $response = new Response('{"ok":true}');
        $response->headers->set('Content-Type', 'application/json');

        $this->pass($this->middleware(), Request::create('/admin/competence_list', 'GET'), $response);

        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function error_responses_are_not_logged_as_views()
    {
        config(['audit.log_page_views.mode' => 'all']);

        $response = $this->htmlResponse();
        $response->setStatusCode(404);

        $this->pass($this->middleware(), Request::create('/neegzistuoja', 'GET'), $response);

        $this->assertNull($this->findLogFile('audit'));
    }
}
