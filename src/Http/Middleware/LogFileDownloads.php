<?php

namespace Vdu\TisLogging\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Vdu\TisLogging\EventLogger;

/**
 * Automatiškai fiksuoja VISUS failų atsisiuntimus, nepriklausomai nuo to,
 * kokia biblioteka/mechanizmas juos sugeneravo - Excel::download(),
 * PDF::download(), Storage::download(), response()->download(), ir t.t.
 *
 * Veikimo principas - DVI patikros, kad apimtume abu realius atvejus:
 *
 * 1) BinaryFileResponse (response()->download(), response()->file(),
 *    ar tiesiogiai sukurtas BinaryFileResponse) - fiksuojamas VISADA,
 *    net jei Content-Disposition antraštė nenustatyta (kai kurie
 *    kontroleriai sukuria BinaryFileResponse tiesiogiai, praleisdami
 *    disposition parametrą - tai pastebėta pilotinio diegimo metu).
 *    Pats šis tipas jau reiškia "siunčiamas failas".
 *
 * 2) Bet kuris kitas Response su "Content-Disposition" antrašte
 *    (pvz. barryvdh/laravel-dompdf grąžina paprastą Illuminate\Http\Response
 *    su rankomis nustatyta antrašte, ne specializuotą poklasį).
 *
 * SVARBU (peržiūra vs atsisiuntimas): naršyklės (pvz. Chrome PDF viewer)
 * gali atidaryti failą PIRMA peržiūrai naršyklėje, o tada, paspaudus
 * atsisiuntimo mygtuką viduje, siųsti ANTRĄ, atskirą užklausą TAI PAČIAI
 * URL, kad išsaugotų failą į diską. Serveris NEGALI patikimai atskirti
 * šių dviejų atvejų (abu atrodo identiškai). Tad žinutėje sąžiningai
 * rašoma "peržiūrėtas/atsisiųstas", o pasikartojantys to paties failo
 * kvietimai per trumpą laiko langą (žr. deduplication žemiau) sujungiami
 * į VIENĄ žurnalo įrašą.
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
        [$shouldLog, $filename, $disposition] = $this->inspectResponse($response);

        if ($shouldLog && !$this->isDuplicateWithinWindow($request, $filename)) {
            app(EventLogger::class)->info(
                'download',
                'Failas peržiūrėtas/atsisiųstas'.($filename ? ": {$filename}" : ''),
                [
                    'context' => [
                        'url' => $request->fullUrl(),
                        'filename' => $filename,
                        'content_type' => $response->headers->get('Content-Type'),
                        'disposition' => $disposition,
                    ],
                ]
            );
        }

        if (!$shouldLog) {
            return;
        }

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

    /**
     * Patikrina, ar tas pats vartotojas (ar IP, jei neprisijungęs) jau
     * kvietė TĄ PAČIĄ URL per pastarąsias N sekundžių - jei taip, laikoma
     * dubliuotu kvietimu (peržiūra + atsisiuntimas tam pačiam veiksmui)
     * ir NEBEFIKSUOJAMA antrą kartą. Išjungiama nustačius
     * AUDIT_LOG_DOWNLOAD_DEDUP_SECONDS=0.
     */
    protected function isDuplicateWithinWindow($request, ?string $filename): bool
    {
        $window = (int) config('audit.download_dedup_seconds', 10);

        if ($window <= 0) {
            return false;
        }

        try {
            $identity = optional(Auth::user())->getAuthIdentifier() ?? $request->ip();
            $key = 'vdu-tis-logging:download-dedup:'.md5($identity.'|'.$request->fullUrl());

            if (Cache::has($key)) {
                return true;
            }

            Cache::put($key, true, $window);

            return false;
        } catch (\Throwable $e) {
            // Jei cache driver'is nepasiekiamas/sugedęs, geriau fiksuoti
            // (galimai dubliuotą) įrašą, nei prarasti audito duomenis.
            return false;
        }
    }

    /**
     * @return array{0: bool, 1: ?string, 2: ?string} [ar_loginti, failo_pavadinimas, disposition_tipas]
     */
    protected function inspectResponse($response): array
    {
        if ($response instanceof BinaryFileResponse) {
            $disposition = $response->headers->get('Content-Disposition');
            $filename = $disposition
                ? $this->extractFilename($disposition)
                : basename($response->getFile()->getPathname());

            return [true, $filename, stripos((string) $disposition, 'inline') === 0 ? 'inline' : 'attachment'];
        }

        if ($response instanceof Response) {
            $disposition = $response->headers->get('Content-Disposition');

            if (!$disposition) {
                return [false, null, null];
            }

            return [
                true,
                $this->extractFilename($disposition),
                stripos($disposition, 'inline') === 0 ? 'inline' : 'attachment',
            ];
        }

        return [false, null, null];
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
