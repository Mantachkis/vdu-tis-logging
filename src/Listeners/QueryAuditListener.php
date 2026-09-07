<?php

namespace Vdu\TisLogging\Listeners;

use Illuminate\Database\Events\QueryExecuted;
use Vdu\TisLogging\EventLogger;
use Vdu\TisLogging\Support\SqlStatementParser;

/**
 * Fiksuoja VISAS duomenis keičiančias SQL užklausas (INSERT/UPDATE/DELETE),
 * įskaitant tas, kurios APEINA Eloquent modelius:
 *
 *     DB::table('news')->where('id', 5)->update([...]);
 *     Model::where('id', 5)->update([...]);   // query builder, ne instancija
 *     DB::statement('UPDATE ...');
 *
 * Tokiais atvejais Eloquent NEMETA jokių modelio event'ų, tad
 * GlobalModelAuditListener jų nepamato - ši klasė užpildo tą spragą.
 *
 * Stulpeliai surišami su reikšmėmis (žr. SqlStatementParser), tad žurnale
 * matomas skaitomas "stulpelis => nauja reikšmė" žemėlapis, o ne žalias
 * SQL su atskiru poziciniu parametrų masyvu.
 *
 * APRIBOJIMAS: NĖRA old_values. SQL užklausa nežino, kokios reikšmės buvo
 * prieš pakeitimą - tai žino tik Eloquent modelis, įkeltas iš DB. Jei
 * reikia "iš ko į ką pakeitė", kontroleryje būtina naudoti modelio
 * instanciją ($model->save()), ne DB::table()->update().
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

        $bindings = $this->redactBindings($event->bindings);
        $parsed = app(SqlStatementParser::class)->parse($sql, $bindings);

        $table = $parsed['table'];
        $description = 'Duomenų bazės pakeitimas ('.strtoupper($statement).')'
            .($table ? ": {$table}" : '');

        $context = [
            'table' => $table,
            'statement' => $statement,
            'conditions' => $parsed['conditions'],
            'connection' => $event->connectionName,
            'time_ms' => $event->time,
        ];

        // Žalią SQL pridedame TIK jei nepavyko išanalizuoti stulpelių -
        // kitaip įrašas be reikalo išsipučia, o visa naudinga informacija
        // jau yra new_values/conditions laukuose.
        if ($parsed['values'] === null) {
            $context['sql'] = $sql;
            $context['bindings'] = $bindings;
        }

        app(EventLogger::class)->info(
            'db_'.$statement,
            $description,
            [
                'new_values' => $parsed['values'],
                'context' => $context,
            ]
        );
    }

    /**
     * Praleidžia lenteles, išvardintas config('audit.exclude_query_tables').
     * Naudinga aukšto dažnio techninėms lentelėms (sessions, cache, jobs),
     * kurios kurtų tik triukšmą.
     */
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

    /**
     * Bando užmaskuoti jautrias reikšmes parametrų sąraše.
     *
     * SVARBU: SQL lygmenyje parametrai yra tik pozicinis masyvas, be
     * stulpelių pavadinimų, tad tiksliai žinoti "šis parametras yra
     * slaptažodis" NEĮMANOMA. Taikome euristiką. Jei jūsų projekte per
     * DB::table() rašomi slaptažodžiai, geriau tokias lenteles įtraukti
     * į exclude_query_tables.
     */
    protected function redactBindings(array $bindings): array
    {
        $maxLength = (int) config('audit.max_binding_length', 500);

        return array_map(function ($value) use ($maxLength) {
            if (!is_string($value)) {
                return $value;
            }

            // Bcrypt/Argon hash'ai - akivaizdžiai slaptažodžiai.
            if (preg_match('/^\$(2[aby]|argon2)/', $value)) {
                return '[REDACTED]';
            }

            // Base64 įterpti paveikslėliai - dažni WYSIWYG redaktoriuose,
            // gali būti šimtų kilobaitų dydžio. Auditui pakanka fakto,
            // kad paveikslėlis buvo įterptas.
            if (stripos($value, 'data:image/') !== false) {
                $value = preg_replace(
                    '/data:image\/[a-z+]+;base64,[A-Za-z0-9+\/=]+/i',
                    '[BASE64_IMAGE]',
                    $value
                );
            }

            if (mb_strlen($value) > $maxLength) {
                return mb_substr($value, 0, $maxLength).'... [TRUNCATED]';
            }

            return $value;
        }, $bindings);
    }
}
