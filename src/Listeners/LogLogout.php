<?php

namespace Vdu\TisLogging\Listeners;

use Illuminate\Auth\Events\Logout;
use Vdu\TisLogging\EventLogger;

class LogLogout
{
    /** @var EventLogger */
    protected $logger;

    public function __construct(EventLogger $logger)
    {
        $this->logger = $logger;
    }

    public function handle(Logout $event)
    {
        // Laravel dispatch'ina Logout tik jei guard'e realiai buvo prisijungęs
        // vartotojas, tad $event->user čia paprastai nebūna null, bet
        // patikrinam apsauginiais tikslais.
        if (!$event->user) {
            return;
        }

        $identifier = $event->user->email
            ?? $event->user->username
            ?? null;

        $this->logger->security(
            'logout',
            "Vartotojas atsijungė (guard: {$event->guard})",
            [
                'user_id' => $event->user->getAuthIdentifier(),
                'user_identifier' => $identifier,
                'context' => ['guard' => $event->guard],
            ]
        );
    }
}
