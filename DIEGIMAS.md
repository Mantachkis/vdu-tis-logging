# VDU TIS Audit Log — pilnas diegimo planas

Paketas: `vdu/tis-logging` v2.17.0
Repo: https://github.com/Mantachkis/vdu-tis-logging

Skirta Laravel 5.7 / 8 projektams su Oracle (`yajra/laravel-oci8`) ir
`zefy/laravel-sso` arba `iffutsius/laravel-sso`.

---

# I DALIS — SERVERIO PARUOŠIMAS

Atliekama **po vieną kartą kiekviename serveryje**, ne kiekvienam projektui.

## 1. Žurnalų katalogas

```bash
mkdir -p /home/logs
groupadd audit-writers
chown root:audit-writers /home/logs
chmod 2775 /home/logs
```

Kiekvienam Linux vartotojui, kurio projektai rašys žurnalus:

```bash
usermod -aG audit-writers studentas
usermod -aG audit-writers sso
usermod -aG audit-writers epasirasymas
```

**Patikrinimas:**
```bash
ls -ld /home/logs
```
Turi rodyti: `drwxrwsr-x ... root audit-writers`

Raidė `s` (ne `x`) reiškia, kad setgid bitas nustatytas — nauji poaplankiai
paveldės grupę, ir skirtingų projektų procesai nesusidurs su teisių
problemomis.

## 2. Composer

```bash
which composer
```

Jei tuščia (VDU serveriuose dažniausiai taip):

```bash
cd /kelias/iki/projekto
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
php -r "unlink('composer-setup.php');"
php composer.phar --version
```

Toliau visur naudojamas `php composer.phar`, ne `composer`.

## 3. GitHub tokenas (neprivaloma)

Reikalingas tik diegiant kelis projektus iš eilės. GitHub riboja
neautentifikuotas API užklausas iki ~60/val. iš vieno IP.

Repo viešas, tad **prieigai tokenas nereikalingas** — tik greičio ribai.

GitHub → Settings → Developer settings → Personal access tokens →
Tokens (classic) → Generate new token. **Jokių scope'ų žymėti nereikia.**

```bash
php composer.phar config --global github-oauth.github.com JŪSŲ_TOKENAS
```

Tokenas saugomas `~/.composer/auth.json` — konkretaus Linux vartotojo
namuose. Kartoti reikia kiekvienam vartotojui, kuris vykdys composer.

---

# II DALIS — KIEKVIENAM PROJEKTUI

## 4. Informacijos surinkimas

```bash
cd /kelias/iki/projekto

php -v
grep '"laravel/framework"' composer.json
grep -n "attemptLogin" app/Http/Controllers/Auth/LoginController.php
grep -rl "XLSX.writeFile\|table_to_book" resources/views/ 2>/dev/null
```

| Rezultatas | Ką reikš |
|---|---|
| PHP 7.1–8.x, Laravel 5.7–9.x | Suderinama |
| `attemptLogin` rastas | Reikės 7 skyriaus |
| `XLSX.writeFile` rastas | Reikės 8 skyriaus |

## 5. composer.json

### 5.1. Repozitorija

Jei `repositories` bloko nėra, pridėkite šakniniame lygyje:

```json
"repositories": [
    {"type": "vcs", "url": "https://github.com/Mantachkis/vdu-tis-logging.git"}
],
```

Jei blokas jau yra (pvz. su SSO paketu), pridėkite antrą įrašą:

```json
"repositories": [
    {"type": "vcs", "url": "https://github.com/iffutsius/laravel-sso.git"},
    {"type": "vcs", "url": "https://github.com/Mantachkis/vdu-tis-logging.git"}
],
```

### 5.2. Priklausomybė

```json
"require": {
    ...
    "vdu/tis-logging": "^2.0"
},
```

### 5.3. allow-plugins (TIK Laravel 5.7 projektams)

Senuose `composer.lock` failuose šios sekcijos nėra, ir naujas Composer
atsisako dirbti su klaida:

> Your composer.lock was generated before the allow-plugins security
> feature was introduced

`config` bloke:

```json
"config": {
    "preferred-install": "dist",
    "sort-packages": true,
    "optimize-autoloader": true,
    "allow-plugins": {
        "kylekatarnls/update-helper": true
    }
},
```

Jei composer skųsis dėl kito plugin'o — jo pavadinimas bus klaidos žinutėje.

## 6. Diegimas

```bash
php composer.phar update vdu/tis-logging
php artisan audit:install
php artisan config:clear
```

**NIEKADA nenaudokite `--with-dependencies`.** Ta komanda atnaujina ir kitus
paketus. Viename projekte taip netyčia buvo pakeltas `nesbot/carbon`, kas
sugriovė naujienlaiškių siuntimą ir kainavo pusdienį diagnostikos.

**Kodėl `update`, ne `require`:** `require` įrašo siaurą apribojimą pagal tuo
metu žinomą versiją ir gali įdiegti senesnę iš `composer.lock`.

Sėkmės požymis išvestyje:
```
Discovered Package: vdu/tis-logging
```

### 6.1. `.env` patikrinimas

```bash
grep "AUDIT_LOG_APP_NAME\|AUDIT_LOG_BASE_PATH" .env
```

Turi būti:
```
AUDIT_LOG_APP_NAME=unikalus-projekto-vardas
AUDIT_LOG_BASE_PATH=/home/logs
```

**Dažna klaida:** jei `.env` kopijuotas iš kito projekto, `APP_NAME` nuklysta,
ir du projektai rašo į tą patį katalogą.

```bash
sed -i 's|AUDIT_LOG_APP_NAME=.*|AUDIT_LOG_APP_NAME=teisingas-vardas|' .env
php artisan config:clear
php artisan audit:install
```

### 6.2. Peržiūrų fiksavimas

```bash
sed -i 's|AUDIT_LOG_PAGE_VIEWS=off|AUDIT_LOG_PAGE_VIEWS=all|' .env
php artisan config:clear
```

### 6.3. Klientinių įvykių endpoint'as

Tik jei projekte yra SheetJS eksportų:

```bash
sed -i 's|AUDIT_LOG_CLIENT_EVENTS=false|AUDIT_LOG_CLIENT_EVENTS=true|' .env
php artisan config:clear
php artisan route:clear
```

## 7. Login kontroleris

SSO brokeris nenaudoja `Auth::attempt()`, tad Laravel niekada nesužino apie
nepavykusį bandymą. Sėkmingi prisijungimai fiksuojami automatiškai.

### Variantas A — standartinis SSO brokeris

Buvo:

```php
protected function attemptLogin(Request $request)
{
    $broker = new \Zefy\LaravelSSO\LaravelSSOBroker;
    $credentials = $this->credentials($request);

    return $broker->login($credentials[$this->username()], $credentials['password']);
}
```

Tampa:

```php
protected function attemptLogin(Request $request)
{
    $broker = new \Zefy\LaravelSSO\LaravelSSOBroker;
    $credentials = $this->credentials($request);

    if ($broker->login($credentials[$this->username()], $credentials['password'])) {
        return true;
    }

    \Vdu\TisLogging\Facades\AuditLog::security(
        'login_failed',
        'Nepavykes prisijungimo bandymas ('.$request->input($this->username()).')',
        ['user_identifier' => $request->input($this->username())]
    );

    return false;
}
```

Jei projektas naudoja `Iffutsius\LaravelSSO\LaravelSSOBroker` — pakeiskite
namespace.

### Variantas B — dvigubas guard'as (espUser + SSO)

```php
protected function attemptLogin(Request $request)
{
    $user = Users::where('email', $request->username)->first();

    if ($user) {
        if ($user->is_verified != 1) {
            \Vdu\TisLogging\Facades\AuditLog::warning(
                'login_blocked',
                'Bandymas prisijungti prie nepatvirtintos paskyros ('.$request->username.')',
                [
                    'user_id' => $user->id,
                    'user_identifier' => $request->username,
                    'context' => ['guard' => 'espUser'],
                ]
            );

            return back()->with('error', 'Norint prisijungti, turite patvirtinti savo el.pasto adresa.');
        }

        Auth::shouldUse('espUser');
        Auth::guard('espUser')->login($user);

        return Auth::guard('espUser')->check();
    }

    $broker = new \Zefy\LaravelSSO\LaravelSSOBroker;
    $credentials = $this->credentials($request);

    if ($broker->login($credentials[$this->username()], $credentials['password'])) {
        $user = User::where('username', $credentials['username'])->first();
        Auth::guard('web')->login($user);

        return Auth::guard('web')->check();
    }

    \Vdu\TisLogging\Facades\AuditLog::security(
        'login_failed',
        'Nepavykes prisijungimo bandymas ('.$request->username.')',
        [
            'user_identifier' => $request->username,
            'context' => ['attempted_guards' => ['espUser', 'web/sso']],
        ]
    );

    return false;
}
```

### Slaptažodžio atkūrimas (jei yra)

```php
// Sekminga uzklausa:
\Vdu\TisLogging\Facades\AuditLog::security(
    'password_reset_requested',
    'Slaptazodzio atkurimas uzklaustas ('.$email.')',
    ['user_id' => $user->id, 'user_identifier' => $email]
);

// Nepatvirtinta paskyra:
\Vdu\TisLogging\Facades\AuditLog::warning(
    'password_reset_blocked',
    'Slaptazodzio atkurimo bandymas nepatvirtintai paskyrai ('.$email.')',
    ['user_id' => $user->id, 'user_identifier' => $email]
);

// Neegzistuojantis el. pastas (galimas zvalgymo bandymas):
\Vdu\TisLogging\Facades\AuditLog::warning(
    'password_reset_unknown_user',
    'Slaptazodzio atkurimo bandymas neegzistuojanciam vartotojui ('.$email.')',
    ['user_identifier' => $email]
);
```

```bash
php artisan config:clear
```

## 8. Klientinės pusės eksportai

SheetJS sukuria failą naršyklėje iš DOM turinio — į serverį neišsiunčiama
jokia užklausa, tad joks serverio mechanizmas to pamatyti negali.

```bash
grep -rn "XLSX.writeFile" resources/views/
```

Kiekvienoje rastoje vietoje. Buvo:

```js
document.getElementById('exportExcel').addEventListener('click', function () {
    const table = document.getElementById('sortTable');
    const workbook = XLSX.utils.table_to_book(table, {sheet: "Sheet1"});
    XLSX.writeFile(workbook, 'reports.xlsx');
});
```

Tampa:

```js
document.getElementById('exportExcel').addEventListener('click', async function () {
    const table = document.getElementById('sortTable');

    await fetch('/audit/client-event', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
        },
        body: JSON.stringify({
            category: 'export',
            description: 'Excel eksportas: reports.xlsx',
            context: { filename: 'reports.xlsx', rows: table.rows.length },
        }),
    }).catch(() => {});

    const workbook = XLSX.utils.table_to_book(table, {sheet: "Sheet1"});
    XLSX.writeFile(workbook, 'reports.xlsx');
});
```

Svarbu:
- `async` prieš `function` — be jo `await` sukels sintaksės klaidą, ir
  mygtukas nustos veikti
- `.catch(() => {})` — eksportas turi įvykti net jei pranešimas nepavyko
- `'{{ csrf_token() }}'` veikia Blade faile; jei JS atskirame `.js` faile,
  naudokite `document.querySelector('meta[name="csrf-token"]').content`

## 9. Oracle jungties patikrinimas (KRITIŠKAI SVARBU)

Paketas apgaubia `yajra/laravel-oci8` jungtį, kad galėtų nuskaityti senas
reikšmes. Jei Oracle NLS nustatymai dingtų, sugriūtų datų formatai **visame
projekte**, ne tik audite.

Laikina route į `routes/web.php`:

```php
Route::get('/test-db', function () {
    return response()->json(DB::select('select sysdate as dt, 1.5 as nr from dual'));
});
```

Atidarykite `/test-db`.

**Laukiamas rezultatas:**
```json
[{"dt":"2026-09-10 15:28:54","nr":"1.5"}]
```

| Tikrinama | Gerai | Blogai |
|---|---|---|
| Data | `2026-09-10 15:28:54` | `10-SEP-26` |
| Skaičius | `1.5` | `1,5` |

**Jei kas nors ne taip:**
```bash
echo "AUDIT_LOG_CAPTURE_OLD_VALUES=false" >> .env
php artisan config:clear
```

Tai išjungia tik senų reikšmių mechanizmą — auditas veikia toliau.

**Ištrinkite route'ą po patikrinimo.**

## 10. Galutinis patikrinimas

Atlikite penkis veiksmus per naršyklę: prisijunkite, atidarykite puslapį,
pakeiskite įrašą, atsisiųskite failą, atsijunkite.

```bash
tail -30 /home/logs/{APP_NAME}/audit/audit-$(date +%Y-%m-%d).log \
  | grep -o '"category":"[^"]*"' | sort | uniq -c
```

Laukiama: `login`, `view`, `update` arba `db_update`, `download`, `logout`.

Nepavykęs prisijungimas — bandykite su neteisingu slaptažodžiu:

```bash
grep login_failed /home/logs/{APP_NAME}/audit/audit-$(date +%Y-%m-%d).log | tail -1
```

## 11. Švarinimas

```bash
grep -n "test-db\|opcache-clear\|test-klaida\|test-audit" routes/web.php
```

Turi nieko negrąžinti.

---

# III DALIS — KO DARYTI NEREIKIA

Pridėję šiuos dalykus rankomis gausite **dubliuotus įrašus**:

| Dalykas | Kodėl nereikia |
|---|---|
| `use Auditable;` modeliuose | Visi Eloquent modeliai audituojami automatiškai |
| `auditExclude()` su `pers_code` | Blokuojamas globaliai pakete |
| `LogsExceptions` trait `Handler.php` | Handler apgaubiamas automatiškai (v2.16.0+) |
| `Kernel.php` middleware registravimas | Registruojama per ServiceProvider |
| `$this->logView()` kontroleriuose | Jei įjungtas `AUDIT_LOG_PAGE_VIEWS` |
| `query_params` sąrašas | Numatytieji šablonai apima `*Id`, `*_id`, `ckods` |

**Jei ankstesniuose projektuose jau pridėjote `LogsExceptions`** — pašalinkite:

```php
class Handler extends ExceptionHandler
{
    // use Vdu\TisLogging\Traits\LogsExceptions;   ← pašalinti

    public function report(Exception $exception)
    {
        // $this->logException($exception);        ← pašalinti
        parent::report($exception);
    }
}
```

---

# IV DALIS — ŽINOMOS KLIŪTYS

| Simptomas | Priežastis | Sprendimas |
|---|---|---|
| `composer: command not found` | Nėra `PATH` | `php composer.phar` |
| `Could not authenticate against github.com` | GitHub greičio riba | I dalies 3 skyrius arba palaukti 1 val. |
| `allow-plugins` klaida | Senas `composer.lock` | 5.3 skyrius |
| `git was not found` | Serveryje nėra git | Nenaudokite `--prefer-source` |
| Įdiegta senesnė versija | `require` įrašė siaurą apribojimą | `php composer.phar update vdu/tis-logging` |
| Pakeitimai neįsigalioja | OPcache | Laikina `/opcache-clear` route |
| `.env` reikšmė ignoruojama | Config cache | `php artisan config:clear` |
| `.env` ignoruojama ir po clear | Shell environment kintamasis | `unset AUDIT_LOG_APP_NAME` |
| Žurnalas rašo į kito projekto katalogą | `AUDIT_LOG_APP_NAME` nuklydo | 6.1 skyrius |
| `route:list` lūžta | PSR-4 klaida (failo pavadinimo registras) | Pervadinti failą, `dump-autoload` |
| Blade pakeitimai nematomi | View cache | `php artisan view:clear` |
| Naršyklė nesiunčia užklausos | Talpykla | F12 → Network → Disable cache |

### OPcache išvalymo route

```php
Route::get('/opcache-clear', function () {
    if (function_exists('opcache_reset')) { opcache_reset(); return 'OPcache isvalytas'; }
    return 'OPcache nepasiekiamas';
});
```

Atidarykite naršyklėje, tada **ištrinkite**.

---

# V DALIS — ATNAUJINIMAS

```bash
cd /kelias/iki/projekto
php composer.phar update vdu/tis-logging
php artisan vendor:publish --tag=audit-config --force
php artisan audit:install
php artisan config:clear
```

- `--force` perrašo `config/audit.php`. **Jei buvote jį redagavę** (pvz.
  `exclude_models`, `query_params`) — pirma pasidarykite kopiją.
- `audit:install` prideda naujus `.env` kintamuosius, nepaliesdamas esamų.

Jei veikia queue darbuotojas:

```bash
php artisan queue:restart
```

---

# VI DALIS — KĄ FIKSUOJA

## `audit/` kanalas

| Kategorija | Kaip |
|---|---|
| `login`, `logout` | Automatiškai |
| `login_failed` | Rankinis (7 skyrius) |
| `create`, `update`, `delete` | Automatiškai, visi modeliai |
| `db_insert`, `db_update`, `db_delete` | Automatiškai, `DB::table()` |
| `view` | Automatiškai (`AUDIT_LOG_PAGE_VIEWS`) |
| `download` | Automatiškai |
| `mail_sent` | Automatiškai |
| `export` | Rankinis (8 skyrius) |

## `error/` kanalas

| Kategorija | Kaip |
|---|---|
| `exception` | Automatiškai |
| `login_blocked` | Rankinis |
| `password_reset_blocked` | Rankinis |

## Įrašo laukai

`occurred_at`, `event_type`, `category`, `user_id`, `user_identifier`,
`ip_address`, `user_agent`, `subject_type`, `subject_id`, `old_values`,
`new_values`, `context`.

## Jautrūs laukai

Niekada nepatenka: `password`, `pass`, `passwd`, `pwd`, `remember_token`,
`api_token`, `pers_code`.

`ckods` fiksuojamas sąmoningai — tai darbuotojo kodas, ne asmens duomuo.

---

# VII DALIS — STEBĖJIMAS PO DIEGIMO

Po savaitės:

```bash
du -sh /home/logs/{APP_NAME}/
wc -l /home/logs/{APP_NAME}/audit/audit-$(date +%Y-%m-%d).log
grep -c '"category":"view"' /home/logs/{APP_NAME}/audit/audit-$(date +%Y-%m-%d).log
```

Jei peržiūros sudaro 90%+ įrašų ir žurnalai auga per greitai:

```bash
sed -i 's|AUDIT_LOG_PAGE_VIEWS=all|AUDIT_LOG_PAGE_VIEWS=whitelist|' .env
```

Ir `config/audit.php`:

```php
'log_page_views' => [
    'mode' => env('AUDIT_LOG_PAGE_VIEWS', 'off'),
    'routes' => [
        'admin/userInfoList',
        'admin/userCompetenceView/*',
        'user/person_request_view/*',
    ],
],
```

Kuriuos maršrutus rinktis, parodys:

```bash
grep '"category":"view"' /home/logs/{APP_NAME}/audit/audit-$(date +%Y-%m-%d).log \
  | grep -o '"url":"[^"]*"' | sort | uniq -c | sort -rn | head -30
```

---

# VIII DALIS — RETENCIJA

`AUDIT_LOG_RETENTION_DAYS=90` — po 90 dienų seni failai **automatiškai
ištrinami**.

```bash
sed -i 's|AUDIT_LOG_RETENTION_DAYS=90|AUDIT_LOG_RETENTION_DAYS=1095|' .env
php artisan config:clear
```

`0` = niekada netrinti automatiškai (tada archyvavimą reikia tvarkyti
`logrotate` ar rankiniu būdu).
