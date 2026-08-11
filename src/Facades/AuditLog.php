<?php

namespace Vdu\TisLogging\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void log(string $eventType, string $category, string $description, array $data = [])
 * @method static void info(string $category, string $description, array $data = [])
 * @method static void security(string $category, string $description, array $data = [])
 * @method static void system(string $category, string $description, array $data = [])
 * @method static void warning(string $category, string $description, array $data = [])
 * @method static void error(string $category, string $description, array $data = [])
 *
 * @see \Vdu\TisLogging\EventLogger
 */
class AuditLog extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'audit-log';
    }
}
