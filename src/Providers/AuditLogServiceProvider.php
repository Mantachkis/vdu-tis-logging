<?php

namespace Vdu\TisLogging\Providers;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Vdu\TisLogging\Console\InstallCommand;
use Vdu\TisLogging\Http\Middleware\LogFileDownloads;
use Vdu\TisLogging\Listeners\GlobalModelAuditListener;
use Vdu\TisLogging\Listeners\LogFailedLogin;
use Vdu\TisLogging\Listeners\LogLogout;
use Vdu\TisLogging\Listeners\LogSuccessfulLogin;
use Vdu\TisLogging\Listeners\QueryAuditListener;

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

        Event::listen(Login::class, LogSuccessfulLogin::class);
        Event::listen(Logout::class, LogLogout::class);
        Event::listen(Failed::class, LogFailedLogin::class);

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

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        } elseif (config('audit.log_downloads', true)) {
            // Registruojame globalų middleware'į HTTP kernel'yje - projekto
            // Kernel.php faile NIEKO keisti nereikia. Tik web/HTTP kontekste
            // (ne artisan komandoms), kad nesikištume į CLI vykdymą.
            $this->app->make(Kernel::class)->pushMiddleware(LogFileDownloads::class);
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

        $this->app->alias(\Vdu\TisLogging\EventLogger::class, 'audit-log');
    }
}
