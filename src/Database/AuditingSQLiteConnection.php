<?php

namespace Vdu\TisLogging\Database;

use Illuminate\Database\SQLiteConnection as BaseSQLiteConnection;

/**
 * SQLite jungtis su senų reikšmių perėmimu - naudojama TIK Laravel 5.7-7.x,
 * kur nėra DB::beforeExecuting(). Registruojama per
 * Connection::resolverFor() paketo ServiceProvider'yje.
 */
class AuditingSQLiteConnection extends BaseSQLiteConnection
{
    use CapturesOldValues;
}
