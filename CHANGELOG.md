# Changelog

Visi svarbūs paketo pakeitimai fiksuojami šiame faile.
Versijavimas pagal [Semantic Versioning](https://semver.org/): MAJOR.MINOR.PATCH.

## [2.6.0] - 2026-09-08

### Pridėta
- **`audit:install` dabar prideda VISUS `AUDIT_LOG_*` kintamuosius** į `.env`.
  Iki šiol komanda rašė tik pradinį penkių kintamųjų rinkinį, o vėliau pridėtos
  funkcijos (`AUDIT_LOG_QUERIES`, `AUDIT_LOG_PAGE_VIEWS`, `AUDIT_LOG_CLIENT_EVENTS`
  ir kt.) į jį nepateko - diegiant naują projektą juos tekdavo prisiminti ir
  įrašyti rankomis.
- Kintamieji grupuojami su antraštėmis ir komentarais, kad `.env` faile būtų
  aišku, ką kiekvienas daro.
- Praleidžiami jau esami kintamieji - komandą galima saugiai paleisti
  pakartotinai esamuose projektuose, kad būtų pridėti tik trūkstami.
- `audit:install` išvestis perrašyta: aiškiai atskirta, kas veikia
  automatiškai, ką reikia įjungti `.env`, o kur būtinas rankinis kodas.
- 3 nauji testai.

## [2.5.0] - 2026-09-08

### Pridėta
- **`LogPageViews` middleware - automatinis puslapių peržiūrų fiksavimas** be
  kontrolerių redagavimo. Iki šiol peržiūros reikalavo rankinio `LogsViews`
  trait naudojimo kiekviename kontroleryje.
- Trys režimai per `AUDIT_LOG_PAGE_VIEWS`:
  - `off` (NUMATYTOJI) - tik rankinis `logView()`;
  - `whitelist` - fiksuojami tik `config('audit.log_page_views.routes')`
    išvardinti maršrutai (palaiko `*` šablonus). REKOMENDUOJAMA;
  - `all` - kiekvienas puslapio atidarymas.
- Route parametrai automatiškai įrašomi kaip `subject_id` - žurnale matoma,
  KIENO duomenys peržiūrėti, ne tik kad puslapis atidarytas.
- `exclude` sąrašas techniniams maršrutams praleisti.
- 9 nauji testai.

### Sąmoningi apribojimai
- Fiksuojami tik `GET`/`HEAD` sėkmingi (2xx) HTML atsakymai. `POST`/`PUT`/
  `DELETE` jau padengti modelio ir SQL mechanizmų, atsisiuntimai -
  `LogFileDownloads`, o JSON/AJAX atsakymai paprastai techniniai, ne
  "duomenų peržiūra".
- `all` režimas generuoja labai daug įrašų. Tai apsunkina svarbių įvykių
  paiešką ir BDAR duomenų minimizavimo principo pagrindimą - todėl
  numatytoji reikšmė yra `off`, o dokumentacijoje rekomenduojamas
  `whitelist`.

## [2.4.0] - 2026-09-08

### Pridėta
- **Klientinės pusės įvykių endpoint'as.** Kai kurie veiksmai vyksta vien
  naršyklėje ir nesukelia jokios HTTP užklausos - pvz. Excel eksportas per
  SheetJS (`XLSX.writeFile`), `window.print()`, kopijavimas į iškarpinę.
  Failas suformuojamas iš jau įkelto DOM turinio ir išsaugomas tiesiai į
  vartotojo diską, tad JOKIA serverio pusės priemonė to pagauti negali.
  Pastebėta realiame pilotiniame diegime.
- Įjungus `AUDIT_LOG_CLIENT_EVENTS=true`, registruojamas POST maršrutas
  (numatytoji reikšmė `/audit/client-event`), į kurį JS gali pranešti apie
  tokį veiksmą vienu `fetch` kvietimu (pavyzdys README).
- Apsaugos nuo piktnaudžiavimo: kategorijų baltasis sąrašas, aprašymo ilgio
  ir konteksto raktų kiekio ribos, ne skaliarinių reikšmių atmetimas,
  `throttle:60,1`.
- Kiekvienas toks įrašas žymimas `"source": "client"` - klientinės pusės
  pranešimai nėra tokie patys patikimi kaip serverio užfiksuoti įvykiai
  (vartotojas techniškai gali jų neišsiųsti), tad auditą peržiūrintis asmuo
  turi matyti skirtumą.
- 7 nauji testai.

## [2.3.0] - 2026-09-07

### Pridėta
- **`old_values` SQL lygmens įrašams - "iš ko į ką pakeitė".** Iki šiol SQL
  užklausų fiksavimas rodė tik naujas reikšmes, nes `QueryExecuted` event'as
  suveikia jau PO užklausos įvykdymo. Dabar `OldValuesSnapshotStore` per
  `DB::beforeExecuting` perima užklausą PRIEŠ vykdymą ir nuskaito esamą įrašo
  būseną, tad žurnale matomos ir senos, ir naujos reikšmės net kai naudojamas
  `DB::table()->update()` vietoj Eloquent modelio.
- **Rodomi tik realiai pasikeitę laukai.** Jei forma siunčia visus stulpelius,
  bet dalis perrašoma tomis pačiomis reikšmėmis, tokie stulpeliai į žurnalą
  nepatenka. `UPDATE`, kuris nieko nepakeitė, apskritai nefiksuojamas.
- `DELETE` atveju ištrinto įrašo turinys fiksuojamas kaip `old_values`.
- Naujos config opcijos: `AUDIT_LOG_CAPTURE_OLD_VALUES` (numatytoji `true`),
  `AUDIT_LOG_OLD_VALUES_MAX_ROWS` (numatytoji 5 - riba masiniams atnaujinimams).
- 5 nauji testai.

### Suderinamumas
- **Laravel 8.x-9.x**: per `DB::beforeExecuting` - veikia su visais draiveriais,
  įskaitant Oracle (`yajra/laravel-oci8`).
- **Laravel 5.7-7.x**: `beforeExecuting` ten neegzistuoja, o `StatementPrepared`
  event'as rašymo užklausoms nesuveikia, tad naudojamos pakeistos jungties
  klasės per `Connection::resolverFor()` (mysql, pgsql, sqlite, sqlsrv).
- **Oracle senesnėse Laravel versijose**: `yajra/laravel-oci8` resolveris
  NEPERRAŠOMAS (jis nustato NLS datų formatus, dešimtainius skirtukus,
  `CURRENT_SCHEMA` - perrašius sugriūtų visas projektas). Vietoj to jis
  apgaubiamas: leidžiama atlikti visą darbą, tada jungties būsena
  perkeliama į paketo poklasį per refleksiją (`ConnectionStateCopier`),
  išsaugant TĄ PATĮ PDO objektą su jau nustatytais seanso kintamaisiais.
  Nepavykus - grąžinama originali jungtis, projektas nenukenčia.

### Svarbi detalė - tingus (lazy) prisijungimas
- Laravel `pdo` savybė iš pradžių būna `Closure` (jungtis atidaroma tik
  prireikus). `ConnectionStateCopier` prieš kopijavimą priverstinai išsprendžia
  šį `Closure` - kitaip originali jungtis ir kopija jį iškviestų atskirai ir
  atsidarytų DVI skirtingos DB jungtys, o Oracle atveju antroji būtų BE yajra
  nustatytų NLS seanso kintamųjų (sugriūtų datų formatai ir dešimtainiai
  skirtukai).

### Apribojimai
- Našumo kaina: viena papildoma `SELECT` užklausa kiekvienam `UPDATE`/`DELETE`.
- Senos reikšmės nuskaitomos tik jei `WHERE` sąlygos atpažįstamos
  (`stulpelis = ?` forma).

## [2.2.0] - 2026-09-07

### Pridėta
- **`SqlStatementParser` - stulpeliai surišami su reikšmėmis.** Anksčiau SQL
  lygmens įrašai buvo praktiškai neskaitomi: stulpelių pavadinimai SQL sakinyje,
  reikšmės - atskirame poziciniame masyve, tad administratoriui tekdavo juos
  suvesti mintyse. Dabar žurnale matomas tvarkingas `new_values` žemėlapis
  (`stulpelis => reikšmė`) ir atskiras `conditions` laukas (WHERE sąlygos),
  plius `table` laukas su lentelės pavadinimu.
- Lentelės pavadinimas įtraukiamas į įrašo žinutę.
- Žalias SQL sakinys pridedamas TIK jei analizė nepavyko - kitaip įrašas be
  reikalo išsipūstų.
- **Base64 paveikslėliai keičiami į `[BASE64_IMAGE]` žymą.** WYSIWYG redaktorių
  turinys su įterptais paveikslėliais generuodavo šimtų kilobaitų dydžio žurnalo
  įrašus (pastebėta realiame diegime).
- `AUDIT_LOG_MAX_BINDING_LENGTH` - konfigūruojamas parametrų trumpinimo ribos
  ilgis (numatytoji - 500 simbolių).
- 17 naujų/atnaujintų testų, įskaitant Oracle stiliaus SQL (dvigubos kabutės,
  didžiosios raidės), MySQL backtick sintaksę ir kelių WHERE sąlygų atvejus.

## [2.1.0] - 2026-09-07

### Pridėta
- **`QueryAuditListener` - SQL užklausų lygmens fiksavimas.** Pastebėta realiame
  pilotiniame diegime: kontroleriai, naudojantys `DB::table('x')->update([...])`
  arba `Model::where(...)->update([...])`, APEINA Eloquent modelio instanciją,
  todėl jokie modelio event'ai nemetami ir `GlobalModelAuditListener` tokių
  pakeitimų nepamato. Naujas listener'is fiksuoja visas INSERT/UPDATE/DELETE
  užklausas SQL lygmeniu (kategorijos `db_insert`, `db_update`, `db_delete`).
- Įjungiama per `AUDIT_LOG_QUERIES=true` (pagal nutylėjimą IŠJUNGTA, nes
  generuoja daug įrašų ir dubliuojasi su Eloquent fiksavimu).
- `config('audit.exclude_query_tables')` - aukšto dažnio techninių lentelių
  sąrašas (sessions, cache, jobs ir kt.), praleidžiamas fiksuojant.
- Jautrių duomenų maskavimas parametruose: bcrypt/argon hash'ai keičiami į
  `[REDACTED]`, ilgesnės nei 500 simbolių reikšmės trumpinamos.
- SELECT užklausos sąmoningai NEfiksuojamos.
- 8 nauji testai.

### Žinomas apribojimas
- SQL lygmens įrašuose NĖRA `old_values` - užklausa nežino, kas buvo prieš
  pakeitimą. Tikslus "iš ko į ką pakeitė" įmanomas TIK per Eloquent modelio
  instanciją (`$model->save()`). Jei tai kritiška, kontrolerius reikia
  pertvarkyti naudoti modelio instancijas vietoj `DB::table()`.

## [2.0.0] - 2026-09-03

### LŪŽTANTIS PAKEITIMAS (breaking change)
- **Numatytoji elgsena pasikeitė: nuo šiol VISI Eloquent modeliai audituojami
  automatiškai**, be jokio `use Auditable;` pridėjimo modelio faile. Anksčiau
  buvo audituojami TIK modeliai su eksplicitiškai pridėtu `Auditable` trait.
  Tai reiškia, kad po šio atnaujinimo projektų žurnalai staiga pradės pildytis
  daug daugiau `create`/`update`/`delete` įrašų nei anksčiau.

### Kaip išjungti/pritaikyti, jei nepageidaujama
- `AUDIT_LOG_ALL_MODELS=false` - visiškai išjungia globalų mechanizmą,
  grįžtama prie ankstesnio elgesio (tik `Auditable` trait pažymėti modeliai).
- `config('audit.exclude_models')` - pilnai kvalifikuotų klasių sąrašas,
  kuriuos globalus mechanizmas turi praleisti (pvz. aukšto dažnio, techniniai
  modeliai).

### Pridėta
- **`GlobalModelAuditListener`** - registruojasi per Laravel wildcard
  Eloquent event'us (`eloquent.created: *` ir t.t.), automatiškai audituoja
  kiekvieną modelį projekte.
- **`ModelAuditRecorder`** - bendra logika, naudojama tiek `AuditObserver`
  (rankinis `Auditable` trait), tiek naujo globalaus mechanizmo, kad abu
  keliai elgtųsi tiksliai vienodai (tas pats duomenų minimizavimas, tas
  pats jautrių laukų filtravimas per `config('audit.exclude')` ir
  `auditExclude()`).
- Modeliai su `Auditable` trait automatiškai praleidžiami globaliame
  mechanizme, kad nebūtų dubliuoto fiksavimo.
- 7 nauji testai, padengiantys globalaus audito funkcionalumą.

## [1.7.0] - 2026-09-03

### Pridėta
- **Pasikartojančių atsisiuntimų sujungimas (deduplication).** Kai kurios
  naršyklės (pvz. Chrome PDF viewer) siunčia DVI atskiras užklausas tai
  pačiai URL vienam vartotojo veiksmui - pirma peržiūrai, tada, paspaudus
  atsisiuntimo mygtuką viduje, dar kartą, kad išsaugotų failą. Pastebėta
  realiame pilotiniame diegime (2 vartotojo veiksmai sukūrė 4 žurnalo
  įrašus). Dabar pasikartojantys tos pačios URL kvietimai per
  `AUDIT_LOG_DOWNLOAD_DEDUP_SECONDS` (numatytoji - 10 sek.) sujungiami į
  VIENĄ įrašą. Dedup raktas apima vartotojo tapatybę (arba IP, jei
  neprisijungęs), tad skirtingi vartotojai NĖRA sujungiami tarpusavyje.
- Žurnalo žinutė pakeista iš "Failas atsisiųstas" į **"Failas
  peržiūrėtas/atsisiųstas"**, sąžiningai atspindint, kad serveris negali
  patikimai atskirti šių dviejų atvejų.
- 4 nauji testai, padengiantys dedup logiką.

## [1.6.1] - 2026-09-03

### Pataisyta (kritinė klaida)
- **`LogFileDownloads` nefiksavo `BinaryFileResponse` atsakymų be `Content-Disposition`
  antraštės**, pastebėta realiame pilotiniame diegime (`UserCompetenceController::
  downloadMyPortfolio()` tipo kontroleriai, kurie sukuria `BinaryFileResponse`
  tiesiogiai, praleisdami disposition parametrą). Dabar `BinaryFileResponse`
  fiksuojamas BESĄLYGIŠKAI (pats šis tipas jau reiškia "siunčiamas failas"),
  o failo pavadinimas, jei antraštės nėra, paimamas iš paties failo kelio.
  Kiti `Response` tipai (pvz. `laravel-dompdf` grąžinamas paprastas `Response`)
  ir toliau reikalauja `Content-Disposition` antraštės, kad nebūtų klaidingai
  fiksuojami paprasti HTML puslapiai.
- Naujas testas, tiksliai atkuriantis šį atvejį.

## [1.6.0] - 2026-09-03

### Pridėta
- **`LogFileDownloads` dabar nustato `Cache-Control: no-store` antraštę** kiekvienam
  užfiksuotam atsisiuntimui. Be to, PAKARTOTINIAI to paties failo atsisiuntimai galėtų
  būti aptarnaujami naršyklės talpyklos, o serveris (ir auditas) apie tai niekada
  nesužinotų - realus scenarijus, pastebėtas pilotinio diegimo metu. Taip pat gera
  saugumo praktika jautriems dokumentams bendro naudojimo kompiuteriuose. Išjungiama
  per `AUDIT_LOG_PREVENT_DOWNLOAD_CACHING=false`, jei projektui svarbesnė talpyklos
  nauda našumui.
- 2 nauji testai.

## [1.5.1] - 2026-08-18

### Pataisyta (kritinė klaida)
- **`LogFileDownloads` middleware nefiksavo PDF atsisiuntimų per `barryvdh/laravel-dompdf`**,
  nes ankstesnė versija tikrino tik `BinaryFileResponse`/`StreamedResponse` poklasius,
  o `laravel-dompdf` `download()` metodas grąžina paprastą `Illuminate\Http\Response`
  su rankomis nustatyta `Content-Disposition` antrašte. Dabar tikrinama bazinė
  `Symfony\Component\HttpFoundation\Response` klasė (kurią turi VISI Laravel
  atsakymai), tad middleware veikia nepriklausomai nuo to, kokį konkretų Response
  poklasį naudoja atsisiuntimą generuojanti biblioteka.
- Naujas testas, imituojantis būtent `laravel-dompdf` grąžinamo atsakymo tipą,
  kad ši regresija nebepasikartotų ateityje.

## [1.5.0] - 2026-08-18

### Pridėta
- **Universalus failų atsisiuntimų middleware (`LogFileDownloads`)** - automatiškai
  fiksuoja VISUS failų atsisiuntimus (Excel, PDF, Storage::download(),
  response()->download() ir t.t.) tikrindamas kiekvieno HTTP atsakymo
  `Content-Disposition` antraštę. Registruojasi automatiškai per paketo
  ServiceProvider - projekto `Kernel.php` redaguoti NEREIKIA. Išjungiamas per
  `AUDIT_LOG_DOWNLOADS=false`.
- 5 nauji testai, padengiantys middleware funkcionalumą.

### Žinomas apribojimas
- Middleware apima tik atsisiuntimus (failus su `Content-Disposition` antrašte).
  Paprastos duomenų peržiūros be failo generavimo vis tiek reikalauja rankinio
  `LogsViews` trait naudojimo - tai fundamentalus apribojimas (HTTP atsakymas
  rodant duomenis puslapyje neturi universalaus "tai audituotina peržiūra" požymio).

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
