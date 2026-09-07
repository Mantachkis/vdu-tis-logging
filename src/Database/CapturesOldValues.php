<?php

namespace Vdu\TisLogging\Database;

use Vdu\TisLogging\Support\OldValuesSnapshotStore;

/**
 * Perima UPDATE/DELETE užklausas PRIEŠ jų vykdymą, kad būtų galima
 * nuskaityti senas reikšmes.
 *
 * KAM TO REIKIA: Laravel 8+ turi DB::beforeExecuting() mechanizmą, bet
 * senesnės versijos (5.7-7.x) jo NETURI, o StatementPrepared event'as
 * rašymo užklausoms nesuveikia (jis dispatch'inamas tik prepared()
 * metode, kurį kviečia select()/cursor(), bet ne affectingStatement()).
 *
 * Vienintelis stabilus būdas senesnėse versijose - pakeisti pačią
 * jungties klasę per Connection::resolverFor() (egzistuoja nuo 5.4).
 *
 * affectingStatement() yra bendras taškas, per kurį eina IR update(),
 * IR delete() - tad užtenka perimti šį vieną metodą.
 */
trait CapturesOldValues
{
    public function affectingStatement($query, $bindings = [])
    {
        try {
            if (config('audit.log_queries', false)) {
                app(OldValuesSnapshotStore::class)->capture($query, $bindings, $this);
            }
        } catch (\Throwable $e) {
            // Audito klaida NIEKADA neturi sutrukdyti realiai užklausai.
        }

        return parent::affectingStatement($query, $bindings);
    }
}
