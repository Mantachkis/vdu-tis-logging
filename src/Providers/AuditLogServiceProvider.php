<?php

namespace Vdu\TisLogging\Providers;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Vdu\TisLogging\Console\InstallCommand;
use Vdu\TisLogging\Http\Middleware\LogFileDownloads;
use Vdu\TisLogging\Http\Middleware\LogPageViews;
use Vdu\TisLogging\Listeners\GlobalModelAuditListener;
use Vdu\TisLogging\Listeners\LogFailedLogin;
use Vdu\TisLogging\Listeners\LogLogout;
use Vdu\TisLogging\Listeners\LogSentMail;
use Vdu\TisLogging\Listeners\LogSuccessfulLogin;
use Vdu\TisLogging\Listeners\QueryAuditListener;
use Vdu\TisLogging\Support\OldValuesSnapshotStore;
use Vdu\TisLogging\Support\QueueContext;

class AuditLogServiceProvider extends ServiceProvider
{
    /**
     * Bootstrapping: config publish, migracijų kelias, auth event listener'iai,
     * globalus modelio audito listener'is, Artisan komandos, automatinis
     * atsisiuntimų middleware.
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../../config/audit.php' => config_path('audit.php'),
        ], 'audit-config');

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        // Klientinės pusės įvykių endpoint'as - registruojamas tik jei
        // eksplicitiškai įjungtas, nes tai viešai pasiekiamas maršrutas.
        if (config('audit.client_events.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../../routes/client-events.php');
        }

        Event::listen(Login::class, LogSuccessfulLogin::class);
        Event::listen(Logout::class, LogLogout::class);
        Event::listen(Failed::class, LogFailedLogin::class);

        $this->registerQueueContextHandling();

        // Išsiųsti el. laiškai - reikšmingas veiksmas su asmens duomenimis,
        // kurio modelio/SQL mechanizmai nepamato (DB nekeičiamas).
        // MessageSent egzistuoja nuo Laravel 5.x, bet tikriname apsaugai.
        if (class_exists(\Illuminate\Mail\Events\MessageSent::class)) {
            Event::listen(\Illuminate\Mail\Events\MessageSent::class, LogSentMail::class.'@handle');
        }

        // Globalus visų Eloquent modelių audito fiksavimas - veikia
        // NEPRIKLAUSOMAI nuo console/web konteksto, nes modelio pokyčiai
        // vyksta ir per artisan komandas/queue job'us/seederius, ne tik
        // per web request'us.
        //
        // Naudojame "Klasė@metodas" eilutės sintaksę, o NE masyvą
        // [Klasė::class, 'metodas'] - senesnės Laravel versijos (5.7)
        // masyvo atveju bando kviesti metodą statiškai, vietoj to, kad
        // išspręstų klasę per konteinerį. Eilutės sintaksė veikia
        // vienodai visose palaikomose versijose (5.7 - 9.x).
        if (config('audit.audit_all_models', true)) {
            Event::listen('eloquent.created: *', GlobalModelAuditListener::class.'@handleCreated');
            Event::listen('eloquent.updated: *', GlobalModelAuditListener::class.'@handleUpdated');
            Event::listen('eloquent.deleted: *', GlobalModelAuditListener::class.'@handleDeleted');
        }

        // Query lygmens fiksavimas - pagauna DB::table()->update() ir kitas
        // užklausas, kurios APEINA Eloquent modelius (tokiais atvejais
        // jokie modelio event'ai nemetami). Listener'is registruojamas
        // visada, bet pats tikrina config('audit.log_queries') vykdymo
        // metu ir iškart grįžta, jei išjungta (pagal nutylėjimą - taip).
        // Toks registravimas leidžia įjungti/išjungti per config runtime
        // metu, nereikalaujant aplikacijos perkrovimo.
        Event::listen(QueryExecuted::class, QueryAuditListener::class.'@handle');

        // Senų reikšmių nuskaitymas PRIEŠ UPDATE/DELETE vykdymą - be to
        // SQL lygmens įrašai negalėtų parodyti "iš ko į ką pakeitė".
        //
        // Laravel 8+ : per DB::beforeExecuting (veikia su BET KOKIU
        // draiveriu, įskaitant Oracle per yajra/laravel-oci8).
        // Laravel 5.7-7.x : per pakeistas jungties klases (žr. žemiau).
        $snapshotStore = $this->app->make(OldValuesSnapshotStore::class);

        if ($snapshotStore->isSupported()) {
            DB::beforeExecuting(function ($query, $bindings, $connection) use ($snapshotStore) {
                if (config('audit.log_queries', false)) {
                    $snapshotStore->capture($query, $bindings, $connection);
                }
            });
        } else {
            // Registruojame boot() metode, nes čia jau įvykdyti VISŲ
            // paketų register() metodai - tad matome ir svetimus
            // resolverius (pvz. yajra Oracle) ir galime juos apgaubti,
            // o ne perrašyti.
            $this->registerLegacyConnectionResolvers();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        } else {
            $kernel = $this->app->make(Kernel::class);

            if (config('audit.log_downloads', true)) {
                // Registruojame globalų middleware'į HTTP kernel'yje - projekto
                // Kernel.php faile NIEKO keisti nereikia.
                $kernel->pushMiddleware(LogFileDownloads::class);
            }

            if (config('audit.log_page_views.mode', 'off') !== 'off') {
                $kernel->pushMiddleware(LogPageViews::class);
            }
        }
    }

    /**
     * Registravimas: config merge, kad projektas veiktų net
     * nepublikavus config failo, ir pagrindinio serviso bind'inimas.
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/audit.php', 'audit');

        $this->app->singleton(\Vdu\TisLogging\EventLogger::class, function () {
            return new \Vdu\TisLogging\EventLogger();
        });

        // BŪTINA singleton - snapshot'ai išsaugomi prieš užklausą
        // ir paimami "QueryExecuted" metu, tad tai turi būti TAS PATS
        // objektas, ne du atskiri egzemplioriai.
        $this->app->singleton(OldValuesSnapshotStore::class);

        // BŪTINA singleton - seka laiškų kiekį per VISĄ užklausą, kad
        // pasiekus ribą būtų suformuota viena suvestinė.
        $this->app->singleton(\Vdu\TisLogging\Support\MailBatchTracker::class);

        // BŪTINA singleton - kontekstas nustatomas darbo pradžioje ir
        // naudojamas visų to darbo metu fiksuojamų įvykių.
        $this->app->singleton(QueueContext::class);

        // BŪTINA singleton - buferyje laikomas atidėtas SQL įrašas, kurį
        // reikia įvertinti atėjus kitai užklausai.
        $this->app->singleton(\Vdu\TisLogging\Support\PendingQueryLog::class);

        $this->app->alias(\Vdu\TisLogging\EventLogger::class, 'audit-log');
    }

    /**
     * Perkelia vartotojo kontekstą iš HTTP užklausos į queue darbuotoją.
     *
     * Queue darbai (naujienlaiškiai, eksportai) vykdomi atskirame procese,
     * kuriame nėra nei sesijos, nei HTTP užklausos - tad Auth::user() ten
     * grąžina null. Be šio mechanizmo žurnale atsirastų "kažkas išsiuntė
     * 500 laiškų" be autoriaus.
     *
     * Naudojami Laravel queue kabliukai:
     *   createPayloadUsing - įrašo kontekstą į darbo payload'ą įstatymo metu;
     *   before - atkuria jį darbuotojo procese;
     *   after/failing - išvalo, kad tas pats darbuotojas nepriskirtų to
     *   paties vartotojo kitų vartotojų darbams.
     */
    protected function registerQueueContextHandling(): void
    {
        if (!config('audit.queue_context', true)) {
            return;
        }

        // createPayloadUsing egzistuoja nuo Laravel 5.7, bet tikriname
        // apsaugai - be jo kontekstas tiesiog nebus perkeliamas.
        if (!method_exists(Queue::class, 'createPayloadUsing')) {
            return;
        }

        Queue::createPayloadUsing(function () {
            try {
                $captured = $this->app->make(QueueContext::class)->capture();

                return empty($captured) ? [] : [QueueContext::PAYLOAD_KEY => $captured];
            } catch (\Throwable $e) {
                // Audito klaida NIEKADA neturi sutrukdyti darbo įstatymui.
                return [];
            }
        });

        Queue::before(function (JobProcessing $event) {
            try {
                $payload = $event->job->payload();

                $this->app->make(QueueContext::class)
                    ->set($payload[QueueContext::PAYLOAD_KEY] ?? null);
            } catch (\Throwable $e) {
                //
            }
        });

        $clear = function () {
            try {
                $this->app->make(QueueContext::class)->clear();
            } catch (\Throwable $e) {
                //
            }
        };

        Queue::after($clear);
        Queue::failing($clear);
    }

    /**
     * Įjungia senų reikšmių perėmimą Laravel 5.7-7.x versijose, kur nėra
     * DB::beforeExecuting().
     *
     * Naudojami DU skirtingi būdai:
     *
     * 1) Draiveriai BE svetimo resolverio (mysql, pgsql, sqlite, sqlsrv) -
     *    tiesiog registruojame savo jungties poklasį.
     *
     * 2) Draiveriai SU svetimu resolveriu (pvz. Oracle per yajra) -
     *    resolverio NEPERRAŠOME (jis atlieka gyvybiškai svarbią
     *    konfigūraciją: NLS datų formatus, dešimtainius skirtukus,
     *    CURRENT_SCHEMA). Vietoj to jį APGAUBIAME: leidžiame atlikti
     *    visą darbą, tada perkeliame gautą būseną į savo poklasį.
     *    Jei kas nors nepavyktų - grąžinama originali jungtis, tad
     *    projektas veikia normaliai, tik be old_values.
     */
    protected function registerLegacyConnectionResolvers(): void
    {
        if (!method_exists(Connection::class, 'getResolver')) {
            return;
        }

        $standard = [
            'mysql' => \Vdu\TisLogging\Database\AuditingMySqlConnection::class,
            'pgsql' => \Vdu\TisLogging\Database\AuditingPostgresConnection::class,
            'sqlite' => \Vdu\TisLogging\Database\AuditingSQLiteConnection::class,
            'sqlsrv' => \Vdu\TisLogging\Database\AuditingSqlServerConnection::class,
        ];

        foreach ($standard as $driver => $class) {
            if (Connection::getResolver($driver) === null) {
                Connection::resolverFor($driver, function ($pdo, $database, $prefix, $config) use ($class) {
                    return new $class($pdo, $database, $prefix, $config);
                });
            }
        }

        $this->decorateOracleResolver();
    }

    /**
     * Apgaubia yajra/laravel-oci8 resolverį, išsaugant visą jo atliekamą
     * Oracle konfigūraciją.
     */
    protected function decorateOracleResolver(): void
    {
        if (!class_exists(\Yajra\Oci8\Oci8Connection::class)) {
            return;
        }

        $original = Connection::getResolver('oracle');

        if ($original === null) {
            return;
        }

        Connection::resolverFor('oracle', function ($pdo, $database, $prefix, $config) use ($original) {
            // Originalus resolveris atlieka VISĄ darbą: prisijungia,
            // nustato NLS seanso kintamuosius, schemą, edition.
            $base = $original($pdo, $database, $prefix, $config);

            try {
                return \Vdu\TisLogging\Database\ConnectionStateCopier::copyInto(
                    $base,
                    \Vdu\TisLogging\Database\AuditingOci8Connection::class
                );
            } catch (\Throwable $e) {
                // Saugus atsitraukimas: jei būsenos perkelti nepavyko,
                // grąžiname originalią, pilnai veikiančią jungtį.
                // Auditas veiks be old_values, bet projektas nenukentės.
                return $base;
            }
        });
    }
}
