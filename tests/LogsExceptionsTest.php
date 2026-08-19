<?php

namespace Vdu\TisLogging\Tests;

use Vdu\TisLogging\Tests\Fixtures\TestExceptionHandler;

class LogsExceptionsTest extends TestCase
{
    /** @test */
    public function it_logs_exceptions_to_the_error_channel()
    {
        $handler = new TestExceptionHandler();
        $handler->reportPublic(new \RuntimeException('Kažkas nutiko'));

        $decoded = $this->lastLogEntry('error');

        $this->assertSame('error', $decoded['context']['event_type']);
        $this->assertSame('exception', $decoded['context']['category']);
        $this->assertStringContainsString('RuntimeException', $decoded['message']);
        $this->assertStringContainsString('Kažkas nutiko', $decoded['message']);
    }

    /** @test */
    public function it_includes_file_and_line_but_not_full_trace_with_arguments()
    {
        $handler = new TestExceptionHandler();
        $handler->reportPublic(new \RuntimeException('Testinė klaida'));

        $decoded = $this->lastLogEntry('error');

        $this->assertArrayHasKey('file', $decoded['context']['context']);
        $this->assertArrayHasKey('line', $decoded['context']['context']);
        $this->assertArrayNotHasKey('trace', $decoded['context']['context']);
    }

    /** @test */
    public function it_respects_shouldReport_dont_report_list()
    {
        $handler = new TestExceptionHandler();
        $handler->setDontReport([\InvalidArgumentException::class]);

        $handler->reportPublic(new \InvalidArgumentException('Ignoruotina klaida'));

        $this->assertNull($this->findLogFile('error'));
    }

    /** @test */
    public function it_still_logs_exceptions_not_in_dont_report_list()
    {
        $handler = new TestExceptionHandler();
        $handler->setDontReport([\InvalidArgumentException::class]);

        $handler->reportPublic(new \RuntimeException('Ši turi būti loginama'));

        $decoded = $this->lastLogEntry('error');

        $this->assertStringContainsString('Ši turi būti loginama', $decoded['message']);
    }
}
