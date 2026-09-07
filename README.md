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
- [x] 4 etapas – diegimo automatizavimas (`audit:install` komanda)
- [x] 5 etapas – versijavimas ir platinimo kanalas
- [ ] 6 etapas – pilotinis diegimas
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

## Diegimas realiame projekte

Kiekvieno projekto `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Mantachkis/vdu-tis-logging.git" }
    ],
    "require": {
        "vdu/tis-logging": "^1.0"
    }
}
```

Diegimas:

```bash
composer require vdu/tis-logging
php artisan audit:install
```

## Versijavimas

Naudojamas [Semantic Versioning](https://semver.org/): `MAJOR.MINOR.PATCH`.

- **PATCH** (`1.0.x`) - bug fix'ai, be API pakeitimų. Saugu automatiškai atsinaujinti.
- **MINOR** (`1.x.0`) - nauji, atgal suderinami funkcionalumai (pvz. naujas trait'as).
- **MAJOR** (`x.0.0`) - lūžtantys (breaking) pakeitimai, pvz. metodo signatūros
  pasikeitimas ar minimalios PHP/Laravel versijos pakėlimas.

Projektuose `composer.json` naudokite `^1.0` - tai leidžia automatinius patch/minor
naujinimus (`composer update vdu/tis-logging`), bet NE major versijos pasikeitimus,
kurie reikalautų peržiūrėti `CHANGELOG.md` prieš atnaujinant.

Visos versijos ir jų pakeitimai fiksuojami [`CHANGELOG.md`](CHANGELOG.md) faile.

`audit:install` komanda automatiškai:
1. publikuoja `config/audit.php`;
2. patikrina/papildo `.env` failą trūkstamais `AUDIT_LOG_*` kintamaisiais (numatytosios reikšmės);
3. sukuria žurnalų katalogą (`{AUDIT_LOG_BASE_PATH}/{AUDIT_LOG_APP_NAME}`) ir patikrina, ar jis rašomas.

**Po komandos VISADA patikrinkite** `.env` faile pridėtas `AUDIT_LOG_APP_NAME` ir
`AUDIT_LOG_BASE_PATH` reikšmes - numatytosios gali netikti konkrečiam projektui/serveriui.

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
    ├── makademijatest/
    │   ├── audit/
    │   │   ├── audit-2026-08-13.log   ← info, security, system įvykiai
    │   │   └── audit-2026-08-12.log
    │   └── error/
    │       ├── error-2026-08-13.log   ← error IR warning tipo įvykiai
    │       └── error-2026-08-12.log
    ├── adresas2/
    │   ├── audit/
    │   └── error/
```

Kiekvienai dienai automatiškai sukuriamas naujas failas (Monolog `RotatingFileHandler`),
o senesni nei `AUDIT_LOG_RETENTION_DAYS` dienų failai automatiškai ištrinami.

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

**`info`, `security`, `system`** → `{app_name}/audit/audit-YYYY-MM-DD.log`
**`warning`, `error`** → `{app_name}/error/error-YYYY-MM-DD.log`

### Testų paleidimas

```bash
composer install
vendor/bin/phpunit
```

Testai naudoja laikiną katalogą (`sys_get_temp_dir()`) ir SQLite in-memory DB, tad NIEKADA
neliečia realaus serverio `/home/logs` kelio ar projekto duomenų bazės. Kiekvienas testo
metodas naudoja unikalų poaplankį (išvengti Windows failų rankenų konfliktų), tad laikini
failai kaupiasi `%TEMP%/vdu-tis-logging-tests/` - juos galima retkarčiais rankomis išvalyti,
tai nekritinis dalykas (OS temp katalogas periodiškai švarinamas pats).

## Auth įvykiai (prisijungimas/atsijungimas/nepavykę bandymai)

Registruojasi AUTOMATIŠKAI vos padarius `composer require` - jokios papildomos
konfigūracijos nereikia standartiniam Laravel `Auth::attempt()` naudojimui.

Pagauna: `Illuminate\Auth\Events\Login`, `Logout`, `Failed`.

**Svarbus apribojimas:** jei projekto login kontroleris apeina `Auth::attempt()`
(pvz. rankiniu `Auth::guard('x')->login($user)` kvietimu arba SSO broker'io
integracija), atitinkami Laravel event'ai NEBUS sukviesti automatiškai. Tokiu
atveju reikia rankinio `AuditLog::security(...)` kvietimo tose šakose - žr.
projekto login kontrolerio analizę (jei tokia buvo pateikta atskirai).

## Modelio pokyčiai

### Automatinis fiksavimas (numatytoji elgsena nuo v2.0.0)

**Nereikia jokio kontrolerio ar modelio redagavimo.** Paketas automatiškai
audituoja **VISŲ** projekto Eloquent modelių create/update/delete įvykius,
su senomis ir naujomis reikšmėmis (`old_values` rodo tik pasikeitusius
laukus - BDAR duomenų minimizavimas).

Galima išjungti, jei nepageidaujama:
```
AUDIT_LOG_ALL_MODELS=false
```

Konkretiems modeliams, kurių audituoti nereikia (pvz. aukšto dažnio,
techniniai, ar nejautrūs modeliai), pridėkite juos `config/audit.php`:
```php
'exclude_models' => [
    \App\Models\Session::class,
    \App\Models\PageView::class,
],
```

Modeliui apibrėžus `auditExclude()` metodą, globalus mechanizmas jį gerbia
lygiai taip pat, kaip ir su `Auditable` trait (žr. žemiau):
```php
class Invoice extends Model
{
    public function auditExclude(): array
    {
        return ['internal_notes'];
    }
}
```

### Rankinis fiksavimas - `Auditable` trait

Jei `AUDIT_LOG_ALL_MODELS=false`, arba tiesiog norite eksplicitiškai pažymėti
konkrečius modelius (aiškumui kode), naudokite `Auditable` trait:

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

Modeliai su `Auditable` trait automatiškai **praleidžiami** globaliame
mechanizme (kad nebūtų dubliuoto fiksavimo) - jų auditavimą tvarko pats
trait'as, identiška logika abiem atvejais.

### ⚠️ Svarbu: `DB::table()` apeina Eloquent

Eloquent modelio event'ai **nesuveikia**, kai duomenys keičiami apeinant
modelio instanciją:

```php
DB::table('news')->where('id', 5)->update([...]);   // ❌ NEfiksuojama
Model::where('id', 5)->update([...]);               // ❌ NEfiksuojama (query builder)

$news = Model::find(5);
$news->title = 'Naujas';
$news->save();                                      // ✅ Fiksuojama
```

Tai fundamentalus Laravel elgesys - `DB::table()` vykdo tiesioginę SQL
užklausą, nesukurdamas modelio instancijos, tad joks modelio event'as
nemetamas. **Jei jūsų projekte tokių vietų yra** (dažna praktika senesniuose
kontroleriuose), turite du variantus:

**A) Pertvarkyti kontrolerius** naudoti modelio instancijas - švariausia,
ir tik taip gausite tikslų `old_values` ("iš ko į ką pakeitė"):

```php
$news = Makademija_news::find($request->id);
$news->fill(['news_header' => $request->news_header, ...]);
$news->save();
```

**B) Įjungti SQL užklausų lygmens fiksavimą** (žr. žemiau) - apima viską
be kontrolerių redagavimo, bet be `old_values`.

## SQL užklausų fiksavimas (`AUDIT_LOG_QUERIES`)

Įjungiama per `.env`:
```
AUDIT_LOG_QUERIES=true
```

Fiksuoja **visas** `INSERT`/`UPDATE`/`DELETE` užklausas SQL lygmeniu,
nepriklausomai nuo to, kaip jos sukurtos (Eloquent, `DB::table()`,
`DB::statement()`). `SELECT` užklausos **nefiksuojamos** - jos kurtų
milžinišką triukšmą be audito vertės.

Įrašo kategorijos: `db_insert`, `db_update`, `db_delete`.

### Senos reikšmės (`old_values`)

Prieš kiekvieną `UPDATE`/`DELETE` paketas atlieka papildomą `SELECT`, kad
nuskaitytų įrašo būseną prieš pakeitimą - tad žurnale matoma **"iš ko į ką
pakeitė"** net ir naudojant `DB::table()`.

Rodomi **tik realiai pasikeitę** laukai: jei forma siunčia visus stulpelius,
bet dalis jų perrašoma tomis pačiomis reikšmėmis, tokie stulpeliai į žurnalą
nepatenka. `UPDATE`, kuris nieko nepakeitė, apskritai nefiksuojamas.

```
AUDIT_LOG_CAPTURE_OLD_VALUES=true    # numatytoji reikšmė
AUDIT_LOG_OLD_VALUES_MAX_ROWS=5      # riba masiniams atnaujinimams
```

**Suderinamumas su Laravel versijomis:**

| Laravel | Mechanizmas | Draiveriai |
|---|---|---|
| 8.x - 9.x | `DB::beforeExecuting` | visi |
| 5.7 - 7.x | pakeista jungties klasė (`Connection::resolverFor`) | mysql, pgsql, sqlite, sqlsrv |
| 5.7 - 7.x | apgaubtas svetimas resolveris | Oracle (`yajra/laravel-oci8`) |

**Oracle senesnėse Laravel versijose:** `yajra/laravel-oci8` registruoja savo
jungties resolverį, kuris atlieka gyvybiškai svarbią konfigūraciją (NLS datų
formatai, dešimtainiai skirtukai, `CURRENT_SCHEMA`). Paketas jo **neperrašo** -
leidžia atlikti visą darbą, o tada perkelia gautą jungties būseną į savo
poklasį per refleksiją. PDO objektas lieka tas pats, tad Oracle seanso
nustatymai galioja toliau. Jei perkėlimas dėl kokios nors priežasties
nepavyktų, grąžinama originali jungtis - projektas veikia normaliai, tik
be `old_values`.

**Našumo kaina:** viena papildoma `SELECT` užklausa kiekvienam
`UPDATE`/`DELETE`. Nustatykite `false`, jei našumas svarbesnis.

**Apribojimai:**
- Senos reikšmės nuskaitomos tik jei `WHERE` sąlygos atpažįstamos
  (`stulpelis = ?` forma). Sudėtingoms sąlygoms (`IN`, subqueries, raw
  `WHERE`) `old_values` nebus.
- Nėra `subject_type`/`subject_id` modelio konteksto - tik SQL sakinys
  ir parametrai.
- Generuoja **žymiai daugiau** įrašų, įskaitant dubliuotus: pakeitimas per
  modelį bus užfiksuotas du kartus (kaip `update` ir kaip `db_update`).
- Jautrūs duomenys parametruose maskuojami tik euristiškai (bcrypt/argon
  hash'ai → `[REDACTED]`, ilgesni nei 500 simbolių → trumpinami). SQL
  lygmenyje neįmanoma patikimai susieti parametro su stulpelio pavadinimu,
  tad jei per `DB::table()` rašomi slaptažodžiai, geriau tas lenteles
  įtraukti į `exclude_query_tables`.

Aukšto dažnio techninės lentelės praleidžiamos per `config/audit.php`:
```php
'exclude_query_tables' => [
    'sessions', 'cache', 'jobs', 'failed_jobs', 'password_resets',
],
```

**Rekomendacija:** naudokite laikinai, kol pertvarkysite kontrolerius
naudoti modelio instancijas, arba nuolat, jei pilnas SQL lygmens
padengimas svarbesnis už žurnalų glaustumą ir `old_values` tikslumą.

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

## Klaidos/išimtys - `LogsExceptions` trait

Laravel neturi standartinio event'o nepagautoms išimtims (skirtingai nuo
Login/Logout/Failed), tad automatinis `error` tipo įvykių fiksavimas reikalauja
vieno papildomo žingsnio projekto `app/Exceptions/Handler.php` faile:

```php
use Vdu\TisLogging\Traits\LogsExceptions;

class Handler extends ExceptionHandler
{
    use LogsExceptions;

    public function report(Throwable $exception)
    {
        $this->logException($exception);
        parent::report($exception);
    }
}
```

Po šio vieno papildymo, **visos** projekto nepagautos išimtys (500 klaidos ir pan.)
automatiškai pateks į `error/error-YYYY-MM-DD.log`, su išimties klase, žinute,
failu ir eilute. Gerbiamas projekto esamas `$dontReport` sąrašas (jei apibrėžtas)
- validacijos/404 klaidos, kurias Laravel numatytai nutildo, taip pat nepateks
į audito žurnalą kaip "error" įvykiai.

**Saugumo pastaba:** pilnas stack trace su funkcijų argumentais SĄMONINGAI
neįtraukiamas (gali turėti slaptažodžių/tokenų) - loginami tik failas ir eilutė.
Pilną trace rasite standartiniame `storage/logs/laravel.log`, jei reikės detalesnei
diagnostikai.

## Failų atsisiuntimai - automatinis middleware

**Nereikia jokio kontrolerio ar `Kernel.php` redagavimo.** Paketas automatiškai
registruoja globalų middleware'į (`LogFileDownloads`), kuris fiksuoja **visus**
failų atsisiuntimus, nepriklausomai nuo to, kokia biblioteka juos sugeneravo:

- `Excel::download(...)` (maatwebsite/excel)
- `PDF::download(...)` (barryvdh/laravel-dompdf)
- `Storage::download(...)`
- `response()->download(...)`, `response()->file(...)`
- tiesiogiai sukurtas `BinaryFileResponse` (net be `Content-Disposition` antraštės)
- bet koks kitas atsakymas su `Content-Disposition` HTTP antrašte

Veikimo principas: `BinaryFileResponse` fiksuojamas **besąlygiškai** (pats šis
tipas jau reiškia "siunčiamas failas"), o kiti `Response` tipai - tik jei turi
`Content-Disposition` antraštę. Užfiksuoja `category: download` įrašą `audit/`
kanale su failo pavadinimu, URL ir turinio tipu.

Galima išjungti, jei nepageidaujama:
```
AUDIT_LOG_DOWNLOADS=false
```

### Naršyklės talpykla ir pasikartojantys kvietimai

Kiekvienam užfiksuotam atsisiuntimui middleware'as prideda `Cache-Control:
no-store` antraštę - be to, pakartotiniai to paties failo atsisiuntimai galėtų
būti aptarnaujami iš naršyklės talpyklos, o auditas apie tai niekada
nesužinotų. Išjungiama per `AUDIT_LOG_PREVENT_DOWNLOAD_CACHING=false`.

Kai kurios naršyklės (pvz. Chrome PDF viewer) taip pat siunčia **dvi**
atskiras užklausas tai pačiai URL vienam vartotojo veiksmui - pirma peržiūrai,
tada, paspaudus atsisiuntimo mygtuką viduje, dar kartą, kad išsaugotų failą.
Serveris negali patikimai atskirti šių dviejų atvejų, tad žinutėje sąžiningai
rašoma **"peržiūrėtas/atsisiųstas"**, o pasikartojantys tos pačios URL
kvietimai per `AUDIT_LOG_DOWNLOAD_DEDUP_SECONDS` (numatytoji - 10 sek.)
sujungiami į **vieną** žurnalo įrašą. Nustatykite `0`, jei norite fiksuoti
kiekvieną kvietimą atskirai.

**Apribojimas:** tai apima tik **atsisiuntimus** (failus su tinkama HTTP
antrašte arba `BinaryFileResponse`). Paprastos duomenų **peržiūros** ekrane
(be failo generavimo) vis tiek reikalauja rankinio `LogsViews` trait
naudojimo - HTTP atsakymas rodant duomenis puslapyje neturi jokio universalaus
požymio "tai prasminga duomenų peržiūra", tad automatinis aptikimas
fundamentaliai neįmanomas.



### Teisių paruošimas serveryje (vienkartinis veiksmas prieš pirmą diegimą)

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
