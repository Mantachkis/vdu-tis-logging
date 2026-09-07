<?php

namespace Vdu\TisLogging\Support;

/**
 * Nuskaito įrašų būseną PRIEŠ UPDATE/DELETE užklausos įvykdymą, kad
 * žurnale būtų galima parodyti "iš ko į ką pakeitė" net tada, kai
 * duomenys keičiami apeinant Eloquent modelį (DB::table()->update()).
 *
 * VEIKIMO PRINCIPAS:
 * 1. Laravel "beforeExecuting" callback'as pagauna užklausą prieš vykdymą.
 * 2. Jei tai UPDATE/DELETE su atpažįstamomis WHERE sąlygomis, atliekame
 *    SELECT, kad nuskaitytume dabartinę įrašo būseną.
 * 3. Būsena išsaugoma atmintyje, susieta su užklausos "pirštų atspaudu".
 * 4. Kai QueryExecuted event'as praneša, kad užklausa įvykdyta,
 *    QueryAuditListener paima šią būseną ir suformuoja old_values.
 *
 * APRIBOJIMAI:
 * - Veikia tik jei WHERE sąlygos atpažįstamos ("stulpelis = ?" forma).
 *   Sudėtingoms sąlygoms (subqueries, IN, raw WHERE) senų reikšmių
 *   nuskaityti nepavyks.
 * - Kiekvienam UPDATE/DELETE atliekama PAPILDOMA SELECT užklausa - tai
 *   našumo kaina. Ribojame nuskaitomų eilučių kiekį (max_rows), kad
 *   masiniai atnaujinimai nesugriautų našumo.
 * - Laravel 5.7-7.x su NESTANDARTINIU draiveriu (pvz. Oracle per
 *   yajra/laravel-oci8) senos reikšmės nefiksuojamos: ten reikia keisti
 *   jungties klasę, o to paketo jungtį perrašyti būtų pavojinga.
 *   Laravel 8+ šis apribojimas negalioja - beforeExecuting veikia su
 *   bet kokiu draiveriu.
 */
class OldValuesSnapshotStore
{
    /** @var array<string, array> */
    protected $snapshots = [];

    /** Apsauga nuo rekursijos - mūsų pačių SELECT neturi vėl paleisti šios logikos. */
    protected $capturing = false;

    /**
     * Ar senų reikšmių perėmimas prieinamas šioje aplinkoje.
     *
     * Laravel 8+ : per DB::beforeExecuting (bet koks draiveris).
     * Laravel 5.7-7.x : per pakeistą jungties klasę (tik standartiniai
     * draiveriai - žr. AuditLogServiceProvider::registerLegacyConnectionResolvers).
     */
    public function isSupported(): bool
    {
        return method_exists(\Illuminate\Database\Connection::class, 'beforeExecuting');
    }

    /**
     * Kviečiama PRIEŠ užklausos vykdymą.
     */
    public function capture(string $sql, array $bindings, $connection): void
    {
        if ($this->capturing || !config('audit.capture_old_values', true)) {
            return;
        }

        $statement = strtolower(strtok(trim($sql), " \t\n"));

        if (!in_array($statement, ['update', 'delete'], true)) {
            return;
        }

        $parsed = app(SqlStatementParser::class)->parse($sql, $bindings);

        if (empty($parsed['table']) || empty($parsed['conditions'])) {
            return;
        }

        $this->capturing = true;

        try {
            $rows = $this->fetchCurrentRows($connection, $parsed['table'], $parsed['conditions']);

            if ($rows !== null) {
                $this->snapshots[$this->fingerprint($sql, $bindings)] = $rows;
            }
        } catch (\Throwable $e) {
            // Nepavyko nuskaityti (teisės, sudėtinga schema, nestandartinis
            // lentelės pavadinimas) - tyliai praleidžiame. Auditas vis tiek
            // užfiksuos pakeitimą, tik be old_values.
        } finally {
            $this->capturing = false;
        }
    }

    /**
     * Paima ir pašalina išsaugotą būseną (kviečiama po užklausos vykdymo).
     */
    public function pull(string $sql, array $bindings): ?array
    {
        $key = $this->fingerprint($sql, $bindings);

        if (!isset($this->snapshots[$key])) {
            return null;
        }

        $rows = $this->snapshots[$key];
        unset($this->snapshots[$key]);

        return $rows;
    }

    protected function fetchCurrentRows($connection, string $table, array $conditions): ?array
    {
        $maxRows = (int) config('audit.old_values_max_rows', 5);

        $wrapped = $connection->getQueryGrammar()->wrapTable($table);

        $whereParts = [];
        $whereBindings = [];

        foreach ($conditions as $column => $value) {
            $whereParts[] = $connection->getQueryGrammar()->wrap($column).' = ?';
            $whereBindings[] = $value;
        }

        $sql = 'select * from '.$wrapped.' where '.implode(' and ', $whereParts);

        $rows = $connection->select($sql, $whereBindings);

        if (empty($rows)) {
            return null;
        }

        // Masiniams atnaujinimams (daug eilučių) neriboto dydžio snapshot'as
        // sukurtų milžiniškus žurnalo įrašus - apribojame.
        $rows = array_slice($rows, 0, $maxRows);

        return array_map(function ($row) {
            return (array) $row;
        }, $rows);
    }

    protected function fingerprint(string $sql, array $bindings): string
    {
        return md5($sql.'|'.serialize($bindings));
    }
}
