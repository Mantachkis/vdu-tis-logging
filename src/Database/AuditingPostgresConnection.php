<?php

namespace Vdu\TisLogging\Database;

use Illuminate\Database\PostgresConnection as BasePostgresConnection;

/**
 * PostgreSQL jungtis su senų reikšmių perėmimu - naudojama TIK Laravel
 * 5.7-7.x, kur nėra DB::beforeExecuting(). Registruojama per
 * Connection::resolverFor() paketo ServiceProvider'yje.
 */
class AuditingPostgresConnection extends BasePostgresConnection
{
    use CapturesOldValues;
}
