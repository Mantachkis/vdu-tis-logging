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

**Įjungta pagal nutylėjimą**, nes senesniuose projektuose `DB::table()`
naudojimas yra dažna praktika, o be šio mechanizmo tokie pakeitimai apskritai
nebūtų fiksuojami.

```
AUDIT_LOG_QUERIES=true    # numatytoji
```

### Dubliavimosi su Eloquent nėra

`$model->save()` sukelia du nepriklausomus įvykius - SQL užklausą ir Eloquent
`updated` event'ą. Paketas automatiškai praleidžia SQL įrašą, jei tos pačios
lentelės pakeitimą per tą pačią užklausą jau užfiksavo Eloquent mechanizmas
(jo įrašas vertingesnis - turi `subject_type`, `subject_id` ir modelio lygmens
reikšmes).

`DB::table()` pakeitimai, kurių Eloquent nemato, fiksuojami kaip anksčiau -
būtent dėl jų šis mechanizmas ir egzistuoja.

```
AUDIT_LOG_SKIP_ELOQUENT_DUPLICATES=true    # numatytoji
```

**Techninė detalė:** kadangi Eloquent event'as suveikia PO SQL užklausos, SQL
įrašas trumpam atidedamas - iki kitos užklausos arba užklausos pabaigos. Įrašo
`occurred_at` fiksuojamas įvykio, ne rašymo momentu, tad chronologija išlieka
teisinga.

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

## Queue darbai

Queue darbai (naujienlaiškių siuntimas, eksportų generavimas, ataskaitos)
vykdomi **atskirame procese**, kuriame nėra nei sesijos, nei HTTP užklausos -
tad `Auth::user()` ten grąžina `null`, o `Request::ip()` rodo darbuotojo, ne
realaus vartotojo IP.

Be jokios konfigūracijos paketas išsaugo vartotojo kontekstą darbo payload'e
įstatymo momentu ir atkuria jį darbuotojo procese:

```json
{
  "message": "Išsiųsta laiškų suvestinė (482 laiškų): Naujienlaiškis",
  "context": {
    "user_id": 33131,
    "user_identifier": "mantas.garliauskas@vdu.lt",
    "ip_address": "193.219.38.75"
  }
}
```

Be šio mechanizmo tas pats įrašas turėtų `user_id: null` - žurnale liktų
„kažkas išsiuntė 482 laiškus" be autoriaus.

Pirmenybės tvarka: eksplicitiškai perduoti `$data` laukai → realiai
prisijungęs vartotojas → queue kontekstas. Tad jei darbuotojo procese kažkodėl
liktų senas kontekstas, realus vartotojas visada turės pirmenybę.

Kontekstas išvalomas darbui pasibaigus (`Queue::after`) ir jam sugedus
(`Queue::failing`) - kitaip ilgai gyvuojantis darbuotojas priskirtų tą patį
vartotoją ir kitų žmonių darbams.

```
AUDIT_LOG_QUEUE_CONTEXT=true    # numatytoji
```

Reikalauja Laravel 5.7+ (`Queue::createPayloadUsing`). Senesnėse versijose
tyliai praleidžiama.

## Jautrūs laukai

Trys lygiai, kuriuose laukai blokuojami:

**1. Paketo globalus sąrašas** (`config/audit.php` → `exclude`) - taikomas
visiems modeliams:

```php
'exclude' => [
    'password', 'pass', 'passwd', 'pwd',
    'remember_token', 'api_token',
    'pers_code',   // asmens kodas
],
```

`pers_code` blokuojamas globaliai, nes VDU sistemose šis stulpelis kartojasi
keliose lentelėse - taip nereikia kiekviename modelyje rašyti `auditExclude()`.

`ckods` sąmoningai **NEblokuojamas** - tai darbuotojo kodas, vidinis
identifikatorius, o ne asmens duomuo. Auditui jis naudingas: leidžia susieti
veiksmą su konkrečiu darbuotoju. Jei jūsų sistemoje `ckods` reiškia ką kita ir
yra jautrus, įtraukite jį į `exclude`.

**2. Modelio sąrašas** - domeno-specifiniams laukams tame viename modelyje:

```php
class Invoice extends Model
{
    public function auditExclude(): array
    {
        return ['internal_notes', 'bank_account'];
    }
}
```

Veikia ir su `Auditable` trait, ir be jo (globaliame režime).

**3. SQL lygmens automatinis filtravimas** - bcrypt/argon hash'ai keičiami į
`[REDACTED]`, base64 paveikslėliai į `[BASE64_IMAGE]`, ilgesnės nei 500
simbolių reikšmės trumpinamos. Taikoma automatiškai, be konfigūracijos.

**Prieš diegiant į naują projektą** peržiūrėkite audituojamų modelių stulpelius
- paketo sąrašas yra saugus startas, ne garantija, kad visi jautrūs laukai bus
atpažinti.

## Klientinės pusės veiksmai (SheetJS, print, iškarpinė)

Kai kurie veiksmai vyksta **vien naršyklėje** ir nesukelia jokios HTTP
užklausos - serveris apie juos fiziškai nieko nesužino:

```js
XLSX.writeFile(workbook, 'reports.xlsx');   // SheetJS - failas iš DOM
window.print();
navigator.clipboard.writeText(...);
```

Toks Excel eksportas suformuojamas iš jau įkelto puslapio turinio ir
išsaugomas tiesiai į vartotojo diską. **Jokia serverio pusės priemonė to
pagauti negali** - vienintelis būdas yra, kad pati naršyklė praneštų.

Įjunkite endpoint'ą:
```
AUDIT_LOG_CLIENT_EVENTS=true
```

Tada JS kode pridėkite vieną `fetch` kvietimą prieš eksportą:

```js
document.getElementById('exportExcel').addEventListener('click', async function () {
    const table = document.getElementById('sortTable');
    table.querySelectorAll('button').forEach(btn => btn.remove());

    // Pranešame serveriui PRIEŠ eksportą
    await fetch('/audit/client-event', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({
            category: 'export',
            description: 'Excel eksportas: reports.xlsx',
            context: { filename: 'reports.xlsx', rows: table.rows.length },
        }),
    }).catch(() => {});   // eksportas turi įvykti net jei pranešimas nepavyko

    const workbook = XLSX.utils.table_to_book(table, {sheet: "Sheet1"});
    XLSX.writeFile(workbook, 'reports.xlsx');
});
```

**Patikimumo pastaba:** klientinės pusės pranešimai nėra tokie patys
patikimi kaip serverio užfiksuoti įvykiai - technikai išmanantis vartotojas
gali jų neišsiųsti arba suklastoti. Todėl kiekvienas toks įrašas žymimas
`"source": "client"`, kad auditą peržiūrintis asmuo matytų skirtumą.
Serverio pusėje užfiksuoti įvykiai lieka autoritetingas šaltinis.

**Apsaugos:** kategorijos ribojamos baltuoju sąrašu
(`allowed_categories` - pagal nutylėjimą `export`, `print`, `view`, `copy`),
aprašymo ilgis ir konteksto raktų kiekis apriboti, taikomas
`throttle:60,1` - kad klientas negalėtų užtvindyti žurnalo.

## Puslapių peržiūros - automatinis middleware

Alternatyva rankiniam `LogsViews` naudojimui - fiksuoja peržiūras **be jokio
kontrolerių redagavimo**. Trys režimai per `.env`:

```
AUDIT_LOG_PAGE_VIEWS=off         # numatytoji - tik rankinis logView()
AUDIT_LOG_PAGE_VIEWS=whitelist   # tik nurodyti jautrūs maršrutai
AUDIT_LOG_PAGE_VIEWS=all         # kiekvienas puslapio atidarymas
```

### `whitelist` režimas (rekomenduojamas)

`config/audit.php`:
```php
'log_page_views' => [
    'mode' => 'whitelist',
    'routes' => [
        'admin/userInfoList',
        'admin/userCompetenceView/*',
        'admin/payments_list/*',
        'user/person_request_view/*',
    ],
],
```

Route parametrai (`{id}`) automatiškai įrašomi kaip `subject_id` - žurnale
matysite ne tik „atidarė puslapį", bet ir **kieno** duomenys peržiūrėti.

### `all` režimas - įspėjimas

Fiksuoja kiekvieną atidarymą, įskaitant navigaciją, AJAX ir atgal/pirmyn.
Vienas administratorius per dieną gali sugeneruoti tūkstančius įrašų. Dėl to:
- rasti tikrai svarbų įvykį tampa sunkiau,
- BDAR duomenų minimizavimo principą sunkiau pagrįsti.

Naudokite tik jei reglamentas aiškiai to reikalauja.

### POST-peržiūros ir AJAX

Kai kurios sistemos naudoja `POST` ne duomenų keitimui, o **peržiūrai su
filtrais**. Pagal nutylėjimą `POST` nefiksuojamas kaip peržiūra - kitaip
kiekvienas formos išsaugojimas atsirastų žurnale du kartus (kaip `update` iš
modelio mechanizmo ir kaip `view`). Tokius maršrutus nurodykite atskirai:

```php
'post_routes' => [
    'user/personal_studies',   // POST, bet tik parodo duomenis
],
```

Analogiškai su JSON/AJAX - dauguma jų techniniai, bet kai kurie atiduoda
asmens duomenis (server-side DataTables, autocomplete su vartotojų sąrašais):

```php
'json_routes' => [
    'admin/userInfoList/data',
],
```

Abu sąrašai veikia **visuose režimuose** - `whitelist` režimu jų nereikia
dubliuoti pagrindiniame `routes` sąraše.

**Atsargiai:** į `post_routes` netraukite maršrutų, kurie realiai keičia
duomenis - gausite dubliuotus įrašus.

### `auto` režimas - be jokių sąrašų

Jei nenorite rankiniu būdu vardinti POST maršrutų:

```
AUDIT_LOG_POST_VIEWS=auto
```

Tada POST fiksuojamas kaip peržiūra **tik jei** per tą užklausą nebuvo
užfiksuota jokio reikšmingo veiksmo (duomenų pakeitimo ar laiško
išsiuntimo). Middleware veikia po kontrolerio, tad iki to momento visi
pakeitimai jau užfiksuoti - sprendimas patikimas.

**Niuansas:** jei tas pats maršrutas kartais keičia duomenis, o kartais ne,
jis kartais atsiras kaip `update`, kartais kaip `view`. Tai teisingai
atspindi, kas realiai įvyko, bet žurnalo skaitytojui gali pasirodyti
nenuoseklu.

`post_routes` sąrašas veikia ir `auto` režime - jei norite maršrutą fiksuoti
kaip peržiūrą **visada**, nepaisant automatinio sprendimo.

## El. laiškai

Fiksuojama **automatiškai**, be kontrolerių redagavimo - per Laravel
`MessageSent` event'ą. Laiško išsiuntimas nekeičia DB, tad modelio ir SQL
mechanizmai jo nepamato, nors tai reikšmingas veiksmas su asmens duomenimis.

Kategorija: `mail_sent`. Fiksuojama tema, visi gavėjai (`to`, `cc`, `bcc`)
ir bendras jų skaičius.

```
AUDIT_LOG_MAIL=true                 # numatytoji
AUDIT_LOG_MAIL_MAX_RECIPIENTS=0     # 0 = visi adresai
```

**BDAR pastaba:** gavėjų el. paštai yra asmens duomenys. Jei naujienlaiškiai
siunčiami tūkstančiams gavėjų, apsvarstykite `max_recipients` ribą - kitaip
vienas žurnalo įrašas gali turėti labai daug asmens duomenų. Nustačius, pvz.,
`50`, bus fiksuojami pirmi 50 adresų, o likusieji pakeisti į „... ir dar N".

### Masiniai siuntimai cikle

Naujienlaiškiai dažnai siunčiami taip:

```php
foreach ($subscribers as $subscriber) {
    Mail::to($subscriber->email)->send(new Newsletter($content));
}
```

Kiekvienas siuntimas yra **atskiras** `MessageSent` event'as, tad 500 gavėjų
duotų 500 beveik identiškų žurnalo įrašų. Todėl veikia riba:

```
AUDIT_LOG_MAIL_MAX_INDIVIDUAL=20    # numatytoji; 0 = be ribos
```

Pirmi 20 laiškų fiksuojami **atskirai** (išsaugoma detali informacija apie
tipinius atvejus), o viskas virš ribos sukaupiama ir užklausos pabaigoje
įrašoma **viena suvestine** su likusių gavėjų sąrašu:

```json
{
  "message": "Išsiųsta laiškų suvestinė (482 laiškų): Naujienlaiškis",
  "context": {
    "category": "mail_sent",
    "context": {
      "subject": "Naujienlaiškis",
      "to": ["a@vdu.lt", "b@vdu.lt", "..."],
      "recipients_total": 482,
      "summary": true
    }
  }
}
```

Suvestinės grupuojamos **pagal temą** - jei per vieną užklausą siunčiami
skirtingi laiškai, gausite atskirą suvestinę kiekvienai temai.

Suvestinė rašoma per `register_shutdown_function()`, tad veikia ir CLI
kontekste (artisan komandos, queue darbuotojai), kur jokio HTTP middleware
nėra.

**Tarpinis rašymas.** Masinis siuntimas sinchroniškai gali nutrūkti (PHP
`max_execution_time`, web serverio Gateway Timeout, atminties riba) - tada
suvestinė, rašoma tik proceso pabaigoje, būtų prarasta kartu su visais
sukauptais duomenimis. Todėl suvestinė įrašoma kas `N` laiškų:

```
AUDIT_LOG_MAIL_SUMMARY_FLUSH=50    # numatytoji; 0 = tik proceso pabaigoje
```

Nutrūkus procesui prarandama tik paskutinė, nebaigta grupė.

**Rekomendacija dėl masinių siuntimų.** Jei siunčiate šimtams gavėjų, geriau
naudoti eilę - tai išsprendžia ir timeout'ą, ir audito pilnumą:

```php
Mail::to($email->email)->queue(new Newsletter($newsletter));
```

Vartotojo kontekstas eilėje išsaugomas automatiškai (žr. „Queue darbai"), tad
žurnale ir toliau matysite, kas inicijavo siuntimą.

### Server-side DataTables - fiksuojama automatiškai

Kai lentelė pildoma per AJAX (`yajra/laravel-datatables` `serverSide` režimu),
puslapis įkeliamas tuščias, o realūs asmens duomenys atiduodami atskira JSON
užklausa. Fiksuojant tik puslapio atidarymą, auditas parodytų „atidarė
vartotojų sąrašą", bet ne tai, kad realiai buvo atiduoti 500 asmenų duomenys.

**Jokio maršrutų sąrašo nereikia** - DataTables atsakymai atpažįstami pagal
struktūrą (`draw`, `recordsTotal`, `recordsFiltered`, `data` laukai), kuri yra
standartizuota specifikacijos dalis:

```json
{
  "message": "Peržiūrėti duomenys (DataTables): /admin/users/data",
  "context": {
    "category": "view",
    "context": {
      "source": "datatables",
      "records_returned": 2,
      "records_total": 1847,
      "records_filtered": 2,
      "search": "Jonaitis",
      "page": 1
    }
  }
}
```

Paieškos frazė auditui ypač vertinga - „administratorius ieškojo 'Jonaitis'"
pasako daugiau nei „atidarė vartotojų sąrašą".

Veikia visuose režimuose (išskyrus `off`), nes tai realus asmens duomenų
atidavimas. Išjungiama:

```
AUDIT_LOG_DETECT_DATATABLES=false
```

**Sujungimas:** DataTables siunčia užklausą kiekvienam lapo perėjimui,
rikiavimui ir net kiekvienam paieškos simboliui. Užklausos su tuo pačiu URL ir
tais pačiais parametrais sujungiamos per `AUDIT_LOG_DATATABLES_DEDUP_SECONDS`
(numatytoji 5 sek.) langą, tad vienas paieškos veiksmas duoda vieną įrašą, o ne
dešimtis. `draw` parametras į raktą neįtraukiamas, nes jis kinta kiekvienai
užklausai.

### Kiti AJAX endpoint'ai ir modalai

Ne-DataTables AJAX užklausoms, kurios atiduoda asmens duomenis, naudokite
`json_routes` sąrašą - jis veikia nepriklausomai nuo DataTables aptikimo.

**Modalai.** Jei modalas duomenis gauna per AJAX, tai reali asmens duomenų
peržiūra ir tokį endpoint'ą reikia įtraukti:

```js
$('#editModal').on('show.bs.modal', function () {
    $.get('/admin/person/' + id, function (data) { ... });   // ← reikia įtraukti
});
```

```php
'json_routes' => [
    'admin/person/*',
],
```

Bet jei modalas duomenis skaito iš `data-*` atributų, jau įrašytų puslapyje
renderinimo metu, **įtraukti nieko nereikia**:

```html
<button data-id="{{ $item->id }}" data-semester="{{ $item->semester }}">
```

Jokios užklausos į serverį nevyksta - tie duomenys jau buvo atiduoti atidarant
puslapį, o tas atidarymas jau užfiksuotas.

**Kaip surasti tokius endpoint'us naujame projekte:** naršyklėje F12 → Network →
XHR, atidarykite kelis modalus ir puslapius su lentelėmis. Jei atsiranda
užklausų, grąžinančių JSON su asmens duomenimis - jų URL įtraukite į
`json_routes`.

### Kas nefiksuojama visais režimais

`POST`/`PUT`/`DELETE` (nebent išvardinti `post_routes`), atsisiuntimai (juos
padengia `LogFileDownloads`), JSON atsakymai (nebent išvardinti `json_routes`),
klaidų puslapiai (ne 2xx), ir maršrutai iš `exclude` sąrašo. `exclude` turi
pirmenybę prieš visus baltuosius sąrašus.

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
