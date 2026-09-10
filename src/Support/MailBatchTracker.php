<?php

namespace Vdu\TisLogging\Support;

use Vdu\TisLogging\EventLogger;

/**
 * Seka, kiek el. laiškų išsiųsta per VIENĄ užklausą, ir formuoja suvestinę,
 * kai pasiekiama riba.
 *
 * KAM TO REIKIA: naujienlaiškiai dažnai siunčiami cikle:
 *
 *     foreach ($subscribers as $subscriber) {
 *         Mail::to($subscriber->email)->send(new Newsletter($content));
 *     }
 *
 * Kiekvienas siuntimas yra atskiras MessageSent event'as, tad 500 gavėjų
 * duotų 500 atskirų žurnalo įrašų. Auditui paprastai pakanka žinoti, kad
 * naujienlaiškis buvo išsiųstas konkrečiam gavėjų sąrašui - ne 500 beveik
 * identiškų įrašų.
 *
 * ELGSENA: pirmi N laiškų (config('audit.mail.max_individual_per_request'))
 * fiksuojami atskirai - taip išsaugoma detali informacija apie tipinius
 * atvejus. Viską, kas virš ribos, sukaupiame ir užklausos pabaigoje
 * įrašome VIENA suvestine su pilnu likusių gavėjų sąrašu.
 *
 * Suvestinė rašoma per register_shutdown_function(), o ne middleware
 * terminate() - taip veikia ir CLI kontekste (artisan komandos, queue
 * darbuotojai), kur jokio HTTP middleware nėra.
 */
class MailBatchTracker
{
    /** Kiek laiškų jau užfiksuota atskirais įrašais. */
    protected $individuallyLogged = 0;

    /**
     * Sukaupti "virš ribos" laiškai, grupuojami pagal temą.
     *
     * @var array<string, array{count: int, recipients: string[]}>
     */
    protected $overflow = [];

    /** Ar shutdown callback'as jau užregistruotas. */
    protected $flushRegistered = false;

    /**
     * Ar šis laiškas turi būti fiksuojamas atskiru įrašu.
     */
    public function shouldLogIndividually(): bool
    {
        $limit = (int) config('audit.mail.max_individual_per_request', 0);

        if ($limit <= 0) {
            return true;
        }

        return $this->individuallyLogged < $limit;
    }

    public function markLogged(): void
    {
        $this->individuallyLogged++;
    }

    /**
     * Prideda laišką prie suvestinės (naudojama pasiekus ribą).
     */
    public function addToSummary(?string $subject, array $recipients): void
    {
        $key = $subject ?? '(be temos)';

        if (!isset($this->overflow[$key])) {
            $this->overflow[$key] = ['count' => 0, 'recipients' => []];
        }

        $this->overflow[$key]['count']++;

        foreach ($recipients as $recipient) {
            $this->overflow[$key]['recipients'][] = $recipient;
        }

        $this->registerFlush();

        // TARPINIS ĮRAŠYMAS: nelaukiame proceso pabaigos, o įrašome
        // suvestinę kas N laiškų.
        //
        // KAM TO REIKIA: masinis siuntimas sinchroniškai gali nutrūkti
        // (PHP max_execution_time, web serverio Gateway Timeout, atminties
        // riba) - tada register_shutdown_function gali būti neįvykdyta, ir
        // VISI sukaupti duomenys dingtų. Auditui prarasti 80 gavėjų įrašą
        // yra blogiau, nei turėti kelias dalines suvestines.
        $flushEvery = (int) config('audit.mail.summary_flush_every', 50);

        if ($flushEvery > 0 && $this->overflow[$key]['count'] >= $flushEvery) {
            $this->flush();
        }
    }

    protected function registerFlush(): void
    {
        if ($this->flushRegistered) {
            return;
        }

        $this->flushRegistered = true;

        register_shutdown_function(function () {
            try {
                $this->flush();
            } catch (\Throwable $e) {
                // Užklausa jau baigta - klaida čia negali niekam padėti,
                // bet ir neturi nieko sugriauti.
            }
        });
    }

    /**
     * Įrašo suvestines. Vieša, kad testai galėtų kviesti nelaukdami
     * proceso pabaigos.
     */
    public function flush(): void
    {
        if (empty($this->overflow)) {
            return;
        }

        $maxRecipients = (int) config('audit.mail.max_recipients', 0);

        foreach ($this->overflow as $subject => $group) {
            $recipients = $group['recipients'];

            if ($maxRecipients > 0 && count($recipients) > $maxRecipients) {
                $remaining = count($recipients) - $maxRecipients;
                $recipients = array_slice($recipients, 0, $maxRecipients);
                $recipients[] = "... ir dar {$remaining}";
            }

            app(EventLogger::class)->info(
                'mail_sent',
                "Išsiųsta laiškų suvestinė ({$group['count']} laiškų): {$subject}",
                [
                    'context' => [
                        'subject' => $subject === '(be temos)' ? null : $subject,
                        'to' => $recipients,
                        'recipients_total' => $group['count'],
                        // Aiškiai pažymime, kad tai suvestinė, o ne
                        // vienas laiškas - kitaip skaitytojas galėtų
                        // klaidingai suprasti recipients_total reikšmę.
                        'summary' => true,
                        'individually_logged_before_summary' => $this->individuallyLogged,
                    ],
                ]
            );
        }

        $this->overflow = [];
    }
}
