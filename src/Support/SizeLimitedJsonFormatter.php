<?php

namespace Vdu\TisLogging\Support;

use Monolog\Formatter\JsonFormatter;

/**
 * JSON formatter'is, ribojantis vieno įrašo dydį.
 *
 * KAM TO REIKIA: syslog turi griežtas pranešimo dydžio ribas. PHP
 * `syslog()` funkcija, `rsyslog` ir tinklo perdavimas ilgesnius pranešimus
 * TYLIAI NUKERPA - o nukirptas JSON tampa nebeskaitomas, tad prarandamas
 * visas įrašas, ne tik jo galas.
 *
 * Mūsų įrašai gali būti dideli: `old_values`/`new_values` su ilgais
 * teksto laukais arba `db_*` įrašai su daug stulpelių.
 *
 * ELGSENA viršijus ribą (trys pakopos, kiekviena švelnesnė už įrašo
 * praradimą):
 *   1. `old_values` ir `new_values` pakeičiami žyma - lieka faktas, kad
 *      pakeitimas įvyko, kas ir kada jį atliko;
 *   2. `context` pakeičiamas žyma;
 *   3. `message` apkarpomas.
 *
 * Kiekvienu atveju pridedamas `_truncated` laukas, kad skaitytojas
 * matytų, jog įrašas nepilnas.
 */
class SizeLimitedJsonFormatter extends JsonFormatter
{
    /** @var int */
    protected $maxBytes;

    public function __construct(int $maxBytes = 7000)
    {
        parent::__construct();

        $this->maxBytes = $maxBytes;
    }

    public function format(array $record): string
    {
        $formatted = parent::format($record);

        if ($this->maxBytes <= 0 || strlen($formatted) <= $this->maxBytes) {
            return $formatted;
        }

        // 1 pakopa: didžiausi laukai - senos ir naujos reikšmės.
        if (isset($record['context']['old_values']) || isset($record['context']['new_values'])) {
            $record['context']['old_values'] = '[TRUNCATED]';
            $record['context']['new_values'] = '[TRUNCATED]';
            $record['context']['_truncated'] = 'values';

            $formatted = parent::format($record);

            if (strlen($formatted) <= $this->maxBytes) {
                return $formatted;
            }
        }

        // 2 pakopa: papildomas kontekstas.
        if (isset($record['context']['context'])) {
            $record['context']['context'] = '[TRUNCATED]';
            $record['context']['_truncated'] = 'context';

            $formatted = parent::format($record);

            if (strlen($formatted) <= $this->maxBytes) {
                return $formatted;
            }
        }

        // 3 pakopa: pati žinutė. Paliekame tiek, kiek telpa, kad įrašas
        // išliktų galiojantis JSON.
        $overflow = strlen($formatted) - $this->maxBytes;
        $message = (string) ($record['message'] ?? '');

        if ($overflow > 0 && mb_strlen($message) > $overflow) {
            $record['message'] = mb_substr($message, 0, mb_strlen($message) - $overflow - 20).'...';
            $record['context']['_truncated'] = 'message';

            $formatted = parent::format($record);
        }

        return $formatted;
    }
}
