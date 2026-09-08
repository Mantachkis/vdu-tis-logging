<?php

namespace Vdu\TisLogging\Http\Controllers;

use Illuminate\Http\Request;
use Vdu\TisLogging\EventLogger;

/**
 * Priima pranešimus apie NARŠYKLĖJE įvykusius veiksmus, kurių serveris
 * kitaip nepamatytų.
 *
 * KAM TO REIKIA: kai kurie veiksmai vyksta vien kliento pusėje ir
 * nesukelia jokios HTTP užklausos, pvz.:
 *
 *     XLSX.writeFile(workbook, 'reports.xlsx');   // SheetJS naršyklėje
 *     window.print();
 *     navigator.clipboard.writeText(...);
 *
 * Tokiu atveju failas suformuojamas iš jau įkelto DOM turinio ir
 * išsaugomas tiesiai į vartotojo diską - serveris apie tai nieko
 * nesužino. Vienintelis būdas užfiksuoti - kad pati naršyklė
 * praneštų.
 *
 * SVARBU (patikimumas): klientinės pusės pranešimai NĖRA tokie patys
 * patikimi kaip serverio užfiksuoti įvykiai - technikai išmanantis
 * vartotojas gali jų neišsiųsti arba suklastoti. Todėl kiekvienas toks
 * įrašas žymimas "source": "client", kad auditą peržiūrintis asmuo
 * matytų skirtumą. Serverio pusėje užfiksuoti įvykiai lieka
 * autoritetingas šaltinis.
 */
class ClientEventController
{
    public function __invoke(Request $request, EventLogger $logger)
    {
        $allowed = config('audit.client_events.allowed_categories', []);
        $category = (string) $request->input('category');

        if (!in_array($category, $allowed, true)) {
            return response()->json([
                'message' => 'Neleistina įvykio kategorija.',
            ], 422);
        }

        $description = trim((string) $request->input('description'));

        if ($description === '') {
            return response()->json([
                'message' => 'Trūksta įvykio aprašymo.',
            ], 422);
        }

        $maxLength = (int) config('audit.client_events.max_description_length', 200);

        $logger->info(
            $category,
            mb_substr($description, 0, $maxLength),
            [
                'context' => array_merge(
                    $this->sanitizeContext($request->input('context')),
                    [
                        // Aiškiai pažymime, kad tai naršyklės pranešimas,
                        // o ne serverio užfiksuotas faktas.
                        'source' => 'client',
                        'page' => mb_substr((string) $request->headers->get('referer'), 0, 500),
                    ]
                ),
            ]
        );

        return response()->json(['logged' => true]);
    }

    /**
     * Priima tik paprastas skaliarines reikšmes ir riboja jų kiekį bei
     * ilgį - kitaip klientas galėtų prikimšti žurnalą bet kokiais
     * duomenimis (log injection / flooding).
     */
    protected function sanitizeContext($context): array
    {
        if (!is_array($context)) {
            return [];
        }

        $maxKeys = (int) config('audit.client_events.max_context_keys', 10);
        $maxLength = (int) config('audit.client_events.max_description_length', 200);

        $clean = [];

        foreach ($context as $key => $value) {
            if (count($clean) >= $maxKeys) {
                break;
            }

            if (!is_scalar($value) && $value !== null) {
                continue;
            }

            $key = mb_substr((string) $key, 0, 50);

            $clean[$key] = is_string($value)
                ? mb_substr($value, 0, $maxLength)
                : $value;
        }

        return $clean;
    }
}
