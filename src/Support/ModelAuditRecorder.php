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
        $values = $this->filter($model, $model->getAttributes());

        if (empty($values)) {
            return;
        }

        $this->record('create', $model, null, $values);
    }

    public function recordUpdated($model): void
    {
        $changes = $model->getChanges();

        if (empty($changes)) {
            return;
        }

        // Slaptažodžio keitimas fiksuojamas ATSKIRU saugumo įrašu - PRIEŠ
        // jautrių laukų filtravimą. Kitaip pakeitimas, liečiantis tik
        // slaptažodį, būtų visai praleistas (žr. žemiau), o tai svarbus
        // saugumo įvykis. Fiksuojamas tik FAKTAS, ne reikšmė.
        $this->recordPasswordChange($model, array_keys($changes));

        // Jei pasikeitė TIK jautrūs laukai (slaptažodis, remember_token),
        // po filtravimo neliks ko rodyti - fiksuotume "kažkas pasikeitė",
        // nenurodydami ką. Toks įrašas beprasmis, o dažnas (pvz. Laravel
        // atsijungiant išvalo remember_token) - tik triukšmas žurnale.
        if (empty($this->filter($model, $changes))) {
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

    /**
     * Jei tarp pasikeitusių laukų yra slaptažodis - fiksuoja saugumo įvykį.
     *
     * Veikia nepriklausomai nuo to, KAIP slaptažodis pakeistas: per
     * standartinį Laravel slaptažodžio atkūrimą, projekto savą formą ar
     * administratoriaus veiksmą - nes visais atvejais išsaugomas modelis.
     *
     * user_id - kas pakeitė (prisijungęs vartotojas arba null, jei per
     * atkūrimo nuorodą); subject_id - kieno slaptažodis pakeistas.
     */
    protected function recordPasswordChange($model, array $changedFields): void
    {
        $passwordFields = array_map('strtolower', (array) config('audit.password_fields', []));

        $matched = array_values(array_filter($changedFields, function ($field) use ($passwordFields) {
            return in_array(strtolower((string) $field), $passwordFields, true);
        }));

        if (empty($matched)) {
            return;
        }

        $key = $model->getKey();

        app(EventLogger::class)->security(
            'password_changed',
            class_basename($model)." (ID {$key}) - slaptažodis pakeistas",
            [
                'subject_type' => get_class($model),
                'subject_id' => $key,
                'context' => ['fields' => $matched],
            ]
        );
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
