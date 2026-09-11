<?php

namespace Vdu\TisLogging\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Vdu\TisLogging\Exceptions\AuditingExceptionHandler;
use Vdu\TisLogging\Exceptions\AuditingExceptionHandlerLegacy;

class ExceptionHandlerDecorationTest extends TestCase
{
    protected function handler()
    {
        return app(ExceptionHandlerContract::class);
    }

    /** @test */
    public function the_projects_exception_handler_is_wrapped_automatically()
    {
        // Jokio Handler.php redagavimo - apgaubiama per konteineri.
        $handler = $this->handler();

        $this->assertTrue(
            $handler instanceof AuditingExceptionHandler
            || $handler instanceof AuditingExceptionHandlerLegacy,
            'Handler turi buti apgaubtas'
        );
    }

    /** @test */
    public function reported_exceptions_are_logged_to_the_error_channel()
    {
        $this->handler()->report(new \RuntimeException('Nepagauta klaida'));

        $decoded = $this->lastLogEntry('error');

        $this->assertSame('exception', $decoded['context']['category']);
        $this->assertSame('error', $decoded['context']['event_type']);
        $this->assertStringContainsString('Nepagauta klaida', $decoded['message']);
        $this->assertStringContainsString('RuntimeException', $decoded['message']);
    }

    /** @test */
    public function file_and_line_are_recorded_but_not_the_full_trace()
    {
        // Pilname trace yra funkciju argumentai - juose gali buti
        // slaptazodziu ar tokenu.
        $this->handler()->report(new \RuntimeException('Testas'));

        $decoded = $this->lastLogEntry('error');

        $this->assertArrayHasKey('file', $decoded['context']['context']);
        $this->assertArrayHasKey('line', $decoded['context']['context']);
        $this->assertArrayNotHasKey('trace', $decoded['context']['context']);
    }

    /** @test */
    public function the_dont_report_list_is_respected()
    {
        // Validacijos/404 klaidos yra normalus vartotojo elgesys, ne
        // sistemine klaida - Laravel jas numatytai nutildo.
        $this->handler()->report(
            new \Illuminate\Validation\ValidationException(
                validator([], ['laukas' => 'required'])
            )
        );

        $this->assertNull($this->findLogFile('error'));
    }

    /** @test */
    public function logging_can_be_disabled()
    {
        config(['audit.log_exceptions' => false]);

        $this->handler()->report(new \RuntimeException('Neturi buti fiksuota'));

        $this->assertNull($this->findLogFile('error'));
    }

    /** @test */
    public function the_original_handler_still_does_its_job()
    {
        // Apgaubimas neturi sugadinti klaidu apdorojimo - render()
        // turi grazinti atsakyma kaip anksciau.
        $response = $this->handler()->render(
            request(),
            new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Nerasta')
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /** @test */
    public function an_audit_failure_never_breaks_error_handling()
    {
        // Jei zurnalizavimas nepavyktu, klaidu apdorojimas turi testis -
        // kitaip vartotojas vietoj klaidos puslapio matytu balta ekrana.
        config(['audit.base_path' => '/kelias/kurio/nera/ir/negalima/sukurti']);

        $response = $this->handler()->render(
            request(),
            new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Nerasta')
        );

        $this->assertSame(404, $response->getStatusCode());
    }
}
