<?php

namespace Vdu\TisLogging\Tests;

use Illuminate\Support\Facades\DB;
use Vdu\TisLogging\Database\AuditingSQLiteConnection;
use Vdu\TisLogging\Database\ConnectionStateCopier;

class ConnectionStateCopierTest extends TestCase
{
    /** @test */
    public function it_produces_a_working_connection_of_the_target_class()
    {
        $original = DB::connection();

        $copy = ConnectionStateCopier::copyInto($original, AuditingSQLiteConnection::class);

        $this->assertInstanceOf(AuditingSQLiteConnection::class, $copy);
        // Svarbiausia - nukopijuota jungtis turi realiai veikti.
        $this->assertSame(1, (int) $copy->select('select 1 as n')[0]->n);
    }

    /** @test */
    public function it_preserves_the_same_pdo_instance()
    {
        // Kritiškai svarbu Oracle atveju: yajra resolveris nustato NLS
        // seanso kintamuosius BŪTENT tam PDO objektui. Jei kopija gautų
        // naują PDO, tie nustatymai dingtų.
        $original = DB::connection();

        $copy = ConnectionStateCopier::copyInto($original, AuditingSQLiteConnection::class);

        $this->assertSame($original->getPdo(), $copy->getPdo());
    }

    /** @test */
    public function it_shares_the_pdo_even_when_the_source_connection_is_still_lazy()
    {
        // Laravel "pdo" savybė iš pradžių būna Closure (tinginys
        // prisijungimas). Jei nukopijuotume patį Closure, originalas ir
        // kopija jį iškviestų atskirai ir atsidarytų DVI skirtingos
        // jungtys - Oracle atveju antroji be NLS seanso kintamųjų.
        $original = DB::connection();

        // Grąžiname jungtį į "tingią" būseną, imituodami dar
        // neišspręstą Closure.
        $pdo = $original->getPdo();
        $original->setPdo(function () use ($pdo) {
            return $pdo;
        });

        $copy = ConnectionStateCopier::copyInto($original, AuditingSQLiteConnection::class);

        $this->assertSame($pdo, $copy->getPdo());
        $this->assertSame($original->getPdo(), $copy->getPdo());
    }

    /** @test */
    public function it_preserves_grammar_prefix_and_config()
    {
        $original = DB::connection();

        $copy = ConnectionStateCopier::copyInto($original, AuditingSQLiteConnection::class);

        $this->assertSame($original->getQueryGrammar(), $copy->getQueryGrammar());
        $this->assertSame($original->getPostProcessor(), $copy->getPostProcessor());
        $this->assertSame($original->getTablePrefix(), $copy->getTablePrefix());
        $this->assertSame($original->getConfig(), $copy->getConfig());
    }

    /** @test */
    public function the_copy_captures_old_values_on_updates()
    {
        // Patvirtiname, kad apgaubta jungtis realiai turi CapturesOldValues
        // elgseną - t.y. apgaubimas nesugadina audito funkcionalumo.
        $copy = ConnectionStateCopier::copyInto(DB::connection(), AuditingSQLiteConnection::class);

        $this->assertTrue(method_exists($copy, 'affectingStatement'));
        $this->assertContains(
            \Vdu\TisLogging\Database\CapturesOldValues::class,
            class_uses_recursive($copy)
        );
    }
}
