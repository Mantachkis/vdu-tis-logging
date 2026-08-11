<?php

namespace Vdu\TisLogging\Traits;

use Vdu\TisLogging\Observers\AuditObserver;

/**
 * Naudojimas modelyje:
 *
 *     use Vdu\TisLogging\Traits\Auditable;
 *
 *     class Invoice extends Model
 *     {
 *         use Auditable;
 *
 *         // Neprivaloma: papildomi laukai, kurių šis konkretus modelis
 *         // neturi audituoti (be globalaus config('audit.exclude') sąrašo).
 *         public function auditExclude(): array
 *         {
 *             return ['internal_notes'];
 *         }
 *     }
 */
trait Auditable
{
    public static function bootAuditable()
    {
        static::observe(AuditObserver::class);
    }
}
