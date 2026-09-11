<?php

namespace Vdu\TisLogging\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;

/**
 * Apgaubia projekto App\Exceptions\Handler ir automatiškai fiksuoja
 * nepagautas išimtis - be jokio Handler.php redagavimo.
 *
 * Ši versija skirta Laravel 5.7-7.x (sutartis naudoja Exception).
 * PHP 7.1 neleidžia praplėsti parametro tipo implementuojant sąsają,
 * tad vienos klasės abiem Laravel kartoms nepakanka.
 */
class AuditingExceptionHandlerLegacy implements ExceptionHandlerContract
{
    use DelegatesToInnerHandler;

    public function report(Exception $e)
    {
        $this->auditException($e);

        return $this->handler->report($e);
    }

    public function shouldReport(Exception $e)
    {
        return $this->handler->shouldReport($e);
    }

    public function render($request, Exception $e)
    {
        return $this->handler->render($request, $e);
    }

    public function renderForConsole($output, Exception $e)
    {
        return $this->handler->renderForConsole($output, $e);
    }
}
