<?php

namespace Vdu\TisLogging\Database;

use Yajra\Oci8\Oci8Connection;

/**
 * Oracle jungtis (yajra/laravel-oci8) su senų reikšmių perėmimu.
 *
 * SVARBU: ši klasė NIEKADA neturi būti kraunama, jei yajra/laravel-oci8
 * neįdiegtas - todėl AuditLogServiceProvider ją mini tik po
 * class_exists(Oci8Connection::class) patikros.
 *
 * Ši klasė NEKEIČIA yajra resolverio logikos. Vietoj to,
 * AuditLogServiceProvider apgaubia originalų resolverį: leidžia jam
 * atlikti visą darbą (prisijungimą, NLS seanso kintamųjų nustatymą,
 * schemos parinkimą), o tada per ConnectionStateCopier perkelia gautą
 * būseną į šį poklasį. PDO objektas lieka tas pats, tad Oracle seanso
 * nustatymai galioja toliau.
 */
class AuditingOci8Connection extends Oci8Connection
{
    use CapturesOldValues;
}
