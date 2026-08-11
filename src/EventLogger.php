<?php

namespace Vdu\TisLogging;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\JsonFormatter;

/**
 * Centrinis įvykių žurnalizavimo servisas.
 *
 * Viduje naudoja Monolog (PSR-3 suderintas LoggerInterface), tad
 * pats žurnalizavimo mechanizmas atitinka PSR-3 standartą, net jei
 * ši klasė turi savo, domeno prasme aiškesnį API (log/info/security/...).
 *
 * Įvykiai automatiškai skirstomi į du atskirus failus:
 * - audit.log  <- info, security, system
 * - error.log  <- warning, error
 */
class EventLogger
{
    const TYPE_INFO = 'info';
    const TYPE_SECURITY = 'security';
    const TYPE_SYSTEM = 'system';
    const TYPE_WARNING = 'warning';
    const TYPE_ERROR = 'error';

    /**
     * Įvykio tipai, kurie nukeliauja į error.log.
     */
    const ERROR_CHANNEL_TYPES = [self::TYPE_WARNING, self::TYPE_ERROR];

    /** @var Logger */
    protected $auditLogger;

    /** @var Logger */
    protected $errorLogger;

    public function __construct()
    {
        $appName = (string) config('audit.app_name', 'app');
        $basePath = rtrim((string) config('audit.base_path', ''), '/');
        $dir = $basePath.'/'.$appName;

        $this->ensureDirectoryExists($dir);

        $this->auditLogger = $this->makeLogger(
            'audit',
            $dir.'/'.config('audit.audit_filename', 'audit.log')
        );

        $this->errorLogger = $this->makeLogger(
            'error',
            $dir.'/'.config('audit.error_filename', 'error.log')
        );
    }

    /**
     * Pagrindinis žurnalizavimo metodas.
     *
     * @param string $eventType  info | security | system | warning | error
     * @param string $category   laisva kategorija, pvz. "login", "view", "update"
     * @param string $description žmogui suprantamas įvykio aprašymas
     * @param array $data {
     *     @var int|null    $user_id
     *     @var string|null $user_identifier   naudotojo el. paštas/login, jei nėra autentifikuoto Auth::user()
     *     @var string|null $subject_type       susieto modelio klasė, pvz. App\Models\Invoice
     *     @var int|null    $subject_id
     *     @var array|null  $old_values
     *     @var array|null  $new_values
     *     @var array|null  $context            laisva papildoma informacija
     * }
     */
    public function log(string $eventType, string $category, string $description, array $data = []): void
    {
        $user = Auth::user();

        $context = [
            'occurred_at'     => now()->toIso8601String(),
            'event_type'      => $eventType,
            'category'        => $category,
            'user_id'         => $data['user_id'] ?? optional($user)->id,
            'user_identifier' => $data['user_identifier'] ?? optional($user)->email,
            'ip_address'      => Request::ip(),
            'user_agent'      => Request::header('User-Agent'),
            'subject_type'    => $data['subject_type'] ?? null,
            'subject_id'      => $data['subject_id'] ?? null,
            'old_values'      => $data['old_values'] ?? null,
            'new_values'      => $data['new_values'] ?? null,
            'context'         => $data['context'] ?? null,
        ];

        $this->resolveLogger($eventType)->log($this->mapLevel($eventType), $description, $context);
    }

    /**
     * Trumpiniai metodai - patogesni naudoti nei log() su explicit event_type stringu.
     */
    public function info(string $category, string $description, array $data = []): void
    {
        $this->log(self::TYPE_INFO, $category, $description, $data);
    }

    public function security(string $category, string $description, array $data = []): void
    {
        $this->log(self::TYPE_SECURITY, $category, $description, $data);
    }

    public function system(string $category, string $description, array $data = []): void
    {
        $this->log(self::TYPE_SYSTEM, $category, $description, $data);
    }

    public function warning(string $category, string $description, array $data = []): void
    {
        $this->log(self::TYPE_WARNING, $category, $description, $data);
    }

    public function error(string $category, string $description, array $data = []): void
    {
        $this->log(self::TYPE_ERROR, $category, $description, $data);
    }

    protected function resolveLogger(string $eventType): Logger
    {
        return in_array($eventType, self::ERROR_CHANNEL_TYPES, true)
            ? $this->errorLogger
            : $this->auditLogger;
    }

    protected function mapLevel(string $eventType): int
    {
        $map = [
            self::TYPE_INFO     => Logger::INFO,
            self::TYPE_SECURITY => Logger::NOTICE,
            self::TYPE_SYSTEM   => Logger::INFO,
            self::TYPE_WARNING  => Logger::WARNING,
            self::TYPE_ERROR    => Logger::ERROR,
        ];

        return $map[$eventType] ?? Logger::INFO;
    }

    protected function ensureDirectoryExists(string $dir): void
    {
        if (!is_dir($dir)) {
            // @ - jei katalogas jau egzistuoja (race condition tarp kelių
            // vienalaikių request'ų), mkdir() metų warning, kurį tyliai ignoruojame.
            @mkdir($dir, 0775, true);
        }
    }

    protected function makeLogger(string $channel, string $path): Logger
    {
        // bubble=true, filePermission=0664 (naujam failui), useLocking=true
        // (flock() apsauga nuo vienalaikio rašymo iš kelių PHP-FPM worker'ių).
        $handler = new StreamHandler($path, Logger::DEBUG, true, 0664, true);
        $handler->setFormatter(new JsonFormatter());

        $logger = new Logger($channel);
        $logger->pushHandler($handler);

        return $logger;
    }
}
