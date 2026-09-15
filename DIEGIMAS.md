# VDU TIS Audit Log — diegimo kontrolinis sąrašas

Skirta `vdu/tis-logging` diegimui į Laravel 5.7 / 8 projektus su Oracle.

---

## A. Vienkartinis darbas KIEKVIENAM SERVERIUI

Atliekama po vieną kartą serveryje, ne kiekvienam projektui.

### A1. Žurnalų katalogas ir teisės

```bash
mkdir -p /home/logs
groupadd audit-writers
chown root:audit-writers /home/logs
chmod 2775 /home/logs
```

Kiekvienam Linux vartotojui, kurio projektai rašys žurnalus:

```bash
usermod -aG audit-writers <vartotojas>
```

`chmod 2775` setgid bitas svarbus — nauji poaplankiai paveldi grupę, tad
skirtingų projektų procesai nesusidurs su teisių problemomis.

**Patikrinimas:**
```bash
ls -ld /home/logs        # turi rodyti drwxrwsr-x root audit-writers
```

### A2. GitHub tokenas (composer greičio ribai)

Be jo `composer` be autentifikacijos ribojamas iki ~60 užklausų per valandą iš
vieno IP — diegiant kelis projektus riba pasiekiama greitai.

GitHub → Settings → Developer settings → Personal access tokens → classic.
Viešam repo **jokių scope'ų žymėti nereikia**.

```bash
php composer.phar config --global github-oauth.github.com TOKENAS
```

Tokenas saugomas `~/.composer/auth.json` — **konkretaus Linux vartotojo**
namuose, tad kartoti reikia kiekvienam vartotojui, kuris vykdys composer.

### A3. Composer (jei serveryje nėra)

```bash
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
php -r "unlink('composer-setup.php');"
```

Daugelyje VDU serverių `composer` nėra `PATH`, tad visur naudojamas
`php composer.phar`.

---

## B. KIEKVIENAM PROJEKTUI

### B1. Pasiruošimas — surinkite informaciją PRIEŠ diegdami

```bash
cd /kelias/iki/projekto
php -v                                    # PHP versija
grep '"laravel/framework"' composer.json  # Laravel versija
ls composer.phar                          # ar yra composer
grep -n -A25 "function attemptLogin" app/Http/Controllers/Auth/LoginController.php
grep -rc "DB::table" app/Http/Controllers/ | grep -v ":0" | wc -l
```

Paskutinės dvi komandos parodo, kiek rankinio darbo reikės (žr. B6, B7).

### B2. composer.json

Pridėkite `repositories` bloką (jei jo nėra) ir priklausomybę:

```json
"repositories": [
    {"type": "vcs", "url": "https://github.com/Mantachkis/vdu-tis-logging.git"}
],
"require": {
    "vdu/tis-logging": "^2.0"
}
```

**Laravel 5.7 projektams** dažnai reikia ir `allow-plugins` — senuose
`composer.lock` failuose šios sekcijos nėra, ir naujas Composer atsisako
dirbti:

```json
"config": {
    "allow-plugins": {
        "kylekatarnls/update-helper": true
    }
}
```

### B3. Diegimas

```bash
php composer.phar update vdu/tis-logging
php artisan audit:install
php artisan config:clear
```

**NIEKADA nenaudokite `--with-dependencies`.** Ta komanda atnaujina ir kitus
paketus — vieno projekto atveju taip netyčia buvo pakeltas `nesbot/carbon`,
kas sugriovė naujienlaiškių siuntimą ir kainavo pusdienį diagnostikos.

Jei `require` grąžina senesnę versiją nei naujausia, `update` tai ištaiso —
`require` įrašo siaurą apribojimą pagal tuo metu žinomą versiją.

### B4. `.env` patikrinimas

`audit:install` prideda visus kintamuosius, bet **dvi reikšmes reikia
patikrinti rankomis**:

```bash
grep "AUDIT_LOG_APP_NAME\|AUDIT_LOG_BASE_PATH" .env
```

- `AUDIT_LOG_APP_NAME` — turi būti unikalus kiekvienam projektui
  (tai poaplankio pavadinimas `/home/logs/`)
- `AUDIT_LOG_BASE_PATH` — `/home/logs`

**Dažna klaida:** jei `.env` kopijuotas iš kito projekto, `APP_NAME` reikšmė
nuklysta, ir du projektai rašo į tą patį katalogą.

### B5. Oracle jungties patikrinimas (KRITIŠKAI SVARBU)

Paketas apgaubia `yajra/laravel-oci8` jungtį, kad galėtų nuskaityti senas
reikšmes. Būtina įsitikinti, kad NLS nustatymai išliko.

Laikina route į `routes/web.php`:

```php
Route::get('/test-db', function () {
    return response()->json(DB::select('select sysdate as dt, 1.5 as nr from dual'));
});
```

Atidarykite `/test-db`. Laukiamas rezultatas:

```json
[{"dt":"2026-09-10 15:28:54","nr":"1.5"}]
```

- Data `YYYY-MM-DD HH:MM:SS` formatu, ne `10-SEP-26` → `NLS_DATE_FORMAT` OK
- Skaičius su tašku `1.5`, ne kableliu `1,5` → `NLS_NUMERIC_CHARACTERS` OK

**Jei kas nors ne taip:**
```bash
echo "AUDIT_LOG_CAPTURE_OLD_VALUES=false" >> .env
php artisan config:clear
```

Tai išjungia tik senų reikšmių mechanizmą — visa kita veikia toliau.

**Ištrinkite route'ą po patikrinimo.**

### B6. Login kontroleris

Priklauso nuo to, ką parodė B1.

**Variantas A — standartinis `zefy/laravel-sso`:**

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

**Variantas B — dvigubas guard'as (espUser + SSO):** reikia trijų atskirų
kvietimų — nepatvirtintai paskyrai, bendram nepavykimui ir slaptažodžio
atkūrimui. Žr. README skyrių apie custom auth.

**Kodėl to reikia:** SSO brokeris nenaudoja `Auth::attempt()`, tad Laravel
niekada nesužino apie nepavykusį bandymą ir `Failed` event'o nemeta.
Sėkmingi prisijungimai fiksuojami automatiškai.

### B7. Klientinės pusės eksportai

Jei projekte yra SheetJS (`XLSX.writeFile`) ar panašūs naršyklėje vykstantys
eksportai:

```bash
grep -rn "XLSX.writeFile\|table_to_book" resources/views/ | head
```

Kiekvienoje rastoje vietoje pridėkite `fetch` kvietimą prieš eksportą —
pavyzdys README skyriuje „Klientinės pusės veiksmai".

### B8. Peržiūrų režimas

```bash
sed -i 's|AUDIT_LOG_PAGE_VIEWS=off|AUDIT_LOG_PAGE_VIEWS=all|' .env
php artisan config:clear
```

`all` — viskas fiksuojama, bet žurnalai auga greitai.
`whitelist` — tik `config/audit.php` nurodyti maršrutai.

Rekomendacija: pradėti nuo `all`, po savaitės pamatuoti ir spręsti.

### B9. Galutinis patikrinimas

Atlikite keturis veiksmus sistemoje (prisijunkite, atidarykite puslapį,
pakeiskite įrašą, atsisiųskite failą), tada:

```bash
tail -20 /home/logs/{APP_NAME}/audit/audit-$(date +%Y-%m-%d).log \
  | grep -o '"category":"[^"]*"' | sort | uniq -c
```

Laukiama pamatyti: `login`, `view`, `update` arba `db_update`, `download`.

### B10. Švarinimas

```bash
grep -n "test-db\|opcache-clear\|test-klaida" routes/web.php
```

Turi nieko negrąžinti.

---

## C. Ko daryti NEREIKIA

Šie dalykai veikia automatiškai — jei kur nors juos pridėsite, gausite
dubliuotus įrašus:

| Dalykas | Kodėl nereikia |
|---|---|
| `use Auditable;` modeliuose | Visi modeliai audituojami automatiškai |
| `auditExclude()` su `pers_code` | Blokuojamas globaliai pakete |
| `LogsExceptions` trait `Handler.php` | Handler apgaubiamas automatiškai |
| `Kernel.php` middleware registravimas | Registruojama per ServiceProvider |
| `$this->logView()` kontroleriuose | Jei įjungtas `AUDIT_LOG_PAGE_VIEWS` |

---

## D. Žinomos kliūtys ir sprendimai

| Simptomas | Priežastis | Sprendimas |
|---|---|---|
| `composer: command not found` | Nėra `PATH` | `php composer.phar` |
| `Could not authenticate against github.com` | Greičio riba | A2 tokenas |
| `allow-plugins` klaida | Senas `composer.lock` | B2 `allow-plugins` |
| Pakeitimai neįsigalioja | OPcache | Laikina `/opcache-clear` route |
| `.env` reikšmė ignoruojama | Config cache arba shell kintamasis | `config:clear`, `unset AUDIT_LOG_*` |
| Žurnalas rašo į kito projekto katalogą | `APP_NAME` nuklydo | B4 |
| `route:list` lūžta | PSR-4 klaida projekte (failo pavadinimo registras) | Pervadinti failą, `dump-autoload` |
| Įdiegta senesnė versija | `require` įrašė siaurą apribojimą | `php composer.phar update vdu/tis-logging` |

---

## E. Neišspręsti klausimai

**Retencijos terminas.** `AUDIT_LOG_RETENTION_DAYS=90` yra paketo numatytoji
reikšmė, ne sprendimas. Po 90 dienų seni žurnalai automatiškai trinami.
Valstybinėms IS dažnai reglamentuojama 1–3 metai — verta patvirtinti su
duomenų apsaugos pareigūnu prieš platesnį diegimą.

**Žurnalų vientisumas.** Bet kas su serverio prieiga gali ištrinti eilutę iš
žurnalo be pėdsakų. Jei auditas reikalingas teisiniam įrodymui, vertėtų
apsvarstyti hash grandinę (kiekvienas įrašas su ankstesnio hash'u) ir
`audit:verify` komandą.

**Disko vieta.** Su `AUDIT_LOG_PAGE_VIEWS=all` žurnalai auga pastebimai.
Prieš diegiant į visus 10+ projektų verta savaitę pamatuoti viename:

```bash
du -sh /home/logs/{APP_NAME}/
```
