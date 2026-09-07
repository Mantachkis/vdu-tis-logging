<?php

namespace Vdu\TisLogging\Listeners;

use Illuminate\Database\Events\QueryExecuted;
use Vdu\TisLogging\EventLogger;

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
 * APRIBOJIMAI (svarbu suprasti):
 * - NĖRA old_values. SQL užklausa nežino, kokios reikšmės buvo prieš
 *   pakeitimą - tai žino tik Eloquent modelis, įkeltas iš DB. Jei jums
 *   reikia "iš ko į ką pakeitė", kontroleryje reikia naudoti modelio
 *   instanciją ($model->save()), ne DB::table()->update().
 * - Fiksuojamas SQL sakinys ir parametrai, ne modelio kontekstas
 *   (nėra subject_type/subject_id).
 * - Jautrūs duomenys parametruose užmaskuojami tik apytiksliai
 *   (žr. redactBindings) - SQL lygmenyje neįmanoma patikimai susieti
 *   parametro su stulpelio pavadinimu.
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

        app(EventLogger::class)->info(
            'db_'.$statement,
            'Duomenų bazės pakeitimas ('.strtoupper($statement).')',
            [
                'context' => [
                    'sql' => $sql,
                    'bindings' => $this->redactBindings($event->bindings),
                    'connection' => $event->connectionName,
                    'time_ms' => $event->time,
                ],
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
            // Ieškome lentelės pavadinimo SQL sakinyje - paprasta, bet
            // pakankamai patikima praktikoje (lentelės vardas visada
            // pasirodo INSERT INTO x / UPDATE x / DELETE FROM x pradžioje).
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
     * slaptažodis" NEĮMANOMA. Taikome euristiką: jei SQL sakinyje
     * minimas jautrus stulpelis, užmaskuojame VISUS ilgus tekstinius
     * parametrus, kurie atrodo kaip hash'as. Tai apsauga, ne garantija -
     * jei jūsų projekte per DB::table() rašomi slaptažodžiai, geriau
     * tokias lenteles įtraukti į exclude_query_tables.
     */
    protected function redactBindings(array $bindings): array
    {
        return array_map(function ($value) {
            if (!is_string($value)) {
                return $value;
            }

            // Bcrypt/Argon hash'ai - akivaizdžiai slaptažodžiai.
            if (preg_match('/^\$(2[aby]|argon2)/', $value)) {
                return '[REDACTED]';
            }

            // Labai ilgi tekstai (pvz. HTML turinys) - trumpiname, kad
            // žurnalo įrašai neišaugtų iki megabaitų.
            if (mb_strlen($value) > 500) {
                return mb_substr($value, 0, 500).'... [TRUNCATED]';
            }

            return $value;
        }, $bindings);
    }
}
