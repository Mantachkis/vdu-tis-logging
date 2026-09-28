<?php

namespace Vdu\TisLogging\Tests;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\SyslogHandler;
use Vdu\TisLogging\EventLogger;
use Vdu\TisLogging\Support\SizeLimitedJsonFormatter;

class SyslogDriverTest extends TestCase
{
    /**
     * Ištraukia logger'io handler'ius - jie protected, tad per refleksiją.
     */
    protected function handlersOf(EventLogger $logger, string $property): array
    {
        $reflection = new \ReflectionObject($logger);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);

        return $prop->getValue($logger)->getHandlers();
    }

    /** @test */
    public function the_file_driver_writes_only_to_files()
    {
        config(['audit.driver' => 'file']);

        $logger = new EventLogger();

        $handlers = $this->handlersOf($logger, 'auditLogger');

        $this->assertCount(1, $handlers);
        $this->assertInstanceOf(RotatingFileHandler::class, $handlers[0]);
    }

    /** @test */
    public function the_syslog_driver_writes_only_to_syslog()
    {
        config(['audit.driver' => 'syslog']);

        $logger = new EventLogger();

        $handlers = $this->handlersOf($logger, 'auditLogger');

        $this->assertCount(1, $handlers);
        $this->assertInstanceOf(SyslogHandler::class, $handlers[0]);
    }

    /** @test */
    public function the_both_driver_writes_to_files_and_syslog()
    {
        // Rekomenduojama pereinamoji busena - irasai nepradingsta, jei
        // rsyslog konfiguracija dar nesuveiks.
        config(['audit.driver' => 'both']);

        $logger = new EventLogger();

        $auditHandlers = $this->handlersOf($logger, 'auditLogger');
        $errorHandlers = $this->handlersOf($logger, 'errorLogger');

        $this->assertCount(2, $auditHandlers);
        $this->assertCount(2, $errorHandlers);
    }

    /** @test */
    public function the_ident_starts_with_laravel_and_names_the_project()
    {
        config(['audit.app_name' => 'epasirasymas']);

        $this->assertSame('laravel-epasirasymas-audit', EventLogger::syslogIdent('audit'));
        $this->assertSame('laravel-epasirasymas-error', EventLogger::syslogIdent('error'));
    }

    /** @test */
    public function channels_can_share_one_ident()
    {
        config([
            'audit.app_name' => 'epasirasymas',
            'audit.syslog.separate_channels' => false,
        ]);

        $this->assertSame('laravel-epasirasymas', EventLogger::syslogIdent('audit'));
        $this->assertSame('laravel-epasirasymas', EventLogger::syslogIdent('error'));
    }

    /** @test */
    public function the_prefix_can_be_changed()
    {
        config([
            'audit.app_name' => 'testas',
            'audit.syslog.ident_prefix' => 'vdu-',
        ]);

        $this->assertSame('vdu-testas-audit', EventLogger::syslogIdent('audit'));
    }

    /** @test */
    public function syslog_entries_use_the_size_limited_formatter()
    {
        // Be dydzio ribojimo syslog nukirptu JSON ir irasas taptu
        // nebeskaitomas - prarastume ji visa.
        config(['audit.driver' => 'syslog']);

        $logger = new EventLogger();
        $handlers = $this->handlersOf($logger, 'auditLogger');

        $this->assertInstanceOf(SizeLimitedJsonFormatter::class, $handlers[0]->getFormatter());
    }

    /** @test */
    public function oversized_entries_drop_the_values_first()
    {
        $formatter = new SizeLimitedJsonFormatter(500);

        $record = [
            'message' => 'Users (ID 42) - update',
            'context' => [
                'category' => 'update',
                'user_id' => 33131,
                'old_values' => ['turinys' => str_repeat('a', 2000)],
                'new_values' => ['turinys' => str_repeat('b', 2000)],
            ],
            'level' => 200,
            'level_name' => 'INFO',
            'channel' => 'audit',
            'datetime' => new \DateTime(),
            'extra' => [],
        ];

        $output = $formatter->format($record);
        $decoded = json_decode($output, true);

        // Irasas islieka galiojantis JSON.
        $this->assertNotNull($decoded);

        // Svarbiausia informacija islieka.
        $this->assertSame('update', $decoded['context']['category']);
        $this->assertSame(33131, $decoded['context']['user_id']);

        // Didziausi laukai pakeisti zyma.
        $this->assertSame('[TRUNCATED]', $decoded['context']['old_values']);
        $this->assertSame('values', $decoded['context']['_truncated']);
    }

    /** @test */
    public function entries_within_the_limit_are_untouched()
    {
        $formatter = new SizeLimitedJsonFormatter(7000);

        $record = [
            'message' => 'Trumpas irasas',
            'context' => ['category' => 'login', 'old_values' => ['x' => 'y']],
            'level' => 250,
            'level_name' => 'NOTICE',
            'channel' => 'audit',
            'datetime' => new \DateTime(),
            'extra' => [],
        ];

        $decoded = json_decode($formatter->format($record), true);

        $this->assertSame(['x' => 'y'], $decoded['context']['old_values']);
        $this->assertArrayNotHasKey('_truncated', $decoded['context']);
    }

    /** @test */
    public function the_limit_can_be_disabled()
    {
        $formatter = new SizeLimitedJsonFormatter(0);

        $record = [
            'message' => 'Ilgas',
            'context' => ['old_values' => ['x' => str_repeat('a', 5000)]],
            'level' => 200,
            'level_name' => 'INFO',
            'channel' => 'audit',
            'datetime' => new \DateTime(),
            'extra' => [],
        ];

        $decoded = json_decode($formatter->format($record), true);

        $this->assertArrayNotHasKey('_truncated', $decoded['context']);
    }
}
