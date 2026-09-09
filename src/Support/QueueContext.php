<?php

namespace Vdu\TisLogging\Support;

/**
 * Perkelia vartotojo kontekstą iš HTTP užklausos į queue darbuotoją.
 *
 * KAM TO REIKIA: queue darbai (naujienlaiškių siuntimas, eksportų
 * generavimas, ataskaitos) vykdomi ATSKIRAME procese, kuriame nėra nei
 * HTTP užklausos, nei sesijos. Todėl `Auth::user()` ten grąžina null, o
 * `Request::ip()` - darbuotojo, ne realaus vartotojo IP.
 *
 * Be šio mechanizmo žurnale atsirastų "kažkas išsiuntė 500 laiškų" be
 * autoriaus - o auditui būtent autorius ir yra svarbiausia.
 *
 * KAIP VEIKIA:
 * 1. Kai darbas patenka į eilę (HTTP užklausos metu, kur vartotojas dar
 *    žinomas), Queue::createPayloadUsing įrašo jo duomenis į darbo
 *    payload'ą.
 * 2. Kai darbuotojas darbą pradeda vykdyti, Queue::before nuskaito tuos
 *    duomenis ir įrašo į šį objektą.
 * 3. EventLogger, neradęs autentifikuoto vartotojo, pasinaudoja šiuo
 *    kontekstu.
 * 4. Darbui pasibaigus kontekstas išvalomas - kitaip tas pats darbuotojo
 *    procesas priskirtų tą patį vartotoją ir kitų vartotojų darbams.
 */
class QueueContext
{
    /** Payload rakto pavadinimas - su prefiksu, kad nesikirstų su projekto duomenimis. */
    const PAYLOAD_KEY = 'vdu_tis_logging';

    /** @var array|null */
    protected $context;

    /**
     * Surenka dabartinį kontekstą (kviečiama darbo įstatymo momentu).
     */
    public function capture(): array
    {
        // Naudojame tą pačią logiką kaip EventLogger, kad kontekstas
        // sutaptų su tuo, kas būtų užfiksuota sinchroniškai.
        $logger = app(\Vdu\TisLogging\EventLogger::class);
        $user = $logger->currentUser();

        $captured = array_filter([
            'user_id' => optional($user)->getAuthIdentifier(),
            'user_identifier' => $user ? ($user->email ?? $user->username ?? null) : null,
            'ip_address' => $this->safeRequestValue(function () {
                return request()->ip();
            }),
            'user_agent' => $this->safeRequestValue(function () {
                return request()->header('User-Agent');
            }),
        ], function ($value) {
            return $value !== null && $value !== '';
        });

        return $captured;
    }

    public function set(?array $context): void
    {
        $this->context = $context ?: null;
    }

    public function clear(): void
    {
        $this->context = null;
    }

    public function has(): bool
    {
        return !empty($this->context);
    }

    public function get(string $key)
    {
        return $this->context[$key] ?? null;
    }

    /**
     * Konsolės/queue kontekste request() gali neegzistuoti arba mesti
     * išimtį - tokiu atveju tiesiog nieko negrąžiname.
     */
    protected function safeRequestValue(callable $callback)
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
