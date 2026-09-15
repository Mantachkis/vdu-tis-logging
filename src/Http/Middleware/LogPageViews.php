<?php

namespace Vdu\TisLogging\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Vdu\TisLogging\EventLogger;
use Vdu\TisLogging\Support\DataTablesResponseInspector;

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

        // DataTables atsakymai atpažįstami pagal STRUKTŪRĄ, ne pagal
        // maršrutą, tad jokio rankinio sąrašo nereikia. Tikriname pirma,
        // nes jie yra JSON - o JSON pagal nutylėjimą praleidžiamas.
        $dataTables = $this->detectDataTables($request, $response);

        if ($dataTables === null && !$this->isViewableResponse($request, $response, $path)) {
            return;
        }

        // "post_routes" ir "json_routes" veikia kaip baltieji sąrašai VISUOSE
        // režimuose - t.y. net 'whitelist' režimu jų nereikia dubliuoti
        // pagrindiniame "routes" sąraše. DataTables taip pat fiksuojami
        // visais režimais, nes tai realus asmens duomenų atidavimas.
        $explicitlyListed = $dataTables !== null
            || $this->matchesAny($path, config('audit.log_page_views.post_routes', []))
            || $this->matchesAny($path, config('audit.log_page_views.json_routes', []));

        if ($mode === 'whitelist' && !$explicitlyListed && !$this->matchesWhitelist($path)) {
            return;
        }

        if ($dataTables !== null && $this->isDuplicateDataTablesRequest($request)) {
            return;
        }

        $route = $request->route();
        $queryParams = $this->auditedQueryParams($request);

        $context = [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'route_name' => $route ? $route->getName() : null,
            'route_params' => $route ? $this->scalarParams($route) : [],
        ];

        if (!empty($queryParams)) {
            $context['query_params'] = $queryParams;
        }

        $description = 'Peržiūrėtas puslapis: /'.$path;

        if ($dataTables !== null) {
            $context = array_merge($context, $dataTables, ['source' => 'datatables']);
            $description = 'Peržiūrėti duomenys (DataTables): /'.$path;
        }

        app(EventLogger::class)->info(
            'view',
            $description,
            [
                // Route parametrai (pvz. {id}) - tai dažniausiai ir yra
                // konkretaus peržiūrėto įrašo identifikatorius. Jei jų nėra,
                // bandome query parametrus (AJAX dažnai naudoja ?id=123).
                'subject_id' => $this->resolveSubjectId($route) ?? $this->subjectIdFromQuery($queryParams),
                'context' => $context,
            ]
        );
    }

    /**
     * Surenka query parametrus, kuriuos verta fiksuoti.
     *
     * KAM TO REIKIA: AJAX užklausos dažnai perduoda peržiūrimo įrašo ID
     * kaip query parametrą, ne route parametrą:
     *
     *     $.ajax({ url: "/userApplicationAnswers/", data: { masterInfoId: 123 } })
     *
     * Be jų žurnale matytųsi tik "peržiūrėjo /userApplicationAnswers", bet
     * ne KIENO duomenis peržiūrėjo - o auditui būtent tai ir svarbu.
     *
     * Fiksuojami TIK config('audit.log_page_views.query_params') sąraše
     * išvardinti parametrai - kitaip į žurnalą patektų filtrai, puslapiavimas,
     * paieškos frazės ir kitas triukšmas, o kartais ir jautrūs duomenys.
     */
    protected function auditedQueryParams($request): array
    {
        $allowed = config('audit.log_page_views.query_params', []);

        if (empty($allowed)) {
            return [];
        }

        $collected = [];

        foreach ($request->query() as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            foreach ($allowed as $pattern) {
                if (Str::is($pattern, $key)) {
                    $collected[$key] = mb_substr((string) $value, 0, 200);
                    break;
                }
            }
        }

        return $collected;
    }

    /**
     * Iš surinktų query parametrų parenka tą, kuris labiausiai panašus į
     * peržiūrėto įrašo identifikatorių - pirmą skaitinį.
     */
    protected function subjectIdFromQuery(array $queryParams)
    {
        foreach ($queryParams as $value) {
            if (is_numeric($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array|null DataTables informacija arba null, jei tai ne
     *                    DataTables atsakymas (ar aptikimas išjungtas)
     */
    protected function detectDataTables($request, $response): ?array
    {
        if (!config('audit.log_page_views.detect_datatables', true)) {
            return null;
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return null;
        }

        $inspector = app(DataTablesResponseInspector::class);

        if (!$inspector->matches($response)) {
            return null;
        }

        return $inspector->describe($request, $response);
    }

    /**
     * DataTables generuoja atskirą užklausą kiekvienam lapo perėjimui,
     * rikiavimui ir net kiekvienam paieškos simboliui. Vienas
     * administratorius, ieškantis žmogaus, gali sugeneruoti dešimtis
     * beveik identiškų užklausų - todėl sujungiame tas, kurios per
     * trumpą laiką turi TĄ PATĮ URL ir tuos pačius parametrus.
     */
    protected function isDuplicateDataTablesRequest($request): bool
    {
        $window = (int) config('audit.log_page_views.datatables_dedup_seconds', 5);

        if ($window <= 0) {
            return false;
        }

        try {
            $identity = optional(Auth::user())->getAuthIdentifier() ?? $request->ip();

            // "draw" kinta kiekvienai užklausai, tad jo į raktą neimame -
            // kitaip dedubliavimas niekada nesuveiktų.
            $params = $request->except(['draw', '_']);

            $key = 'vdu-tis-logging:dt-dedup:'.md5($identity.'|'.$request->path().'|'.serialize($params));

            if (Cache::has($key)) {
                return true;
            }

            Cache::put($key, true, $window);

            return false;
        } catch (\Throwable $e) {
            // Cache nepasiekiamas - geriau fiksuoti (galimai dubliuotą)
            // įrašą, nei prarasti audito duomenis.
            return false;
        }
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
