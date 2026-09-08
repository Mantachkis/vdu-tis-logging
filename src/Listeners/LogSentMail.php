<?php

namespace Vdu\TisLogging\Listeners;

use Vdu\TisLogging\EventLogger;
use Vdu\TisLogging\Support\MailBatchTracker;

/**
 * Fiksuoja išsiųstus el. laiškus.
 *
 * KAM TO REIKIA: laiško išsiuntimas (ypač naujienlaiškio šimtams gavėjų)
 * yra reikšmingas veiksmas su asmens duomenimis, bet jis nekeičia DB, tad
 * jokie modelio ar SQL mechanizmai jo nepamato. Registruojasi per Laravel
 * MessageSent event'ą, tad veikia AUTOMATIŠKAI, be kontrolerių redagavimo.
 *
 * VERSIJŲ SUDERINAMUMAS: Laravel 5.7-8.x naudoja SwiftMailer
 * (Swift_Message su getTo() masyvu "email => name"), o Laravel 9.x -
 * Symfony Mailer (Symfony\Component\Mime\Email su Address objektų masyvu).
 * Abu variantai palaikomi.
 *
 * BDAR pastaba: gavėjų el. paštai yra asmens duomenys. Pagal nutylėjimą
 * fiksuojami VISI adresai (žr. config('audit.mail.max_recipients'), jei
 * norite apriboti). Įsitikinkite, kad tai atitinka jūsų duomenų saugojimo
 * politiką - naujienlaiškių sistemose adresų sąrašas žurnale gali būti
 * reikšmingas asmens duomenų kiekis.
 */
class LogSentMail
{
    public function handle($event): void
    {
        if (!config('audit.mail.enabled', true)) {
            return;
        }

        $message = $event->message ?? null;

        if ($message === null) {
            return;
        }

        $to = $this->extractAddresses($message, 'getTo');
        $cc = $this->extractAddresses($message, 'getCc');
        $bcc = $this->extractAddresses($message, 'getBcc');

        $total = count($to) + count($cc) + count($bcc);
        $tracker = app(MailBatchTracker::class);

        // Naujienlaiškiai dažnai siunčiami cikle - tada kiekvienas gavėjas
        // yra atskiras MessageSent event'as. Pirmi N fiksuojami atskirai,
        // o viskas virš ribos sukaupiama ir užklausos pabaigoje įrašoma
        // VIENA suvestine (žr. MailBatchTracker).
        if (!$tracker->shouldLogIndividually()) {
            $tracker->addToSummary($this->subject($message), array_merge($to, $cc, $bcc));

            return;
        }

        app(EventLogger::class)->info(
            'mail_sent',
            'Išsiųstas el. laiškas'.($total ? " ({$total} gav.)" : '')
                .($this->subject($message) ? ': '.$this->subject($message) : ''),
            [
                'context' => array_filter([
                    'subject' => $this->subject($message),
                    'to' => $to,
                    'cc' => $cc ?: null,
                    'bcc' => $bcc ?: null,
                    'recipients_total' => $total,
                ], function ($value) {
                    return $value !== null && $value !== [];
                }),
            ]
        );

        $tracker->markLogged();
    }

    protected function subject($message): ?string
    {
        if (!method_exists($message, 'getSubject')) {
            return null;
        }

        $subject = $message->getSubject();

        return $subject === null || $subject === '' ? null : mb_substr((string) $subject, 0, 200);
    }

    /**
     * @return string[] el. pašto adresų masyvas
     */
    protected function extractAddresses($message, string $method): array
    {
        if (!method_exists($message, $method)) {
            return [];
        }

        $raw = $message->{$method}();

        if (empty($raw)) {
            return [];
        }

        $addresses = [];

        foreach ($raw as $key => $value) {
            if (is_object($value) && method_exists($value, 'getAddress')) {
                // Symfony Mailer (Laravel 9+): Address objektų masyvas.
                $addresses[] = $value->getAddress();
            } elseif (is_string($key)) {
                // SwiftMailer (Laravel 5.7-8.x): "email => name" masyvas.
                $addresses[] = $key;
            } elseif (is_string($value)) {
                $addresses[] = $value;
            }
        }

        $max = (int) config('audit.mail.max_recipients', 0);

        if ($max > 0 && count($addresses) > $max) {
            $remaining = count($addresses) - $max;
            $addresses = array_slice($addresses, 0, $max);
            $addresses[] = "... ir dar {$remaining}";
        }

        return $addresses;
    }
}
