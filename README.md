# vdu/tis-logging

Bendra veiksmų žurnalizavimo (audit log) sistema visiems įmonės Laravel projektams.

Fiksuoja:
1. kas prisijungė (login/logout/nepavykę bandymai);
2. kokius duomenis peržiūrėjo;
3. ką pakeitė (create/update/delete);
4. kada atliko veiksmą (tiksli data ir laikas).

Kiekvienam įrašui saugoma: įvykio data ir laikas, įvykio rūšis (info/error/security/system/warning),
vartotojo/įrenginio identifikavimo duomenys, įvykio aprašymas.

## Statusas

Šis paketas kuriamas etapais:

- [x] 1 etapas – paketo repo ir bazinės struktūros paruošimas
- [x] 2 etapas – EventLogger branduolys (Monolog + PSR-3)
- [x] 3 etapas – integraciniai hook'ai (auth, model, view)
- [ ] 4 etapas – diegimo automatizavimas (`audit:install` komanda)
- [ ] 5 etapas – versijavimas ir platinimo kanalas
- [ ] 6 etapas – pilotinis diegimas
- [ ] 7 etapas – diegimas į visus projektus
- [ ] 8 etapas – centralizuotas monitoringas

## Reikalavimai

- PHP ^7.1.3 arba ^8.0
- Laravel (illuminate/*) ^5.7 - ^9.0

### Suderinamumo matrica

| PHP | Laravel | Monolog | Statusas |
|---|---|---|---|
| 7.1 - 7.4 | 5.7 - 5.8 | 1.23+ | ✅ Naudojama esamuose VDU TIS projektuose |
| 7.3 - 8.0 | 6.x - 9.x | 1.23+ arba 2.x | ✅ Palaikoma per platesnius composer.json apribojimus |
| 8.1+ | 10.x+ | 3.x | ⚠️ Nebandyta - Monolog 3.x turi lūžtančių (breaking) pakeitimų. Prieš diegiant tokiame projekte, paleisti pilną testų rinkinį (`vendor/bin/phpunit`) ir patikrinti rezultatus.

## Diegimas (kai paketas bus paruoštas naudoti)

```bash
composer require vdu/tis-logging
php artisan vendor:publish --tag=audit-config
```

Konfigūracija: `config/audit.php` arba `.env` kintamieji:

```
AUDIT_LOG_APP_NAME=adresas1
AUDIT_LOG_BASE_PATH=/home/logs
AUDIT_LOG_AUDIT_FILENAME=audit.log
AUDIT_LOG_ERROR_FILENAME=error.log
AUDIT_LOG_RETENTION_DAYS=90
AUDIT_LOG_DRIVER=file
```

Rezultatas serveryje:

```
/home/
├── studentas/              ← čia gyvena patys Laravel projektai
│   ├── www.adresas1/
│   └── www.adresas2/
├── sso/
├── andrius/
└── logs/                   ← ŽURNALAI, tame pačiame lygyje kaip studentas/sso/andrius
    ├── adresas1/
    │   ├── audit.log       ← info, security, system įvykiai
    │   └── error.log       ← error IR warning tipo įvykiai
    ├── adresas2/
    │   ├── audit.log
    │   └── error.log
```

**Svarbu:** `AUDIT_LOG_BASE_PATH` VISADA reikia nurodyti eksplicitiškai kiekviename projekto `.env`
faile. Automatinis `getenv('HOME')` fallback (jei kintamasis nenurodytas) grąžintų projekto
vartotojo home katalogą (pvz. `/home/studentas`), o NE bendrą `/home/logs` katalogą - tai reikštų,
kad kiekvienas projektas rašytų į savo atskirą, izoliuotą vietą, o ne į bendrą, visiems
administratoriams matomą žurnalų katalogą.

## Naudojimas

Paketas prieinamas per `AuditLog` fasadą (auto-registruotas), be reikalo importuoti klasę:

```php
use AuditLog;

// Bendras metodas su explicit event_type
AuditLog::log('security', 'login', 'Vartotojas prisijungė', [
    'user_id' => $user->id,
    'user_identifier' => $user->email,
]);

// Patogesni trumpiniai - identiškas rezultatas
AuditLog::info('view', 'Peržiūrėtas sąskaitos įrašas', [
    'subject_type' => \App\Models\Invoice::class,
    'subject_id' => $invoice->id,
]);

AuditLog::security('login', 'Vartotojas prisijungė');
AuditLog::system('cron', 'Paleistas naktinis eksportas');
AuditLog::warning('login_blocked', 'Bandymas prisijungti prie nepatvirtintos paskyros');
AuditLog::error('exception', 'Nepagauta klaida apdorojant mokėjimą');
```

Kiekvienas įrašas automatiškai papildomas: tikslia data/laiku (ISO 8601), IP adresu ir
User-Agent iš esamo request'o, bei prisijungusio vartotojo ID/el. paštu (jei `user_id`/
`user_identifier` nenurodyti rankomis `$data` masyve).

**`info`, `security`, `system`** → `audit.log`
**`warning`, `error`** → `error.log`

### Testų paleidimas

```bash
composer install
vendor/bin/phpunit
```

Testai naudoja laikiną katalogą (`sys_get_temp_dir()`) ir SQLite in-memory DB, tad NIEKADA
neliečia realaus serverio `/home/logs` kelio ar projekto duomenų bazės.

## Auth įvykiai (prisijungimas/atsijungimas/nepavykę bandymai)

Registruojasi AUTOMATIŠKAI vos padarius `composer require` - jokios papildomos
konfigūracijos nereikia standartiniam Laravel `Auth::attempt()` naudojimui.

Pagauna: `Illuminate\Auth\Events\Login`, `Logout`, `Failed`.

**Svarbus apribojimas:** jei projekto login kontroleris apeina `Auth::attempt()`
(pvz. rankiniu `Auth::guard('x')->login($user)` kvietimu arba SSO broker'io
integracija), atitinkami Laravel event'ai NEBUS sukviesti automatiškai. Tokiu
atveju reikia rankinio `AuditLog::security(...)` kvietimo tose šakose - žr.
projekto login kontrolerio analizę (jei tokia buvo pateikta atskirai).

## Modelio pokyčiai - `Auditable` trait

```php
use Vdu\TisLogging\Traits\Auditable;

class Invoice extends Model
{
    use Auditable;

    // Neprivaloma: papildomi laukai, kurių šis modelis neturi audituoti
    // (be globalaus config('audit.exclude') sąrašo, kuris pagal nutylėjimą
    // pašalina "password" ir "remember_token").
    public function auditExclude(): array
    {
        return ['internal_notes'];
    }
}
```

Create/update/delete veiksmai loginami automatiškai, su senomis ir naujomis reikšmėmis.

## Peržiūra - `LogsViews` trait

Eloquent neturi "peržiūrėjimo" įvykio, tad šis kvietimas visada bus rankinis:

```php
use Vdu\TisLogging\Traits\LogsViews;

class InvoiceController extends Controller
{
    use LogsViews;

    public function show(Invoice $invoice)
    {
        $this->logView($invoice);
        return view('invoices.show', compact('invoice'));
    }
}
```



Kadangi `/home/logs` yra už kiekvieno projekto vartotojo home katalogo ribų, reikia bendros
Linux grupės su rašymo teise:

```bash
mkdir -p /home/logs
groupadd audit-writers
usermod -aG audit-writers studentas
# usermod -aG audit-writers sso        (pakartoti kiekvienam vartotojui, kuris ras rašyti)
chown root:audit-writers /home/logs
chmod 2775 /home/logs   # setgid bitas - nauji poaplankiai/failai paveldi audit-writers grupę
```

## Licencija

Vidinis įmonės naudojimas (proprietary).
