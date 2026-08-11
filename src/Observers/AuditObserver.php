<?php

namespace Vdu\TisLogging\Observers;

use Vdu\TisLogging\EventLogger;

class AuditObserver
{
    public function created($model)
    {
        $this->record('create', $model, null, $this->filter($model, $model->getAttributes()));
    }

    public function updated($model)
    {
        $this->record(
            'update',
            $model,
            $this->filter($model, $model->getOriginal()),
            $this->filter($model, $model->getChanges())
        );
    }

    public function deleted($model)
    {
        $this->record('delete', $model, $this->filter($model, $model->getOriginal()), null);
    }

    protected function record(string $action, $model, ?array $oldValues, ?array $newValues)
    {
        $className = get_class($model);
        $key = $model->getKey();

        app(EventLogger::class)->info(
            $action,
            class_basename($model)." (ID {$key}) - {$action}",
            [
                'subject_type' => $className,
                'subject_id' => $key,
                'old_values' => $oldValues,
                'new_values' => $newValues,
            ]
        );
    }

    /**
     * Pašalina jautrius laukus (globalus config('audit.exclude') sąrašas +
     * kiekvieno modelio individualus auditExclude(), jei modelis jį apibrėžia).
     */
    protected function filter($model, ?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $excluded = config('audit.exclude', []);

        if (method_exists($model, 'auditExclude')) {
            $excluded = array_merge($excluded, $model->auditExclude());
        }

        return array_diff_key($values, array_flip($excluded));
    }
}
