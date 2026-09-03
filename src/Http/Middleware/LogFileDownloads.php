<?php

namespace Vdu\TisLogging\Http\Middleware;

use Closure;
use Symfony\Component\HttpFoundation\Response;
use Vdu\TisLogging\EventLogger;

/**
 * Automatiškai fiksuoja VISUS failų atsisiuntimus, nepriklausomai nuo to,
 * kokia biblioteka/mechanizmas juos sugeneravo - Excel::download(),
 * PDF::download(), Storage::download(), response()->download(), ir t.t.
 *
 * Veikimo principas: tikrina KIEKVIENĄ HTTP atsakymą, ar jame yra
 * "Content-Disposition" antraštė (standartinis būdas, kuriuo naršyklei
 * pasakoma "tai atsisiunčiamas failas"). Jei taip - žurnalizuoja,
 * nepriklausomai nuo to, kuris kontroleris/paketas sugeneravo atsakymą.
 *
 * SVARBU: netikriname konkretaus Response poklasio (BinaryFileResponse/
 * StreamedResponse), nes daugelis paketų (pvz. barryvdh/laravel-dompdf)
 * grąžina paprastą Illuminate\Http\Response su rankomis nustatyta
 * Content-Disposition antrašte, ne specializuotą poklasį. Tikriname
 * bazinę Symfony\Component\HttpFoundation\Response klasę (kurią turi
 * VISI Laravel atsakymai) ir pačią antraštę.
 *
 * Registruojamas AUTOMATIŠKAI per AuditLogServiceProvider - projekto
 * Kernel.php redaguoti NEREIKIA. Galima išjungti per
 * AUDIT_LOG_DOWNLOADS=false .env kintamąjį, jei nepageidaujama.
 */
class LogFileDownloads
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        try {
            $this->maybeLogDownload($request, $response);
        } catch (\Throwable $e) {
            // Žurnalizavimo klaida NIEKADA neturi sugriauti realaus
            // atsakymo vartotojui - tyliai praleidžiame.
        }

        return $response;
    }

    protected function maybeLogDownload($request, $response): void
    {
        if (!$response instanceof Response) {
            return;
        }

        $disposition = $response->headers->get('Content-Disposition');

        if (!$disposition) {
            return;
        }

        $filename = $this->extractFilename($disposition);

        app(EventLogger::class)->info(
            'download',
            'Failas atsisiųstas'.($filename ? ": {$filename}" : ''),
            [
                'context' => [
                    'url' => $request->fullUrl(),
                    'filename' => $filename,
                    'content_type' => $response->headers->get('Content-Type'),
                    'disposition' => stripos($disposition, 'inline') === 0 ? 'inline' : 'attachment',
                ],
            ]
        );

        // Užkertame kelią naršyklės talpyklai šiam atsakymui - be to,
        // PAKARTOTINIAI to paties failo atsisiuntimai galėtų "praslysti"
        // pro auditą (naršyklė aptarnautų iš talpyklos, serveris apie tai
        // niekada nesužinotų). Taip pat gera saugumo praktika jautriems
        // dokumentams bendro naudojimo kompiuteriuose. Išjungiama per
        // AUDIT_LOG_PREVENT_DOWNLOAD_CACHING=false, jei projektui reikia
        // leisti talpinti (pvz. dideliems, dažnai atsisiunčiamiems viešiems
        // failams, kur talpykla svarbi našumui).
        if (config('audit.prevent_download_caching', true)) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
        }
    }

    protected function extractFilename(?string $disposition): ?string
    {
        if (!$disposition) {
            return null;
        }

        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $matches)) {
            return rawurldecode(trim($matches[1], '"'));
        }

        return null;
    }
}
