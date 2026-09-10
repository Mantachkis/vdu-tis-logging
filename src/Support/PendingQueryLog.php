<?php

namespace Vdu\TisLogging\Support;

use Vdu\TisLogging\EventLogger;

/**
 * Trumpam atideda SQL lygmens įrašą, kad būtų galima patikrinti, ar to
 * paties pakeitimo neužfiksavo Eloquent mechanizmas.
 *
 * KAM TO REIKIA: `$model->save()` sukelia du nepriklausomus įvykius:
 *
 *   1. QueryExecuted (SQL lygmuo) - suveikia PIRMA;
 *   2. Eloquent "updated" (modelio lygmuo) - suveikia PO to.
 *
 * Be atidėjimo SQL įrašas būtų parašytas dar nežinant, kad Eloquent tuoj
 * užfiksuos tą patį pakeitimą - gautume DU įrašus vienam veiksmui.
 *
 * VEIKIMO PRINCIPAS: buferyje laikomas ne daugiau kaip VIENAS įrašas.
 * Atėjus kitai užklausai, ankstesnis įrašas įvertinamas ir įrašomas arba
 * atmetamas - tuo momentu Eloquent jau tikrai spėjo suveikti. Paskutinis
 * įrašas išvalomas užklausos pabaigoje.
 *
 * Įrašo laikas (occurred_at) fiksuojamas atidėjimo momentu, ne rašymo -
 * tad žurnale išlieka teisinga chronologija.
 */
class PendingQueryLog
{
    /** @var array|null */
    protected $pending;

    /** Ar shutdown callback'as jau užregistruotas. */
    protected $flushRegistered = false;

    /**
     * Atideda įrašą, pirma įvertindamas ankstesnį.
     *
     * @param  string|null  $table  lentelė, kurios pakeitimą fiksuojame
     * @param  array  $entry  ['category' => ..., 'description' => ..., 'data' => [...]]
     */
    public function push(?string $table, array $entry): void
    {
        $this->flush();

        $this->pending = ['table' => $table, 'entry' => $entry];

        $this->registerShutdownFlush();
    }

    /**
     * Įrašo atidėtą įrašą, jei jo nedubliuoja Eloquent mechanizmas.
     */
    public function flush(): void
    {
        if ($this->pending === null) {
            return;
        }

        $pending = $this->pending;
        $this->pending = null;

        $logger = app(EventLogger::class);

        if (config('audit.skip_queries_recorded_by_eloquent', true)
            && $logger->wasTableRecordedByEloquent($pending['table'])) {
            return;
        }

        $entry = $pending['entry'];

        $logger->info($entry['category'], $entry['description'], $entry['data']);
    }

    protected function registerShutdownFlush(): void
    {
        if ($this->flushRegistered) {
            return;
        }

        $this->flushRegistered = true;

        register_shutdown_function(function () {
            try {
                $this->flush();
            } catch (\Throwable $e) {
                // Užklausa jau baigta - klaida čia negali niekam padėti.
            }
        });
    }
}
