<?php

namespace Vdu\TisLogging\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Vdu\TisLogging\Providers\AuditLogServiceProvider;

class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [AuditLogServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Testams naudojame laikiną katalogą, kad testai NIEKADA nerašytų
        // į realų serverio /home/logs kelią.
        $app['config']->set('audit.base_path', sys_get_temp_dir().'/vdu-tis-logging-tests');
        $app['config']->set('audit.app_name', 'testapp');
    }
}
