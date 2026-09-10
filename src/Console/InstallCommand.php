<?php

namespace Vdu\TisLogging\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'audit:install';

    protected $description = 'Įdiegia VDU TIS audito žurnalizavimo sistemą (config, .env, žurnalų katalogas)';

    /**
     * .env kintamieji, kuriuos komanda prideda, jei jų dar nėra faile.
     *
     * Struktūra: grupės pavadinimas => [kintamasis => [reikšmė, komentaras]].
     * Grupės naudojamos tik tvarkingam .env failo formatavimui.
     */
    protected function envGroups(): array
    {
        return [
            'Pagrindiniai nustatymai' => [
                // AUDIT_LOG_APP_NAME užpildomas dinamiškai iš config('app.name').
                'AUDIT_LOG_APP_NAME' => [null, 'Poaplankio pavadinimas zurnalu kataloge'],
                'AUDIT_LOG_BASE_PATH' => ['/home/logs', 'Saknininis zurnalu katalogas serveryje'],
                'AUDIT_LOG_AUDIT_FILENAME' => ['audit.log', null],
                'AUDIT_LOG_ERROR_FILENAME' => ['error.log', null],
                'AUDIT_LOG_RETENTION_DAYS' => ['90', 'Saugojimo terminas dienomis (0 = netrinti)'],
            ],

            'Modeliu ir duomenu baze' => [
                'AUDIT_LOG_ALL_MODELS' => ['true', 'Automatiskai audituoti VISUS Eloquent modelius'],
                'AUDIT_LOG_QUERIES' => ['true', 'Fiksuoti SQL uzklausas, apeinancias Eloquent (DB::table)'],
                'AUDIT_LOG_QUEUE_CONTEXT' => ['true', 'Perkelti vartotojo kontesta i queue darbus'],
                'AUDIT_LOG_CAPTURE_OLD_VALUES' => ['true', 'Nuskaityti senas reiksmes pries UPDATE/DELETE'],
                'AUDIT_LOG_OLD_VALUES_MAX_ROWS' => ['5', 'Riba masiniams atnaujinimams'],
                'AUDIT_LOG_MAX_BINDING_LENGTH' => ['500', 'Maks. reiksmes ilgis zurnale'],
            ],

            'Failai ir perziuros' => [
                'AUDIT_LOG_DOWNLOADS' => ['true', 'Fiksuoti failu atsisiuntimus'],
                'AUDIT_LOG_PREVENT_DOWNLOAD_CACHING' => ['true', 'Neleisti narsyklei talpinti atsisiuntimu'],
                'AUDIT_LOG_DOWNLOAD_DEDUP_SECONDS' => ['10', 'Sujungti pasikartojancius atsisiuntimus'],
                'AUDIT_LOG_PAGE_VIEWS' => ['off', 'Puslapiu perziuros: off | whitelist | all'],
                'AUDIT_LOG_POST_VIEWS' => ['whitelist', 'POST kaip perziura: off | whitelist | auto'],
                'AUDIT_LOG_DETECT_DATATABLES' => ['true', 'Automatiskai fiksuoti server-side DataTables uzklausas'],
            ],

            'El. pastas' => [
                'AUDIT_LOG_MAIL' => ['true', 'Fiksuoti issiustus el. laiskus'],
                'AUDIT_LOG_MAIL_MAX_RECIPIENTS' => ['0', 'Gaveju sarasas zurnale (0 = visi)'],
                'AUDIT_LOG_MAIL_MAX_INDIVIDUAL' => ['20', 'Kiek laisku fiksuoti atskirai, toliau - suvestine'],
                'AUDIT_LOG_MAIL_SUMMARY_FLUSH' => ['50', 'Kas kiek laisku irasyti tarpine suvestine'],
            ],

            'Klientines puses ivykiai' => [
                'AUDIT_LOG_CLIENT_EVENTS' => ['false', 'Endpointas narsykles pranesimams (SheetJS ir pan.)'],
            ],
        ];
    }

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

        $content = file_get_contents($envPath);
        $appended = [];
        $additions = '';

        foreach ($this->envGroups() as $groupName => $variables) {
            $groupLines = '';

            foreach ($variables as $key => [$default, $comment]) {
                if (preg_match('/^'.preg_quote($key, '/').'=/m', $content)) {
                    continue;
                }

                if ($key === 'AUDIT_LOG_APP_NAME' && $default === null) {
                    $default = \Illuminate\Support\Str::slug(config('app.name', 'app'));
                }

                if ($comment) {
                    $groupLines .= "\n# {$comment}";
                }

                $groupLines .= "\n{$key}={$default}";
                $appended[] = $key;
            }

            if ($groupLines !== '') {
                $additions .= "\n\n# --- VDU TIS Audit Log: {$groupName} ---".$groupLines;
            }
        }

        if (empty($appended)) {
            $this->line('   Visi kintamieji jau nustatyti, praleidžiu.');
            return;
        }

        file_put_contents($envPath, rtrim($content).$additions."\n");

        $this->line('   Pridėta naujų kintamųjų: '.count($appended));

        foreach ($appended as $key) {
            $this->line("     + {$key}");
        }

        $this->warn('   PATIKRINKITE AUDIT_LOG_APP_NAME ir AUDIT_LOG_BASE_PATH reikšmes - numatytosios gali netikti jūsų serveriui.');

        // .env pasikeitė, tad config cache (jei buvo) taptų nebeteisingas.
        $this->callSilent('config:clear');
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
        $this->info('Diegimas baigtas.');
        $this->line('');
        $this->line('VEIKIA AUTOMATIŠKAI (nieko daryti nereikia):');
        $this->line('  - prisijungimai/atsijungimai per Auth::attempt()');
        $this->line('  - visų Eloquent modelių create/update/delete');
        $this->line('  - failų atsisiuntimai (PDF, Excel, docx ir kt.)');
        $this->line('');
        $this->line('REIKIA ĮJUNGTI .env faile (pagal poreikį):');
        $this->line('  AUDIT_LOG_QUERIES=true         - DB::table() pakeitimai, apeinantys Eloquent');
        $this->line('  AUDIT_LOG_PAGE_VIEWS=whitelist - puslapių peržiūros (nurodykite maršrutus');
        $this->line('                                   config/audit.php log_page_views.routes)');
        $this->line('  AUDIT_LOG_CLIENT_EVENTS=true   - naršyklėje vykstantys veiksmai (SheetJS)');
        $this->line('');
        $this->line('REIKIA RANKINIO KODO:');
        $this->line('  1. app/Exceptions/Handler.php - sisteminių klaidų fiksavimui:');
        $this->line('     use Vdu\TisLogging\Traits\LogsExceptions;');
        $this->line('     public function report(Throwable $e) { $this->logException($e); parent::report($e); }');
        $this->line('');
        $this->line('  2. Jei projektas naudoja custom auth (SSO, rankinis guard->login()),');
        $this->line('     nepavykę prisijungimai NEBUS fiksuojami automatiškai - reikia');
        $this->line('     AuditLog::security(...) kvietimo login kontroleryje. Žr. README.');
    }
}
