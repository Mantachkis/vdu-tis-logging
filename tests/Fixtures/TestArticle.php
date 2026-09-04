<?php

namespace Vdu\TisLogging\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * SĄMONINGAI neturi "use Auditable;" - naudojamas testuoti, kad globalus
 * GlobalModelAuditListener audituoja modelius AUTOMATIŠKAI, be jokio
 * trait'o pridėjimo.
 */
class TestArticle extends Model
{
    protected $table = 'test_articles';
    protected $guarded = [];
}
