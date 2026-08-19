<?php

namespace Vdu\TisLogging\Tests\Fixtures;

use Vdu\TisLogging\Traits\LogsExceptions;

/**
 * Minimali fixture, imituojanti realų app/Exceptions/Handler.php -
 * tik su shouldReport() metodu, kad testai galėtų patikrinti abu
 * scenarijus (kai $dontReport filtruoja, ir kai ne).
 */
class TestExceptionHandler
{
    use LogsExceptions;

    protected $dontReportList = [];

    public function setDontReport(array $classes): void
    {
        $this->dontReportList = $classes;
    }

    public function shouldReport(\Throwable $e): bool
    {
        foreach ($this->dontReportList as $class) {
            if ($e instanceof $class) {
                return false;
            }
        }

        return true;
    }

    public function reportPublic(\Throwable $e): void
    {
        $this->logException($e);
    }
}
