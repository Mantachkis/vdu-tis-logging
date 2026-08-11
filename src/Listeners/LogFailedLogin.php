<?php

namespace Vdu\TisLogging\Listeners;

use Illuminate\Auth\Events\Failed;
use Vdu\TisLogging\EventLogger;

class LogFailedLogin
{
    /** @var EventLogger */
    protected $logger;

    public function __construct(EventLogger $logger)
    {
        $this->logger = $logger;
    }

    public function handle(Failed $event)
    {
        $credentials = $event->credentials;
        unset($credentials['password']);

        // Pirma likusi credentials reikšmė (dažniausiai username/email laukas) -
        // laukas gali vadintis skirtingai kiekviename projekte (username(), email ir t.t.),
        // tad imame pirmą, kad išsaugotume BENT jau bandymo identifikatorių.
        $identifier = reset($credentials);

        $this->logger->security(
            'login_failed',
            "Nepavykęs prisijungimo bandymas (guard: {$event->guard})",
            [
                'user_id' => $event->user ? $event->user->getAuthIdentifier() : null,
                'user_identifier' => is_string($identifier) ? $identifier : null,
                'context' => [
                    'guard' => $event->guard,
                    'credentials_fields' => array_keys($credentials),
                ],
            ]
        );
    }
}
