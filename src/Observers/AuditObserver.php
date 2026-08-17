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
        $changes = $model->getChanges();

        // old_values turi rodyti TIK pasikeitusių laukų senas reikšmes -
        // duomenų minimizavimo principas (BDAR 5.1.c). Naudojame
        // getOriginal() su konkrečiu rakto sąrašu, o ne visą įrašą.
        $oldValuesForChangedKeys = array_intersect_key(
            $model->getOriginal(),
            $changes
        );

        $this->record(
            'update',
            $model,
            $this->filter($model, $oldValuesForChangedKeys),
            $this->filter($model, $changes)
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
