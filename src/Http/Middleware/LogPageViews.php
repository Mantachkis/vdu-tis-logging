<?php

namespace Vdu\TisLogging\Http\Middleware;

use Closure;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Vdu\TisLogging\EventLogger;

/**
 * Automatiškai fiksuoja puslapių peržiūras, be jokio kontrolerių
 * redagavimo (skirtingai nuo LogsViews trait).
 *
 * TRYS REŽIMAI (config('audit.log_page_views.mode')):
 *
 *   'off'       - išjungta (NUMATYTOJI). Peržiūros fiksuojamos tik
 *                 rankiniu LogsViews trait naudojimu.
 *
 *   'whitelist' - fiksuojami TIK maršrutai, išvardinti
 *                 config('audit.log_page_views.routes'). Rekomenduojama:
 *                 duoda automatizavimo patogumą, bet žurnale lieka tik
 *                 prasmingi, jautrūs įvykiai.
 *
 *   'all'       - fiksuojamas KIEKVIENAS puslapio atidarymas. Duoda
 *                 pilną padengimą, bet generuoja labai daug įrašų
 *                 (kiekvienas AJAX, navigacija, atgal/pirmyn) - dėl to
 *                 gali tapti sunku rasti tikrai svarbų įvykį, o BDAR
 *                 duomenų minimizavimo principas pasunkėja.
 *
 * VISAIS REŽIMAIS fiksuojami tik GET/HEAD sėkmingi (2xx) HTML atsakymai:
 * POST/PUT/DELETE jau padengti modelio ir SQL mechanizmų, atsisiuntimai -
 * LogFileDownloads middleware, o JSON/AJAX atsakymai paprastai yra
 * techniniai, ne "duomenų peržiūra".
 */
class LogPageViews
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        try {
            $this->maybeLogView($request, $response);
        } catch (\Throwable $e) {
            // Audito klaida NIEKADA neturi sugriauti atsakymo vartotojui.
        }

        return $response;
    }

    protected function maybeLogView($request, $response): void
    {
        $mode = config('audit.log_page_views.mode', 'off');

        if ($mode === 'off') {
            return;
        }

        $path = trim($request->path(), '/');

        if ($this->isExcluded($path)) {
            return;
        }

        if (!$this->isViewableResponse($request, $response, $path)) {
            return;
        }

        // "post_routes" ir "json_routes" veikia kaip baltieji sąrašai VISUOSE
        // režimuose - t.y. net 'whitelist' režimu jų nereikia dubliuoti
        // pagrindiniame "routes" sąraše.
        $explicitlyListed = $this->matchesAny($path, config('audit.log_page_views.post_routes', []))
            || $this->matchesAny($path, config('audit.log_page_views.json_routes', []));

        if ($mode === 'whitelist' && !$explicitlyListed && !$this->matchesWhitelist($path)) {
            return;
        }

        $route = $request->route();

        app(EventLogger::class)->info(
            'view',
            'Peržiūrėtas puslapis: /'.$path,
            [
                // Route parametrai (pvz. {id}) - tai dažniausiai ir yra
                // konkretaus peržiūrėto įrašo identifikatorius.
                'subject_id' => $this->resolveSubjectId($route),
                'context' => [
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'route_name' => $route ? $route->getName() : null,
                    'route_params' => $route ? $this->scalarParams($route) : [],
                ],
            ]
        );
    }

    protected function isViewableResponse($request, $response, string $path): bool
    {
        if (!$response instanceof Response) {
            return false;
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return false;
        }

        // Atsisiuntimus jau fiksuoja LogFileDownloads - nedubliuojame.
        if ($response->headers->get('Content-Disposition')) {
            return false;
        }

        if (!$this->isAllowedMethod($request, $path)) {
            return false;
        }

        return $this->isAllowedContentType($response, $path);
    }

    /**
     * GET/HEAD leidžiami visada. POST/PUT/PATCH elgsena priklauso nuo
     * config('audit.log_page_views.post_mode'):
     *
     *   'off'       - POST niekada nefiksuojamas kaip peržiūra.
     *   'whitelist' - tik "post_routes" sąraše išvardinti (NUMATYTOJI).
     *   'auto'      - fiksuojamas, JEI per užklausą nebuvo užfiksuota jokio
     *                 reikšmingo veiksmo (duomenų pakeitimo ar laiško
     *                 išsiuntimo). Tai automatiškai atskiria POST-peržiūras
     *                 (formos su filtrais) nuo POST-veiksmų (išsaugojimai),
     *                 be jokio rankinio sąrašo.
     *
     * KAM TO REIKIA: kai kurios sistemos naudoja POST ne duomenų keitimui,
     * o peržiūrai su filtrais (pvz. /user/personal_studies). Bet aklai
     * fiksuoti visus POST reikštų, kad kiekvienas išsaugojimas atsirastų
     * žurnale DU kartus - kaip "update" ir kaip "view".
     */
    protected function isAllowedMethod($request, string $path): bool
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return true;
        }

        // Eksplicitiškai išvardinti maršrutai fiksuojami visais režimais.
        if ($this->matchesAny($path, config('audit.log_page_views.post_routes', []))) {
            return true;
        }

        if (config('audit.log_page_views.post_mode', 'whitelist') !== 'auto') {
            return false;
        }

        // Middleware veikia PO kontrolerio, tad iki šio momento visi
        // duomenų pakeitimai jau užfiksuoti - galime patikimai spręsti.
        return !app(EventLogger::class)->hasRecordedActions();
    }

    /**
     * HTML leidžiamas visada. JSON - tik jei maršrutas eksplicitiškai
     * išvardintas "json_routes" sąraše.
     *
     * KAM TO REIKIA: dauguma JSON/AJAX atsakymų yra techniniai (statuso
     * tikrinimai, kalbos failai), bet kai kurie atiduoda asmens duomenis
     * (server-side DataTables, autocomplete su vartotojų sąrašais) - tokie
     * yra reali duomenų peržiūra.
     */
    protected function isAllowedContentType($response, string $path): bool
    {
        $contentType = (string) $response->headers->get('Content-Type');

        if ($contentType === '' || Str::contains($contentType, 'text/html')) {
            return true;
        }

        if (Str::contains($contentType, 'json')) {
            return $this->matchesAny($path, config('audit.log_page_views.json_routes', []));
        }

        return false;
    }

    protected function matchesWhitelist(string $path): bool
    {
        return $this->matchesAny($path, config('audit.log_page_views.routes', []));
    }

    protected function isExcluded(string $path): bool
    {
        return $this->matchesAny($path, config('audit.log_page_views.exclude', []));
    }

    /**
     * Palaiko "*" šablonus, pvz. "admin/userCompetenceView/*".
     */
    protected function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is(trim((string) $pattern, '/'), $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Iš route parametrų bando nustatyti peržiūrėto įrašo ID - dažniausiai
     * tai pirmas skaitinis parametras (pvz. /userCompetenceView/{id}).
     */
    protected function resolveSubjectId($route)
    {
        if (!$route) {
            return null;
        }

        foreach ($this->scalarParams($route) as $value) {
            if (is_numeric($value)) {
                return $value;
            }
        }

        return null;
    }

    protected function scalarParams($route): array
    {
        $params = [];

        foreach ($route->parameters() as $key => $value) {
            if (is_scalar($value)) {
                $params[$key] = $value;
            } elseif (is_object($value) && method_exists($value, 'getKey')) {
                // Route model binding - imame rakto reikšmę, o ne visą modelį.
                $params[$key] = $value->getKey();
            }
        }

        return $params;
    }
}
