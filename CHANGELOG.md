# Changelog

Visi svarbūs paketo pakeitimai fiksuojami šiame faile.
Versijavimas pagal [Semantic Versioning](https://semver.org/): MAJOR.MINOR.PATCH.

## [1.4.0] - 2026-08-18

### Pridėta
- **`LogsExceptions` trait** - paskutinis trūkstamas komponentas pilnam žurnalizavimo
  reikalavimui. Įdedamas į projekto `app/Exceptions/Handler.php`, automatiškai fiksuoja
  visas nepagautas išimtis kaip `error` tipo įvykius `error/` kanale. Gerbia projekto
  `$dontReport` sąrašą. Pilnas stack trace su argumentais sąmoningai neįtraukiamas
  (galimai jautrūs duomenys) - loginamas tik failas ir eilutė.
- 4 nauji testai, padengiantys `LogsExceptions` funkcionalumą.

Su šia versija paketas dabar padengia VISUS pradinius reikalavimus: kas prisijungė,
kokius duomenis peržiūrėjo, ką pakeitė, kada, su pilna įvykio rūšių taksonomija
(info/error/security/system/warning) ir automatiniu klaidų fiksavimu.

## [1.3.0] - 2026-08-18

### Pataisyta (svarbu "kas atliko veiksmą" reikalavimui)
- **`EventLogger` dabar tikrina VISUS projekte sukonfigūruotus auth guard'us**,
  ieškodamas prisijungusio vartotojo, o ne tik numatytąjį (`config('auth.defaults.guard')`).
  Anksčiau, projektuose su keliais autentifikacijos būdais (pvz. `web` SSO vartotojams
  ir custom `espUser` guard'as vietiniams vartotojams), bet kuris veiksmas, atliktas
  vartotojo, prisijungusio per NE-numatytąjį guard'ą, žurnale atsirasdavo su
  `user_id: null` / `user_identifier: null` - reali spraga audito reikalavimui
  identifikuoti, kas atliko veiksmą. Dabar `Auth::user()` (numatytasis, greičiausias
  kelias) tikrinamas pirmas, o jei ten nieko nerasta - iteruojami visi kiti
  `config('auth.guards')` apibrėžti guard'ai.

## [1.2.0] - 2026-08-17

### Pataisyta (svarbu BDAR/duomenų minimizavimo požiūriu)
- **`old_values` dabar rodo TIK pasikeitusių laukų senas reikšmes**, o ne visą modelio
  įrašą. Anksčiau `AuditObserver::updated()` naudojo `$model->getOriginal()`, kuris
  grąžina visą įrašą (visus stulpelius, net nepasikeitusius) - tai reiškė, kad kiekvienas
  `update` įrašas nereikalingai atskleisdavo visus modelio laukus (įskaitant potencialiai
  jautrius, pvz. asmens kodus), net jei pasikeitė tik vienas stulpelis. Dabar `old_values`
  simetriškas su `new_values` - abu apima tik realiai pasikeitusius laukus.
- Numatytasis `config('audit.exclude')` sąrašas praplėstas dažniausiais slaptažodžio
  lauko pavadinimų variantais (`pass`, `passwd`, `pwd`, `api_token`), nes skirtingi
  projektai/lentelės naudoja skirtingas konvencijas (ne visur `password`).

### Rekomendacija projektams, naudojantiems `Auditable` trait
Peržiūrėkite kiekvieno audituojamo modelio laukus ir, jei yra domeno-specifinių jautrių
laukų (asmens kodai, gimimo datos ir pan.), pridėkite juos per modelio `auditExclude()`
metodą - bendrinis paketo `exclude` sąrašas jų automatiškai atpažinti negali.

## [1.1.0] - 2026-08-13

### Pakeista
- Žurnalų saugojimo struktūra: vietoj vieno nuolat augančio `audit.log`/`error.log`
  failo, dabar naudojami atskiri poaplankiai `{app_name}/audit/` ir `{app_name}/error/`
  su automatine kasdienine rotacija (Monolog `RotatingFileHandler`) - pvz.
  `audit/audit-2026-08-13.log`. Senesni nei `AUDIT_LOG_RETENTION_DAYS` dienų failai
  automatiškai ištrinami.
- `audit:install` komanda dabar sukuria abu poaplankius (`audit/`, `error/`) su
  atskiru teisių patikrinimu kiekvienam.

**Diegiantiems iš v1.0.0:** jei jau turite senų `audit.log`/`error.log` failų iš
ankstesnės versijos, jie liks kaip yra (paketas jų automatiškai nemigruoja) - naujus
įrašus rasite naujoje `audit/`/`error/` poaplankių struktūroje.

## [1.0.0] - 2026-08-12

Pirmas stabilus, pilnai testuotas paketo leidimas, paruoštas pilotiniam diegimui.

### Pridėta
- `EventLogger` branduolys - PSR-3 suderintas žurnalizavimas per Monolog, du atskiri
  kanalai (`audit.log` / `error.log`), skirstomi pagal `event_type`.
- Auth event listener'iai - automatinis `Login`/`Logout`/`Failed` event'ų fiksavimas.
- `Auditable` trait - automatinis Eloquent modelio create/update/delete fiksavimas su
  senomis/naujomis reikšmėmis, jautrių laukų filtravimu.
- `LogsViews` trait - rankinis peržiūros veiksmų fiksavimas kontroleriuose.
- `audit:install` Artisan komanda - automatinis config publikavimas, `.env` papildymas,
  žurnalų katalogo sukūrimas.
- 14 vienetinių/integracinių testų, padengiančių visą funkcionalumą.
- Suderinamumas: PHP 7.1.3-8.0, Laravel 5.7-9.x.

### Žinomi apribojimai
- Projektai, kurie apeina standartinį `Auth::attempt()` (pvz. custom SSO broker
  integracijos, rankinis `Auth::guard()->login()`), turi papildyti savo login
  kontrolerius rankiniu `AuditLog::security(...)` kvietimu - auth listener'iai
  tokių atvejų automatiškai nepagaus.
- Laravel 10+/PHP 8.1+ (Monolog 3.x) - nebandyta, prieš diegimą tokiame projekte
  paleisti pilną testų rinkinį.
