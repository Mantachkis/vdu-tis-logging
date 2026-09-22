<?php

namespace Vdu\TisLogging\Tests;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Vdu\TisLogging\EventLogger;
use Vdu\TisLogging\Http\Middleware\LogFailedLogins;

class LogFailedLoginsTest extends TestCase
{
    /**
     * Sukuria užklausą su sesija ir, jei nurodyta, flash'intomis klaidomis -
     * lygiai taip, kaip Laravel daro nepavykus prisijungti.
     */
    protected function loginRequest(array $input, ?array $errors = null): Request
    {
        $request = Request::create('/login', 'POST', $input);
        $session = app('session.store');
        $request->setLaravelSession($session);

        if ($errors !== null) {
            $bag = new ViewErrorBag();
            $bag->put('default', new MessageBag($errors));
            $session->put('errors', $bag);
        }

        return $request;
    }

    protected function pass(Request $request, $response = null)
    {
        $response = $response ?: new RedirectResponse('/login');

        return (new LogFailedLogins())->handle($request, function () use ($response) {
            return $response;
        });
    }

    /** @test */
    public function a_failed_login_is_detected_by_the_standard_auth_failed_message()
    {
        // Butent taip Laravel grazina nepavykusi prisijungima - nesvarbu,
        // SSO brokeris ar vietine lentele.
        $this->pass($this->loginRequest(
            ['username' => 'jonas@vdu.lt', 'password' => 'blogas'],
            ['username' => [trans('auth.failed')]]
        ));

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('login_failed', $decoded['context']['category']);
        $this->assertSame('security', $decoded['context']['event_type']);
        $this->assertSame('jonas@vdu.lt', $decoded['context']['user_identifier']);
    }

    /** @test */
    public function the_password_is_never_recorded()
    {
        $this->pass($this->loginRequest(
            ['username' => 'jonas@vdu.lt', 'password' => 'SLAPTAS-123'],
            ['username' => [trans('auth.failed')]]
        ));

        $content = file_get_contents($this->findLogFile('audit'));

        $this->assertStringNotContainsString('SLAPTAS-123', $content);
    }

    /** @test */
    public function email_field_is_used_when_there_is_no_username()
    {
        $this->pass($this->loginRequest(
            ['email' => 'petras@vdu.lt', 'password' => 'x'],
            ['email' => [trans('auth.failed')]]
        ));

        $decoded = $this->lastLogEntry('audit');

        $this->assertSame('petras@vdu.lt', $decoded['context']['user_identifier']);
    }

    /** @test */
    public function other_validation_errors_are_not_mistaken_for_a_failed_login()
    {
        // Pvz. "laukas privalomas" - tai ne nepavykes prisijungimas.
        $this->pass($this->loginRequest(
            ['username' => ''],
            ['username' => ['Laukas privalomas.']]
        ));

        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function a_successful_login_is_not_logged_as_failed()
    {
        // Sekmingas prisijungimas - redirect be klaidu.
        $this->pass($this->loginRequest(['username' => 'jonas@vdu.lt']));

        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function get_requests_are_ignored()
    {
        $request = Request::create('/login', 'GET');
        $request->setLaravelSession(app('session.store'));

        $bag = new ViewErrorBag();
        $bag->put('default', new MessageBag(['username' => [trans('auth.failed')]]));
        app('session.store')->put('errors', $bag);

        $this->pass($request);

        // GET - tai tik formos atidarymas su senomis klaidomis, ne naujas bandymas.
        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function non_redirect_responses_are_ignored()
    {
        $this->pass(
            $this->loginRequest(['username' => 'x'], ['username' => [trans('auth.failed')]]),
            new Response('<html>ok</html>')
        );

        $this->assertNull($this->findLogFile('audit'));
    }

    /** @test */
    public function it_does_not_duplicate_an_already_recorded_failure()
    {
        // Jei tas pats bandymas jau uzfiksuotas Laravel Failed event'u ar
        // rankiniu kvietimu kontroleryje - antro iraso neturi buti.
        app(EventLogger::class)->security('login_failed', 'Jau uzfiksuota rankiniu kvietimu');

        $this->pass($this->loginRequest(
            ['username' => 'jonas@vdu.lt'],
            ['username' => [trans('auth.failed')]]
        ));

        $content = file_get_contents($this->findLogFile('audit'));

        $this->assertSame(1, substr_count($content, '"category":"login_failed"'));
    }

}
