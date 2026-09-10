<?php

namespace Vdu\TisLogging\Listeners;

use Illuminate\Database\Events\QueryExecuted;
use Vdu\TisLogging\Support\OldValuesSnapshotStore;
use Vdu\TisLogging\Support\PendingQueryLog;
use Vdu\TisLogging\Support\SqlStatementParser;

/**
 * Fiksuoja VISAS duomenis keičiančias SQL užklausas (INSERT/UPDATE/DELETE),
 * įskaitant tas, kurios APEINA Eloquent modelius:
 *
 *     DB::table('news')->where('id', 5)->update([...]);
 *     Model::where('id', 5)->update([...]);   // query builder, ne instancija
 *
 * Stulpeliai surišami su reikšmėmis (SqlStatementParser), o senos reikšmės
 * nuskaitomos prieš užklausos vykdymą (OldValuesSnapshotStore), tad žurnale
 * matoma "iš ko į ką pakeitė" net ir be Eloquent modelio.
 *
 * Rodomi TIK realiai pasikeitę laukai - jei UPDATE sakinys perrašo stulpelį
 * ta pačia reikšme (dažna praktika, kai forma siunčia visus laukus), toks
 * stulpelis į žurnalą nepatenka.
 */
class QueryAuditListener
{
    /**
     * SQL sakiniai, kuriuos fiksuojame. SELECT sąmoningai NEfiksuojame -
     * tai sukurtų milžinišką triukšmą (kiekvienas puslapio atidarymas
     * generuoja dešimtis SELECT užklausų) be jokios audito vertės.
     */
    protected const WRITE_STATEMENTS = ['insert', 'update', 'delete'];

    public function handle(QueryExecuted $event): void
    {
        if (!config('audit.log_queries', false)) {
            return;
        }

        $sql = trim($event->sql);
        $statement = strtolower(strtok($sql, " \t\n"));

        if (!in_array($statement, self::WRITE_STATEMENTS, true)) {
            return;
        }

        if ($this->isExcludedTable($sql)) {
            return;
        }

        $rawBindings = $event->bindings;
        $parsed = app(SqlStatementParser::class)->parse($sql, $this->redactBindings($rawBindings));


        // Senos reikšmės, nuskaitytos PRIEŠ šios užklausos vykdymą.
        $snapshot = app(OldValuesSnapshotStore::class)->pull($sql, $rawBindings);

        [$oldValues, $newValues] = $this->resolveChanges($statement, $parsed['values'], $snapshot);

        // Jei UPDATE nieko realiai nepakeitė (visi laukai perrašyti tomis
        // pačiomis reikšmėmis), nefiksuojame - tai ne pakeitimas.
        if ($statement === 'update' && $newValues !== null && empty($newValues)) {
            return;
        }

        $table = $parsed['table'];

        $context = [
            'table' => $table,
            'statement' => $statement,
            'conditions' => $parsed['conditions'],
            'connection' => $event->connectionName,
            'time_ms' => $event->time,
        ];

        // Žalią SQL pridedame TIK jei nepavyko išanalizuoti stulpelių -
        // kitaip įrašas be reikalo išsipučia.
        if ($parsed['values'] === null && $statement !== 'delete') {
            $context['sql'] = $sql;
            $context['bindings'] = $this->redactBindings($rawBindings);
        }

        // Įrašo NERAŠOME iš karto: Eloquent "updated" event'as suveikia PO
        // SQL užklausos, tad dabar dar nežinome, ar to paties pakeitimo
        // tuoj neužfiksuos modelio mechanizmas. Atidedame iki kitos
        // užklausos (arba užklausos pabaigos) - žr. PendingQueryLog.
        app(PendingQueryLog::class)->push($table, [
            'category' => 'db_'.$statement,
            'description' => 'Duomenų bazės pakeitimas ('.strtoupper($statement).')'.($table ? ": {$table}" : ''),
            'data' => [
                'occurred_at' => now()->toIso8601String(),
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'context' => $context,
            ],
        ]);
    }

    /**
     * Suformuoja old_values/new_values poras, paliekant TIK realiai
     * pasikeitusius laukus.
     *
     * @return array{0: ?array, 1: ?array}
     */
    protected function resolveChanges(string $statement, ?array $parsedValues, ?array $snapshot): array
    {
        if ($statement === 'insert') {
            return [null, $parsedValues];
        }

        if ($statement === 'delete') {
            // Ištrinant "sena reikšmė" yra visas įrašas, naujos nėra.
            return [$this->flattenSnapshot($snapshot), null];
        }

        // UPDATE
        if ($snapshot === null || $parsedValues === null) {
            // Senų reikšmių nuskaityti nepavyko - grąžiname bent naujas.
            return [null, $parsedValues];
        }

        $old = [];
        $new = [];

        foreach ($parsedValues as $column => $newValue) {
            $oldValue = $this->lookupInSnapshot($snapshot, $column);

            // Palyginame kaip tekstą - DB grąžina eilutes/skaičius
            // nenuosekliai (pvz. "5" vs 5), tad griežtas === duotų
            // klaidingų "pakeitimų".
            if ((string) $oldValue === (string) $newValue) {
                continue;
            }

            $old[$column] = $this->redactValue($oldValue);
            $new[$column] = $newValue;
        }

        return [$old ?: null, $new];
    }

    /**
     * Suranda stulpelio reikšmę snapshot'e, nepaisant raidžių registro
     * (Oracle grąžina DIDŽIOSIOMIS, MySQL - kaip apibrėžta schemoje).
     */
    protected function lookupInSnapshot(array $snapshot, string $column)
    {
        $row = $snapshot[0] ?? [];

        if (array_key_exists($column, $row)) {
            return $row[$column];
        }

        foreach ($row as $key => $value) {
            if (strcasecmp($key, $column) === 0) {
                return $value;
            }
        }

        return null;
    }

    protected function flattenSnapshot(?array $snapshot): ?array
    {
        if (empty($snapshot)) {
            return null;
        }

        $rows = array_map(function ($row) {
            return array_map([$this, 'redactValue'], $row);
        }, $snapshot);

        return count($rows) === 1 ? $rows[0] : $rows;
    }

    protected function isExcludedTable(string $sql): bool
    {
        $excluded = config('audit.exclude_query_tables', []);

        foreach ($excluded as $table) {
            if (stripos($sql, (string) $table) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function redactBindings(array $bindings): array
    {
        return array_map([$this, 'redactValue'], $bindings);
    }

    /**
     * Užmaskuoja jautrias reikšmes ir apriboja ilgį.
     */
    protected function redactValue($value)
    {
        if (!is_string($value)) {
            return $value;
        }

        // Bcrypt/Argon hash'ai - akivaizdžiai slaptažodžiai.
        if (preg_match('/^\$(2[aby]|argon2)/', $value)) {
            return '[REDACTED]';
        }

        // Base64 įterpti paveikslėliai - dažni WYSIWYG redaktoriuose,
        // gali būti šimtų kilobaitų dydžio.
        if (stripos($value, 'data:image/') !== false) {
            $value = preg_replace(
                '/data:image\/[a-z+]+;base64,[A-Za-z0-9+\/=]+/i',
                '[BASE64_IMAGE]',
                $value
            );
        }

        $maxLength = (int) config('audit.max_binding_length', 500);

        if (mb_strlen($value) > $maxLength) {
            return mb_substr($value, 0, $maxLength).'... [TRUNCATED]';
        }

        return $value;
    }
}
