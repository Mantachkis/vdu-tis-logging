<?php

namespace Vdu\TisLogging\Traits;

use Throwable;
use Vdu\TisLogging\EventLogger;

/**
 * Naudojimas projekto app/Exceptions/Handler.php faile:
 *
 *     use Vdu\TisLogging\Traits\LogsExceptions;
 *
 *     class Handler extends ExceptionHandler
 *     {
 *         use LogsExceptions;
 *
 *         public function report(Throwable $exception)
 *         {
 *             $this->logException($exception);
 *             parent::report($exception);
 *         }
 *     }
 *
 * Laravel neturi standartinio event'o nepagautoms išimtims (skirtingai nuo
 * Login/Logout/Failed), tad automatinis fiksavimas be šio rankinio žingsnio
 * neįmanomas - tai vienintelė vieta visame projekte, kur reikia vieno
 * papildomo kvietimo.
 */
trait LogsExceptions
{
    protected function logException(Throwable $exception): void
    {
        // Jei projektas jau turi $dontReport sąrašą (Laravel numatytoji
        // savybė validacijos/404 klaidoms nutildyti), gerbiame jį - tokios
        // klaidos NEBŪTINAI turi patekti į audito žurnalą kaip "error" tipo
        // įvykiai, nes tai normalus vartotojo elgesys, ne sisteminė klaida.
        if (method_exists($this, 'shouldReport') && !$this->shouldReport($exception)) {
            return;
        }

        // Sąmoningai NEĮTRAUKIAME pilno stack trace su argumentais - jie gali
        // turėti jautrių duomenų (slaptažodžius, tokenus), perduotus funkcijoms.
        // Failas+eilutė paprastai užtenka diagnostikai, o pilną trace galima
        // rasti standartiniame Laravel storage/logs/laravel.log, jei reikia.
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
    }
}
