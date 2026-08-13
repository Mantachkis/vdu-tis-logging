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
        // į realų serverio /home/logs kelią. Kiekvienam testo metodui -
        // unikalus app_name (uniqid), nes Testbench iš naujo kviečia šitą
        // metodą prieš kiekvieną testą (refreshApplication). Taip
        // išvengiame poreikio trinti/pakartotinai atidaryti tą patį failą
        // tarp testų, kas Windows aplinkoje sukelia "Permission denied",
        // jei ankstesnio testo Monolog StreamHandler rankena dar nebuvo
        // pilnai uždaryta garbage collector'iaus.
        $app['config']->set('audit.base_path', sys_get_temp_dir().'/vdu-tis-logging-tests');
        $app['config']->set('audit.app_name', 'testapp-'.uniqid());

        // SQLite in-memory DB - Auditable/Observer testams reikia realios
        // Eloquent modelio schemos, bet ne realios projekto DB.
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /**
     * Grąžina šio testo unikalų žurnalų katalogą (žr. getEnvironmentSetUp).
     */
    protected function logDir(): string
    {
        return rtrim(config('audit.base_path'), '/').'/'.config('audit.app_name');
    }

    /**
     * Suranda šiandienos datuotą audit/error žurnalo failą (RotatingFileHandler
     * failo pavadinime automatiškai prideda datą, pvz. audit-2026-08-13.log,
     * tad tikslaus pavadinimo iš anksto nežinome - ieškome per glob()).
     */
    protected function findLogFile(string $channel): ?string
    {
        $files = glob($this->logDir().'/'.$channel.'/*.log');

        return $files[0] ?? null;
    }

    protected function lastLogEntry(string $channel): array
    {
        $file = $this->findLogFile($channel);

        $this->assertNotNull($file, "Nerastas {$channel} žurnalo failas kataloge ".$this->logDir()."/{$channel}");

        $lines = array_values(array_filter(explode("\n", trim(file_get_contents($file)))));

        return json_decode(end($lines), true);
    }
}
