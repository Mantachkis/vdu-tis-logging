<?php

namespace Vdu\TisLogging\Support;

use Symfony\Component\HttpFoundation\Response;

/**
 * Atpažįsta server-side DataTables atsakymus ir ištraukia auditui
 * naudingą informaciją.
 *
 * KAM TO REIKIA: kai lentelė pildoma per AJAX (yajra/laravel-datatables
 * serverSide režimu), puslapis įkeliamas tuščias, o realūs asmens duomenys
 * atiduodami atskira JSON užklausa. Fiksuojant tik puslapio atidarymą,
 * auditas parodytų "atidarė vartotojų sąrašą", bet ne tai, kad realiai buvo
 * atiduoti 500 asmenų duomenys, ar ko konkrečiai buvo ieškoma.
 *
 * DataTables atsakymas atpažįstamas pagal jo struktūrą - JSON su "draw",
 * "recordsTotal", "recordsFiltered" ir "data" laukais. Tai standartizuota
 * DataTables specifikacijos dalis, tad atpažinimas veikia nepriklausomai
 * nuo maršruto pavadinimo - jokio rankinio sąrašo nereikia.
 *
 * Auditui vertingiausia yra paieškos frazė: "administratorius ieškojo
 * 'Jonaitis'" pasako daugiau nei "atidarė vartotojų sąrašą".
 */
class DataTablesResponseInspector
{
    /**
     * Ar tai server-side DataTables atsakymas.
     */
    public function matches($response): bool
    {
        $payload = $this->decode($response);

        if ($payload === null) {
            return false;
        }

        // "draw" yra DataTables užklausos eilės numeris, be kurio atsakymas
        // nebūtų DataTables. "data" - pati duomenų aibė.
        return array_key_exists('draw', $payload)
            && array_key_exists('data', $payload)
            && (array_key_exists('recordsTotal', $payload) || array_key_exists('recordsFiltered', $payload));
    }

    /**
     * Ištraukia auditui naudingą informaciją - kiek įrašų atiduota, kiek
     * iš viso egzistuoja, ko buvo ieškoma, kuris lapas.
     */
    public function describe($request, $response): array
    {
        $payload = $this->decode($response) ?: [];

        $data = $payload['data'] ?? [];

        $context = [
            'records_returned' => is_array($data) ? count($data) : null,
            'records_total' => $payload['recordsTotal'] ?? null,
            'records_filtered' => $payload['recordsFiltered'] ?? null,
            'search' => $this->searchTerm($request),
            'page' => $this->pageNumber($request),
        ];

        return array_filter($context, function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /**
     * DataTables paieškos frazę siunčia kaip search[value].
     */
    protected function searchTerm($request): ?string
    {
        $search = $request->input('search');

        if (is_array($search) && isset($search['value'])) {
            $value = trim((string) $search['value']);

            return $value === '' ? null : mb_substr($value, 0, 200);
        }

        return null;
    }

    /**
     * DataTables lapą nurodo per "start" (praleistų įrašų kiekį) ir
     * "length" (įrašų per lapą) - iš jų apskaičiuojame lapo numerį.
     */
    protected function pageNumber($request): ?int
    {
        $start = $request->input('start');
        $length = $request->input('length');

        if (!is_numeric($start) || !is_numeric($length) || (int) $length <= 0) {
            return null;
        }

        return (int) floor((int) $start / (int) $length) + 1;
    }

    /**
     * @return array|null dekoduotas JSON arba null, jei tai ne JSON
     */
    protected function decode($response): ?array
    {
        if (!$response instanceof Response) {
            return null;
        }

        $contentType = (string) $response->headers->get('Content-Type');

        if (stripos($contentType, 'json') === false) {
            return null;
        }

        $content = $response->getContent();

        if (!is_string($content) || $content === '') {
            return null;
        }

        // Labai dideli atsakymai - nedekoduojame visų, kad neapkrautume
        // atminties. DataTables atsakymai su tūkstančiais įrašų gali būti
        // kelių megabaitų.
        $maxBytes = (int) config('audit.log_page_views.datatables_max_decode_bytes', 2097152);

        if (strlen($content) > $maxBytes) {
            return null;
        }

        $payload = json_decode($content, true);

        return is_array($payload) ? $payload : null;
    }
}
