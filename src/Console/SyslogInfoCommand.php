<?php

namespace Vdu\TisLogging\Console;

use Illuminate\Console\Command;
use Vdu\TisLogging\EventLogger;

/**
 * Parodo duomenis, kurių sistemos administratoriui reikia PRIEŠ įjungiant
 * syslog: identifikatorius ir Linux vartotoją, kurio vardu veiks procesas.
 *
 * Be šių duomenų administratorius negali nukreipti įrašų į atskirus
 * failus - jie susimaišytų su sisteminiais žurnalais.
 */
class SyslogInfoCommand extends Command
{
    protected $signature = 'audit:syslog-info';

    protected $description = 'Parodo syslog identifikatorius ir vartotoja, kuriuos reikia perduoti administratoriui';

    public function handle()
    {
        $appName = (string) config('audit.app_name', 'app');

        $this->line('');
        $this->info('=== Syslog informacija administratoriui ===');
        $this->line('');

        $this->line('Projektas:        '.$appName);
        $this->line('Linux vartotojas: '.$this->resolveSystemUser());
        $this->line('Facility:         '.config('audit.syslog.facility', 'LOG_USER'));
        $this->line('');

        $this->info('Identifikatoriai (ident):');

        if (config('audit.syslog.separate_channels', true)) {
            $this->line('  '.EventLogger::syslogIdent('audit', $appName).'   - iprasti veiksmai');
            $this->line('  '.EventLogger::syslogIdent('error', $appName).'   - klaidos ir ispejimai');
        } else {
            $this->line('  '.EventLogger::syslogIdent('audit', $appName).'   - visi irasai');
        }

        $this->line('');
        $this->info('Dabartine bukle:');
        $this->line('  driver:     '.config('audit.driver', 'file'));
        $this->line('  max_bytes:  '.config('audit.syslog.max_bytes', 7000));
        $this->line('');

        $this->comment('Kaip ijungti:');
        $this->line('  1. Perduokite virsuje esancius duomenis administratoriui');
        $this->line('  2. Palaukite, kol jis sukonfiguruos rsyslog');
        $this->line('  3. .env faile: AUDIT_LOG_DRIVER=both');
        $this->line('  4. php artisan config:clear');
        $this->line('  5. Patikrinkite, ar irasai pasiekia centrini serveri');
        $this->line('  6. Patvirtine - pakeiskite i AUDIT_LOG_DRIVER=syslog');
        $this->line('');
        $this->comment('Rekomenduojama pirma "both" - taip nepradingsite irasu,');
        $this->comment('jei rsyslog konfiguracija dar nesuveiks.');
        $this->line('');

        return 0;
    }

    /**
     * Linux vartotojas, kurio vardu veiks PHP procesas. Administratoriui
     * to reikia, jei rsyslog taisyklės filtruoja pagal vartotoją.
     *
     * SVARBU: per CLI tai vartotojas, paleidęs artisan. Per web tai
     * PHP-FPM pool vartotojas - dažniausiai tas pats, bet verta
     * pasitikrinti.
     */
    protected function resolveSystemUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());

            if (!empty($info['name'])) {
                return $info['name'].'  (nustatyta per posix)';
            }
        }

        $fromEnv = getenv('USER') ?: getenv('USERNAME');

        if ($fromEnv) {
            return $fromEnv.'  (nustatyta per aplinkos kintamaji)';
        }

        return 'nepavyko nustatyti - patikrinkite komanda "whoami"';
    }
}
