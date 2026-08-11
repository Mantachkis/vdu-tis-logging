<?php

namespace Vdu\TisLogging\Listeners;

use Illuminate\Auth\Events\Login;
use Vdu\TisLogging\EventLogger;

class LogSuccessfulLogin
{
    /** @var EventLogger */
    protected $logger;

    public function __construct(EventLogger $logger)
    {
        $this->logger = $logger;
    }

    public function handle(Login $event)
    {
        $identifier = $event->user->email
            ?? $event->user->username
            ?? null;

        $this->logger->security(
            'login',
            "Vartotojas sėkmingai prisijungė (guard: {$event->guard})",
            [
                'user_id' => $event->user->getAuthIdentifier(),
                'user_identifier' => $identifier,
                'context' => ['guard' => $event->guard],
            ]
        );
    }
}
