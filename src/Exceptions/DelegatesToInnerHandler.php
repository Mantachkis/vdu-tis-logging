<?php

namespace Vdu\TisLogging\Exceptions;

use Vdu\TisLogging\EventLogger;

/**
 * Bendra logika, dalinama abiejų handler'io apvalkalų (žr.
 * AuditingExceptionHandler ir AuditingExceptionHandlerLegacy).
 *
 * KAM DU APVALKALAI: `Illuminate\Contracts\Debug\ExceptionHandler`
 * sutartis Laravel 5.7-7.x naudoja `Exception` tipą, o 8.x+ - `Throwable`.
 * PHP 7.1 neleidžia praplėsti parametro tipo implementuojant sąsają, tad
 * vienos klasės su abiem versijomis nepakanka.
 */
trait DelegatesToInnerHandler
{
    /** @var object originalus projekto App\Exceptions\Handler */
    protected $handler;

    public function __construct($handler)
    {
        $this->handler = $handler;
    }

    /**
     * Fiksuoja išimtį audito žurnale, tada perduoda originaliam
     * handler'iui.
     */
    protected function auditException($exception): void
    {
        try {
            if (!config('audit.log_exceptions', true)) {
                return;
            }

            // Gerbiame projekto $dontReport sąrašą - validacijos/404
            // klaidos yra normalus vartotojo elgesys, ne sisteminė klaida.
            if (method_exists($this->handler, 'shouldReport')
                && !$this->handler->shouldReport($exception)) {
                return;
            }

            // Sąmoningai NEĮTRAUKIAME pilno stack trace su argumentais -
            // juose gali būti slaptažodžių ar tokenų. Failas ir eilutė
            // paprastai užtenka diagnostikai, o pilną trace rasite
            // storage/logs/laravel.log.
            app(EventLogger::class)->error(
                'exception',
                get_class($exception).': '.$exception->getMessage(),
                [
                    'context' => [
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                    ],
                ]
            );
        } catch (\Throwable $e) {
            // Audito klaida NIEKADA neturi sutrukdyti klaidos apdorojimui -
            // kitaip vartotojas vietoj klaidos puslapio pamatytų baltą ekraną.
        }
    }

    /**
     * Visi kiti metodai perduodami originaliam handler'iui - tad projekto
     * savi metodai (custom render logika ir pan.) veikia kaip anksčiau.
     */
    public function __call($method, $arguments)
    {
        return $this->handler->{$method}(...$arguments);
    }
}
