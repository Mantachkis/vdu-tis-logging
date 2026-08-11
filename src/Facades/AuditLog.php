<?php

namespace Vdu\AuditLog\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void log(string $eventType, string $category, string $description, array $data = [])
 *
 * Pilnas metodų sąrašas ir realizacija bus pridėti 2 etape (EventLogger branduolys).
 */
class AuditLog extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'audit-log';
    }
}
