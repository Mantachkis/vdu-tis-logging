<?php

namespace Vdu\TisLogging\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'audit:install';

    protected $description = 'Įdiegia VDU TIS audito žurnalizavimo sistemą (config, .env, žurnalų katalogas)';

    /**
     * .env kintamieji ir jų numatytosios (default) reikšmės, kurias komanda
     * pasiūlys pridėti, jei jų dar nėra faile.
     */
    protected $envDefaults = [
        'AUDIT_LOG_APP_NAME' => null, // užpildomas dinamiškai iš config('app.name')
        'AUDIT_LOG_BASE_PATH' => '/home/logs',
        'AUDIT_LOG_AUDIT_FILENAME' => 'audit.log',
        'AUDIT_LOG_ERROR_FILENAME' => 'error.log',
        'AUDIT_LOG_RETENTION_DAYS' => '90',
    ];

    public function handle()
    {
        $this->info('VDU TIS Audit Log - diegimas');
        $this->line('');

        $this->publishConfig();
        $this->line('');

        $this->ensureEnvVariables();
        $this->line('');

        $this->ensureLogDirectory();
        $this->line('');

        $this->printNextSteps();

        return 0;
    }

    protected function publishConfig(): void
    {
        $this->info('1. Publikuoju konfigūraciją (config/audit.php)...');
        $this->callSilent('vendor:publish', ['--tag' => 'audit-config']);
        $this->line('   Atlikta.');
    }

    protected function ensureEnvVariables(): void
    {
        $this->info('2. Tikrinu .env kintamuosius...');

        $envPath = base_path('.env');

        if (!file_exists($envPath)) {
            $this->warn("   .env failas nerastas ({$envPath}) - praleidžiu automatinį papildymą. Nustatykite kintamuosius rankomis.");
            return;
        }

        $this->envDefaults['AUDIT_LOG_APP_NAME'] = $this->envDefaults['AUDIT_LOG_APP_NAME']
            ?? \Illuminate\Support\Str::slug(config('app.name', 'app'));

        $content = file_get_contents($envPath);
        $appended = [];

        foreach ($this->envDefaults as $key => $default) {
            if (preg_match('/^'.preg_quote($key, '/').'=/m', $content)) {
                $this->line("   {$key} - jau nustatytas, praleidžiu.");
                continue;
            }

            $content .= "\n{$key}={$default}";
            $appended[] = $key;
        }

        if (!empty($appended)) {
            file_put_contents($envPath, rtrim($content)."\n");
            $this->line('   Pridėti nauji kintamieji: '.implode(', ', $appended));
            $this->warn('   PATIKRINKITE AUDIT_LOG_APP_NAME ir AUDIT_LOG_BASE_PATH reikšmes .env faile - numatytosios gali netikti jūsų serveriui.');

            // .env pasikeitė, tad config cache (jei buvo) taptų nebeteisingas.
            $this->callSilent('config:clear');
        }
    }

    protected function ensureLogDirectory(): void
    {
        $this->info('3. Tikrinu žurnalų katalogus (audit/, error/)...');

        // Skaitome tiesiai iš .env (per env() helper'į), nes config() galėjo
        // būti užkrautas PRIEŠ šio komandos vykdymo metu pridėtus .env pakeitimus.
        $basePath = env('AUDIT_LOG_BASE_PATH', config('audit.base_path'));
        $appName = env('AUDIT_LOG_APP_NAME', config('audit.app_name'));

        if (!$basePath || !$appName) {
            $this->error('   Nepavyko nustatyti AUDIT_LOG_BASE_PATH/AUDIT_LOG_APP_NAME reikšmių - patikrinkite .env rankomis.');
            return;
        }

        $baseDir = rtrim($basePath, '/').'/'.$appName;

        foreach (['audit', 'error'] as $subdir) {
            $dir = $baseDir.'/'.$subdir;

            if (is_dir($dir)) {
                $this->line("   Katalogas jau egzistuoja: {$dir}");
            } elseif (@mkdir($dir, 0775, true)) {
                $this->line("   Sukurtas katalogas: {$dir}");
            } else {
                $this->error("   Nepavyko sukurti katalogo: {$dir}");
                $this->warn('   Tikriausiai trūksta teisių. Administratorius turi paruošti katalogą rankomis - žr. README "Teisių paruošimas serveryje".');
                continue;
            }

            if (!is_writable($dir)) {
                $this->error("   ĮSPĖJIMAS: katalogas {$dir} NĖRA rašomas šiam PHP procesui.");
                $this->warn('   Patikrinkite Linux failų teises/grupes (žr. README "Teisių paruošimas serveryje").');
            }
        }
    }

    protected function printNextSteps(): void
    {
        $this->info('Diegimas baigtas. Kiti žingsniai:');
        $this->line('  1. Modeliuose, kuriuos norite audituoti (create/update/delete):');
        $this->line('     use Vdu\TisLogging\Traits\Auditable;');
        $this->line('');
        $this->line('  2. Kontroleriuose, kur reikia fiksuoti peržiūrą:');
        $this->line('     use Vdu\TisLogging\Traits\LogsViews;');
        $this->line('     $this->logView($model); // metodo viduje');
        $this->line('');
        $this->line('  3. Prisijungimas/atsijungimas per standartinį Auth::attempt() jau veikia automatiškai.');
        $this->line('     Jei projektas naudoja custom auth (SSO/rankinis guard->login()), reikės');
        $this->line('     rankinio AuditLog::security(...) kvietimo tose vietose - žr. README.');
    }
}
