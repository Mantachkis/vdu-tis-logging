<?php

namespace Vdu\TisLogging\Support;

use Vdu\TisLogging\EventLogger;

/**
 * Bendra logika modelio create/update/delete įvykių žurnalizavimui.
 * Naudojama tiek AuditObserver (rankinis Auditable trait), tiek
 * GlobalModelAuditListener (automatinis visų modelių fiksavimas),
 * kad abu keliai elgtųsi TIKSLIAI vienodai (tas pats duomenų
 * minimizavimas, tas pats jautrių laukų filtravimas).
 */
class ModelAuditRecorder
{
    public function recordCreated($model): void
    {
        $this->record('create', $model, null, $this->filter($model, $model->getAttributes()));
    }

    public function recordUpdated($model): void
    {
        $changes = $model->getChanges();

        if (empty($changes)) {
            return;
        }

        // old_values turi rodyti TIK pasikeitusių laukų senas reikšmes -
        // duomenų minimizavimo principas (BDAR 5.1.c).
        $oldValuesForChangedKeys = array_intersect_key($model->getOriginal(), $changes);

        $this->record(
            'update',
            $model,
            $this->filter($model, $oldValuesForChangedKeys),
            $this->filter($model, $changes)
        );
    }

    public function recordDeleted($model): void
    {
        $this->record('delete', $model, $this->filter($model, $model->getOriginal()), null);
    }

    protected function record(string $action, $model, ?array $oldValues, ?array $newValues): void
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
