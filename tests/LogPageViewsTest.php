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

    /** @test */
    public function post_routes_whitelist_allows_post_views()
    {
        // /user/personal_studies yra POST, bet tik PARODO duomenis su
        // filtrais - realus perziuros veiksmas.
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.post_routes' => ['user/personal_studies'],
        ]);

        $this->pass($this->middleware(), Request::create('/user/personal_studies', 'POST'));

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('view', $decoded['context']['category']);
        $this->assertStringContainsString('user/personal_studies', $decoded['message']);
        $this->assertSame('POST', $decoded['context']['context']['method']);
    }

    /** @test */
    public function post_routes_are_logged_even_in_whitelist_mode_without_duplicating_in_routes()
    {
        config([
            'audit.log_page_views.mode' => 'whitelist',
            'audit.log_page_views.routes' => [],
            'audit.log_page_views.post_routes' => ['user/personal_studies'],
        ]);

        $this->pass($this->middleware(), Request::create('/user/personal_studies', 'POST'));

        $decoded = $this->lastLogEntry('audit');

        $this->assertStringContainsString('user/personal_studies', $decoded['message']);
    }

    /** @test */
    public function posts_outside_the_post_routes_list_are_still_ignored()
    {
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.post_routes' => ['user/personal_studies'],
        ]);

        $this->pass($this->middleware(), Request::create('/admin/update_news', 'POST'));

        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function json_routes_whitelist_allows_json_views()
    {
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.json_routes' => ['admin/userInfoList/data'],
        ]);

        $response = new Response('{"data":[]}');
        $response->headers->set('Content-Type', 'application/json');

        $this->pass($this->middleware(), Request::create('/admin/userInfoList/data', 'GET'), $response);

        $decoded = $this->lastLogEntry('audit');

        $this->assertStringContainsString('admin/userInfoList/data', $decoded['message']);
    }

    /** @test */
    public function json_outside_the_json_routes_list_is_still_ignored()
    {
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.json_routes' => ['admin/userInfoList/data'],
        ]);

        $response = new Response('{"ok":true}');
        $response->headers->set('Content-Type', 'application/json');

        $this->pass($this->middleware(), Request::create('/ai-chat/status', 'GET'), $response);

        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function excluded_routes_win_over_post_and_json_whitelists()
    {
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.exclude' => ['user/*'],
            'audit.log_page_views.post_routes' => ['user/personal_studies'],
        ]);

        $this->pass($this->middleware(), Request::create('/user/personal_studies', 'POST'));

        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function query_params_are_logged_when_listed()
    {
        // AJAX su ?masterInfoId=123 - be sio mechanizmo zurnale matytusi
        // tik "perziurejo puslapi", bet ne KIENO duomenis.
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.query_params' => ['masterInfoId'],
        ]);

        $this->pass($this->middleware(), Request::create('/userApplicationAnswers', 'GET', [
            'masterInfoId' => '123',
            'program' => 'ABC',
        ]));

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('123', $decoded['context']['context']['query_params']['masterInfoId']);
        // "program" nera sarase - neturi patekti.
        $this->assertArrayNotHasKey('program', $decoded['context']['context']['query_params']);
    }

    /** @test */
    public function a_numeric_query_param_becomes_the_subject_id()
    {
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.query_params' => ['masterInfoId'],
        ]);

        $this->pass($this->middleware(), Request::create('/userApplicationAnswers', 'GET', [
            'masterInfoId' => '456',
        ]));

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('456', $decoded['context']['subject_id']);
    }

    /** @test */
    public function query_params_support_wildcards()
    {
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.query_params' => ['*_id'],
        ]);

        $this->pass($this->middleware(), Request::create('/perziura', 'GET', [
            'user_id' => '77',
            'filtras' => 'aktyvus',
        ]));

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('77', $decoded['context']['context']['query_params']['user_id']);
        $this->assertArrayNotHasKey('filtras', $decoded['context']['context']['query_params']);
    }

    /** @test */
    public function nothing_is_collected_when_the_list_is_empty()
    {
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.query_params' => [],
        ]);

        $this->pass($this->middleware(), Request::create('/perziura', 'GET', ['id' => '9']));

        $decoded = $this->lastLogEntry('audit');

        $this->assertArrayNotHasKey('query_params', $decoded['context']['context']);
    }

    /** @test */
    public function default_patterns_catch_common_id_parameter_names()
    {
        // Numatytieji sablonai turi apimti dauguma Laravel projektu, kad
        // diegiant nereiketu vardinti kiekvieno parametro atskirai.
        config(['audit.log_page_views.mode' => 'all']);

        $this->pass($this->middleware(), Request::create('/perziura', 'GET', [
            'masterInfoId' => '123',
            'user_id' => '45',
            'id' => '7',
            'filtras' => 'aktyvus',
            'page' => '2',
        ]));

        $decoded = $this->lastLogEntry('audit');
        $collected = $decoded['context']['context']['query_params'];

        $this->assertSame('123', $collected['masterInfoId']);
        $this->assertSame('45', $collected['user_id']);
        $this->assertSame('7', $collected['id']);

        // Triuksmas nepatenka.
        $this->assertArrayNotHasKey('filtras', $collected);
        $this->assertArrayNotHasKey('page', $collected);
    }

    /** @test */
    public function ckods_is_collected_by_default()
    {
        // VDU sistemose ckods (darbuotojo kodas) daznai naudojamas kaip
        // identifikatorius. Tai NE asmens kodas - jis blokuojamas atskirai.
        config(['audit.log_page_views.mode' => 'all']);

        $this->pass($this->middleware(), Request::create('/perziura', 'GET', [
            'ckods' => '78935',
            'cilveks_ckods' => '12345',
        ]));

        $decoded = $this->lastLogEntry('audit');
        $collected = $decoded['context']['context']['query_params'];

        $this->assertSame('78935', $collected['ckods']);
        $this->assertSame('12345', $collected['cilveks_ckods']);
        $this->assertSame('78935', $decoded['context']['subject_id']);
    }

    /** @test */
    public function route_params_take_precedence_over_query_params_for_subject_id()
    {
        config([
            'audit.log_page_views.mode' => 'all',
            'audit.log_page_views.query_params' => ['id'],
        ]);

        // Route parametras tikslesnis nei query - jis turi pirmenybe.
        $request = Request::create('/irasas/999', 'GET', ['id' => '111']);
        $route = new \Illuminate\Routing\Route(['GET'], '/irasas/{irasas}', []);
        $route->bind($request);
        $request->setRouteResolver(function () use ($route) {
            return $route;
        });

        $this->pass($this->middleware(), $request);

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('999', $decoded['context']['subject_id']);
    }
}
