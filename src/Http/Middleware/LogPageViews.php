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

        if (!$this->isViewableResponse($request, $response)) {
            return;
        }

        $path = trim($request->path(), '/');

        if ($this->isExcluded($path)) {
            return;
        }

        if ($mode === 'whitelist' && !$this->matchesWhitelist($path)) {
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
                    'route_name' => $route ? $route->getName() : null,
                    'route_params' => $route ? $this->scalarParams($route) : [],
                ],
            ]
        );
    }

    protected function isViewableResponse($request, $response): bool
    {
        if (!$response instanceof Response) {
            return false;
        }

        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return false;
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return false;
        }

        // Atsisiuntimus jau fiksuoja LogFileDownloads - nedubliuojame.
        if ($response->headers->get('Content-Disposition')) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type');

        // Tik HTML puslapiai. JSON/AJAX atsakymai paprastai techniniai,
        // o ne "vartotojas peržiūrėjo duomenis".
        return $contentType === '' || Str::contains($contentType, 'text/html');
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
