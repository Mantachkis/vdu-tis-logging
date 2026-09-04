<?php

namespace Vdu\TisLogging\Observers;

use Vdu\TisLogging\Support\ModelAuditRecorder;

class AuditObserver
{
    public function created($model)
    {
        app(ModelAuditRecorder::class)->recordCreated($model);
    }

    public function updated($model)
    {
        app(ModelAuditRecorder::class)->recordUpdated($model);
    }

    public function deleted($model)
    {
        app(ModelAuditRecorder::class)->recordDeleted($model);
    }
}
