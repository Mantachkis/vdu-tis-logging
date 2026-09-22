<?php

namespace Vdu\TisLogging\Http\Middleware;

use Closure;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;
use Vdu\TisLogging\EventLogger;

/**
 * Automatiškai fiksuoja nepavykusius prisijungimus - be LoginController
 * redagavimo.
 *
 * KAM TO REIKIA: Laravel meta `Failed` event'ą tik tada, kai naudojamas
 * `Auth::attempt()`. SSO brokeriai (zefy/laravel-sso, iffutsius/laravel-sso)
 * ir projektai su keliais guard'ais jo nenaudoja - tad nepavykę bandymai
 * likdavo neužfiksuoti, nebent programuotojas pridėdavo rankinį kvietimą.
 *
 * KAIP ATPAŽĮSTAMA: nepriklausomai nuo autentifikacijos būdo, Laravel
 * nepavykus prisijungti grąžina vartotoją atgal į formą su standartine
 * `auth.failed` klaidos žinute. Tai daro ir `AuthenticatesUsers` trait'as
 * (per ValidationException), ir dauguma custom kontrolerių (per
 * `back()->withErrors([... => __('auth.failed')])`).
 *
 * Middleware po kontrolerio patikrina, ar sesijoje yra būtent ši žinutė -
 * jei taip, tai buvo nepavykęs prisijungimas.
 *
 * KO NEPAGAUNA: projekto specifinių pranešimų (pvz. „Norint prisijungti,
 * turite patvirtinti savo el. paštą") ir slaptažodžio atkūrimo srautų -
 * juos atpažinti generiškai neįmanoma.
 */
class LogFailedLogins
{
    /**
     * Laukai, iš kurių bandoma nustatyti, kas bandė prisijungti.
     */
    protected const IDENTIFIER_FIELDS = ['username', 'email', 'login', 'name', 'user'];

    public function handle($request, Closure $next)
    {
        $response = $next($request);

        try {
            $this->maybeLogFailedLogin($request, $response);
        } catch (\Throwable $e) {
            // Audito klaida NIEKADA neturi sugriauti atsakymo.
        }

        return $response;
    }

    protected function maybeLogFailedLogin($request, $response): void
    {
        if (!$request->isMethod('POST')) {
            return;
        }

        if (!$response instanceof Response || !$response->isRedirection()) {
            return;
        }

        if (!$this->hasAuthFailedError($request)) {
            return;
        }

        // Jei tas pats bandymas jau užfiksuotas kitu keliu - Laravel Failed
        // event'u (Auth::attempt atveju) arba rankiniu kvietimu kontroleryje -
        // antrą kartą nerašome.
        $logger = app(EventLogger::class);

        if ($logger->hasRecorded('login_failed')) {
            return;
        }

        $identifier = $this->resolveIdentifier($request);

        $logger->security(
            'login_failed',
            'Nepavykęs prisijungimo bandymas'.($identifier ? " ({$identifier})" : ''),
            [
                'user_identifier' => $identifier,
                'context' => [
                    'url' => $request->fullUrl(),
                    'detected_by' => 'auth_failed_message',
                ],
            ]
        );
    }

    /**
     * Ar sesijoje yra standartinė `auth.failed` klaidos žinutė.
     *
     * Lyginama su `trans('auth.failed')` einamąja kalba - tad veikia
     * nepriklausomai nuo to, ar projektas naudoja lietuvišką, ar anglišką
     * vertimą, ar savo tekstą lang faile.
     */
    protected function hasAuthFailedError($request): bool
    {
        if (!method_exists($request, 'hasSession') || !$request->hasSession()) {
            return false;
        }

        $errors = $request->session()->get('errors');

        if (!$errors instanceof ViewErrorBag) {
            return false;
        }

        $expected = trans('auth.failed');

        return in_array($expected, $errors->all(), true);
    }

    protected function resolveIdentifier($request): ?string
    {
        foreach (self::IDENTIFIER_FIELDS as $field) {
            $value = $request->input($field);

            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 200);
            }
        }

        return null;
    }
}
