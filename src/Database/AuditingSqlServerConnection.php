<?php

namespace Vdu\TisLogging\Database;

use Illuminate\Database\SqlServerConnection as BaseSqlServerConnection;

/**
 * SQL Server jungtis su senų reikšmių perėmimu - naudojama TIK Laravel
 * 5.7-7.x, kur nėra DB::beforeExecuting(). Registruojama per
 * Connection::resolverFor() paketo ServiceProvider'yje.
 */
class AuditingSqlServerConnection extends BaseSqlServerConnection
{
    use CapturesOldValues;
}
