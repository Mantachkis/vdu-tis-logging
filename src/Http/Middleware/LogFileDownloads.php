<?php

namespace Vdu\TisLogging\Http\Middleware;

use Closure;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vdu\TisLogging\EventLogger;

/**
 * Automatiškai fiksuoja VISUS failų atsisiuntimus, nepriklausomai nuo to,
 * kokia biblioteka/mechanizmas juos sugeneravo - Excel::download(),
 * PDF::download(), Storage::download(), response()->download(), ir t.t.
 *
 * Veikimo principas: tikrina KIEKVIENĄ HTTP atsakymą, ar tai
 * BinaryFileResponse/StreamedResponse su "Content-Disposition" antrašte
 * (standartinis būdas, kuriuo naršyklei pasakoma "tai atsisiunčiamas
 * failas"). Jei taip - žurnalizuoja, nepriklausomai nuo to, kuris
 * kontroleris/paketas sugeneravo atsakymą.
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
        $isFileResponse = $response instanceof BinaryFileResponse
            || $response instanceof StreamedResponse;

        if (!$isFileResponse) {
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
