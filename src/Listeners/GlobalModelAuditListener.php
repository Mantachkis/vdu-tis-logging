<?php

namespace Vdu\TisLogging\Listeners;

use Vdu\TisLogging\Support\ModelAuditRecorder;
use Vdu\TisLogging\Traits\Auditable;

/**
 * Automatiškai audituoja VISUS projekto Eloquent modelius, be jokio
 * "use Auditable;" pridėjimo kiekviename modelio faile.
 *
 * Registruojasi per Laravel wildcard Eloquent event'us
 * ("eloquent.created: *" ir t.t.), kurie suveikia KIEKVIENAM modeliui
 * projekte, nepriklausomai nuo to, ar jis naudoja Auditable trait'ą.
 *
 * Praleidžia (neaudituoja):
 * - modelius, kurie JAU naudoja Auditable trait'ą (jie turi savo
 *   AuditObserver, registruotą per bootAuditable() - praleidžiame čia,
 *   kad NEBŪTŲ dubliuoto fiksavimo);
 * - modelius, išvardintus config('audit.exclude_models') sąraše;
 * - VISKĄ, jei config('audit.audit_all_models') yra false.
 */
class GlobalModelAuditListener
{
    public function handleCreated(string $eventName, array $data): void
    {
        $model = $data[0];

        if ($this->shouldSkip($model)) {
            return;
        }

        app(ModelAuditRecorder::class)->recordCreated($model);
    }

    public function handleUpdated(string $eventName, array $data): void
    {
        $model = $data[0];

        if ($this->shouldSkip($model)) {
            return;
        }

        app(ModelAuditRecorder::class)->recordUpdated($model);
    }

    public function handleDeleted(string $eventName, array $data): void
    {
        $model = $data[0];

        if ($this->shouldSkip($model)) {
            return;
        }

        app(ModelAuditRecorder::class)->recordDeleted($model);
    }

    protected function shouldSkip($model): bool
    {
        if (!config('audit.audit_all_models', true)) {
            return true;
        }

        // Modeliai, kurie JAU naudoja Auditable trait'ą, turi savo
        // AuditObserver (registruotą per bootAuditable()) - praleidžiame
        // čia, kad įvykis nebūtų užfiksuotas DU kartus.
        if (in_array(Auditable::class, class_uses_recursive($model), true)) {
            return true;
        }

        $excluded = config('audit.exclude_models', []);

        return in_array(get_class($model), $excluded, true);
    }
}
