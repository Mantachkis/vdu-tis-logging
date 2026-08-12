<?php

namespace Vdu\TisLogging\Providers;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Vdu\TisLogging\Console\InstallCommand;
use Vdu\TisLogging\Listeners\LogFailedLogin;
use Vdu\TisLogging\Listeners\LogLogout;
use Vdu\TisLogging\Listeners\LogSuccessfulLogin;

class AuditLogServiceProvider extends ServiceProvider
{
    /**
     * Bootstrapping: config publish, migracijų kelias, auth event listener'iai,
     * Artisan komandos.
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

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
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
