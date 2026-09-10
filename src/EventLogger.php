<?php

namespace Vdu\TisLogging;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\JsonFormatter;
use Vdu\TisLogging\Support\QueueContext;

/**
 * Centrinis įvykių žurnalizavimo servisas.
 *
 * Viduje naudoja Monolog (PSR-3 suderintas LoggerInterface), tad
 * pats žurnalizavimo mechanizmas atitinka PSR-3 standartą, net jei
 * ši klasė turi savo, domeno prasme aiškesnį API (log/info/security/...).
 *
 * Įvykiai automatiškai skirstomi į du atskirus poaplankius/kanalus:
 * - {app}/audit/audit-YYYY-MM-DD.log  <- info, security, system
 * - {app}/error/error-YYYY-MM-DD.log  <- warning, error
 *
 * Naudojamas Monolog RotatingFileHandler - kiekvienai dienai automatiškai
 * sukuriamas naujas failas, o senesni nei config('audit.retention_days')
 * dienų failai automatiškai ištrinami (0 = niekada netrinti automatiškai).
 */
class EventLogger
{
    const TYPE_INFO = 'info';
    const TYPE_SECURITY = 'security';
    const TYPE_SYSTEM = 'system';
    const TYPE_WARNING = 'warning';
    const TYPE_ERROR = 'error';

    /**
     * Įvykio tipai, kurie nukeliauja į error kanalą.
     */
    const ERROR_CHANNEL_TYPES = [self::TYPE_WARNING, self::TYPE_ERROR];

    /**
     * Kategorijos, kurios laikomos REIKŠMINGU veiksmu (ne peržiūra).
     *
     * Naudojama LogPageViews 'auto' režime: jei per užklausą buvo
     * užfiksuota bent viena šių kategorijų, POST užklausa NEBUS papildomai
     * fiksuojama kaip peržiūra - nes tai buvo veiksmas, ne peržiūra.
     */
    const ACTION_CATEGORIES = [
        'create', 'update', 'delete',
        'db_insert', 'db_update', 'db_delete',
        'mail_sent',
    ];

    /** @var Logger */
    protected $auditLogger;

    /** @var Logger */
    protected $errorLogger;

    /**
     * Kiek reikšmingų veiksmų užfiksuota per šią užklausą.
     * EventLogger yra singleton, tad skaitiklis gyvuoja visą request'ą.
     *
     * @var int
     */
    protected $recordedActions = 0;

    /**
     * Lentelės, kurių pakeitimus per šią užklausą JAU užfiksavo Eloquent
     * mechanizmas (create/update/delete su subject_type).
     *
     * Naudoja QueryAuditListener, kad nedubliuotų to paties pakeitimo
     * SQL lygmeniu - kitaip $model->save() duotų DU įrašus: "update" ir
     * "db_update".
     *
     * @var array<string, true>
     */
    protected $tablesRecordedByEloquent = [];

    public function __construct()
    {
        $appName = (string) config('audit.app_name', 'app');
        $basePath = rtrim((string) config('audit.base_path', ''), '/');
        $dir = $basePath.'/'.$appName;

        $auditDir = $dir.'/audit';
        $errorDir = $dir.'/error';

        $this->ensureDirectoryExists($auditDir);
        $this->ensureDirectoryExists($errorDir);

        $this->auditLogger = $this->makeRotatingLogger(
            'audit',
            $auditDir.'/'.config('audit.audit_filename', 'audit.log')
        );

        $this->errorLogger = $this->makeRotatingLogger(
            'error',
            $errorDir.'/'.config('audit.error_filename', 'error.log')
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
        $user = $this->resolveAuthenticatedUser();

        // Queue darbuotojo procese nėra nei sesijos, nei HTTP užklausos,
        // tad Auth::user() grąžina null. Tokiu atveju naudojame kontekstą,
        // išsaugotą darbo įstatymo momentu (žr. QueueContext) - kitaip
        // žurnale atsirastų "kažkas išsiuntė 500 laiškų" be autoriaus.
        $queue = app(QueueContext::class);

        $context = [
            // Atidėtiems įrašams (žr. PendingQueryLog) laikas fiksuojamas
            // įvykio, ne rašymo momentu - kad žurnale išliktų teisinga
            // chronologija.
            'occurred_at'     => $data['occurred_at'] ?? now()->toIso8601String(),
            'event_type'      => $eventType,
            'category'        => $category,
            'user_id'         => $data['user_id']
                ?? optional($user)->getAuthIdentifier()
                ?? $queue->get('user_id'),
            'user_identifier' => $data['user_identifier']
                ?? ($user ? ($user->email ?? $user->username ?? null) : null)
                ?? $queue->get('user_identifier'),
            'ip_address'      => $this->requestIp() ?? $queue->get('ip_address'),
            'user_agent'      => $this->requestUserAgent() ?? $queue->get('user_agent'),
            'subject_type'    => $data['subject_type'] ?? null,
            'subject_id'      => $data['subject_id'] ?? null,
            'old_values'      => $data['old_values'] ?? null,
            'new_values'      => $data['new_values'] ?? null,
            'context'         => $data['context'] ?? null,
        ];

        $this->resolveLogger($eventType)->log($this->mapLevel($eventType), $description, $context);

        if (in_array($category, self::ACTION_CATEGORIES, true)) {
            $this->recordedActions++;
        }

        $this->rememberEloquentTable($category, $data);
    }

    /**
     * Įsimena, kurią lentelę Eloquent mechanizmas ką tik užfiksavo.
     */
    protected function rememberEloquentTable(string $category, array $data): void
    {
        if (!in_array($category, ['create', 'update', 'delete'], true)) {
            return;
        }

        $modelClass = $data['subject_type'] ?? null;

        if (!$modelClass || !class_exists($modelClass)) {
            return;
        }

        try {
            $table = (new $modelClass())->getTable();

            if ($table) {
                $this->tablesRecordedByEloquent[strtolower($table)] = true;
            }
        } catch (\Throwable $e) {
            // Modelio sukurti nepavyko (konstruktorius su argumentais ir
            // pan.) - dubliavimo išvengti negalėsime, bet tai geriau nei
            // sugriauti žurnalizavimą.
        }
    }

    /**
     * Ar šios lentelės pakeitimą per šią užklausą jau užfiksavo Eloquent.
     *
     * Naudoja QueryAuditListener, kad nedubliuotų to paties pakeitimo.
     */
    public function wasTableRecordedByEloquent(?string $table): bool
    {
        if (!$table) {
            return false;
        }

        return isset($this->tablesRecordedByEloquent[strtolower($table)]);
    }

    /**
     * Ar per šią užklausą buvo užfiksuotas bent vienas reikšmingas veiksmas
     * (duomenų pakeitimas ar laiško išsiuntimas).
     *
     * Naudoja LogPageViews 'auto' režimas, kad atskirtų POST-peržiūras
     * (formos su filtrais) nuo POST-veiksmų (išsaugojimai) - be jokio
     * rankinio maršrutų sąrašo.
     */
    public function hasRecordedActions(): bool
    {
        return $this->recordedActions > 0;
    }

    /**
     * Suranda prisijungusį vartotoją NEPRIKLAUSOMAI nuo to, per kurį guard'ą
     * jis prisijungė.
     *
     * Auth::user() tikrina TIK numatytąjį (config('auth.defaults.guard'))
     * guard'ą. Projektuose su keliais autentifikacijos būdais (pvz. atskiras
     * "web" SSO vartotojams ir custom "espUser" guard'as) tai reikštų, kad
     * bet kuris veiksmas, atliktas per NE-numatytąjį guard'ą, žurnale
     * atsirastų su user_id=null - reali spraga "kas atliko veiksmą"
     * reikalavimui. Todėl tikriname numatytąjį guard'ą pirmiausia (greičiausia,
     * dažniausias atvejis), o jei ten nieko nerasta - visus kitus projekte
     * config('auth.guards') sukonfigūruotus guard'us.
     */
    /**
     * Prisijungęs vartotojas (bet kuriame guard'e) arba null.
     *
     * Vieša, kad QueueContext galėtų surinkti tą patį kontekstą, kokį
     * fiksuotų sinchroninis kvietimas.
     */
    public function currentUser()
    {
        return $this->resolveAuthenticatedUser();
    }

    /**
     * Konsolės/queue kontekste HTTP užklausos nėra, tad Request fasadas
     * gali grąžinti niekam netinkamą reikšmę arba mesti išimtį.
     */
    protected function requestIp(): ?string
    {
        try {
            return app()->runningInConsole() ? null : (Request::ip() ?: null);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function requestUserAgent(): ?string
    {
        try {
            return app()->runningInConsole() ? null : (Request::header('User-Agent') ?: null);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function resolveAuthenticatedUser()
    {
        try {
            if ($user = Auth::user()) {
                return $user;
            }
        } catch (\Throwable $e) {
            // Numatytasis guard'as gali būti neteisingai/nevisai
            // sukonfigūruotas projekte - nenutraukiame žurnalizavimo dėl to.
        }

        foreach (array_keys(config('auth.guards', [])) as $guardName) {
            try {
                if ($user = Auth::guard($guardName)->user()) {
                    return $user;
                }
            } catch (\Throwable $e) {
                // Kai kurie custom/retai naudojami guard'ai gali mesti
                // klaidą, jei jų driver'is netinkamai sukonfigūruotas
                // šiam request'ui (pvz. API guard be tokeno). Tyliai
                // praleidžiame - žurnalizavimas neturi sugriauti request'o.
                continue;
            }
        }

        return null;
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

    protected function makeRotatingLogger(string $channel, string $path): Logger
    {
        $retentionDays = (int) config('audit.retention_days', 90);

        // maxFiles=$retentionDays (0 = niekada netrinti automatiškai),
        // bubble=true, filePermission=0664, useLocking=true (flock() apsauga
        // nuo vienalaikio rašymo iš kelių PHP-FPM worker'ių).
        $handler = new RotatingFileHandler($path, $retentionDays, Logger::DEBUG, true, 0664, true);
        $handler->setFormatter(new JsonFormatter());

        $logger = new Logger($channel);
        $logger->pushHandler($handler);

        return $logger;
    }
}
