<?php

namespace Vdu\TisLogging\Exceptions;

use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Throwable;

/**
 * Apgaubia projekto App\Exceptions\Handler ir automatiškai fiksuoja
 * nepagautas išimtis - be jokio Handler.php redagavimo.
 *
 * Ši versija skirta Laravel 8+ (sutartis naudoja Throwable). Laravel
 * 5.7-7.x naudojama AuditingExceptionHandlerLegacy.
 */
class AuditingExceptionHandler implements ExceptionHandlerContract
{
    use DelegatesToInnerHandler;

    public function report(Throwable $e)
    {
        $this->auditException($e);

        return $this->handler->report($e);
    }

    public function shouldReport(Throwable $e)
    {
        return $this->handler->shouldReport($e);
    }

    public function render($request, Throwable $e)
    {
        return $this->handler->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e)
    {
        return $this->handler->renderForConsole($output, $e);
    }
}
