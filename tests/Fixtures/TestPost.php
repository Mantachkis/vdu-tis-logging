<?php

namespace Vdu\TisLogging\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Vdu\TisLogging\Traits\Auditable;

class TestPost extends Model
{
    use Auditable;

    protected $table = 'test_posts';
    protected $guarded = [];
}
