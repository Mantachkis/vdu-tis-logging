<?php

return [

    /*
    |--------------------------------------------------------------------
    | Aplikacijos (sistemos) identifikatorius
    |--------------------------------------------------------------------
    |
    | Naudojamas kaip poaplankio pavadinimas šakniniame žurnalų kataloge,
    | pvz. "makademijatest" -> ~/logs/makademijatest/audit/... ir
    | ~/logs/makademijatest/error/...
    |
    */
    'app_name' => env('AUDIT_LOG_APP_NAME', env('APP_NAME', 'app')),

    /*
    |--------------------------------------------------------------------
    | Šakninis žurnalų katalogas
    |--------------------------------------------------------------------
    |
    | Numatytoji reikšmė - "logs" katalogas serverio vartotojo home
    | kataloge (pvz. /home/deployuser/logs). Kiekvienas projektas
    | sukuria savo poaplankį pagal app_name reikšmę.
    |
    | Būtina eksplicitiškai nurodyti per .env kiekviename projekte,
    | nes home katalogas priklauso nuo to, kokiu Linux vartotoju
    | veikia PHP-FPM/deploy procesas tame konkrečiame serveryje.
    |
    */
    'base_path' => env('AUDIT_LOG_BASE_PATH', rtrim(getenv('HOME') ?: '', '/').'/logs'),

    /*
    |--------------------------------------------------------------------
    | Audit ir error kanalų bazinis failo pavadinimas
    |--------------------------------------------------------------------
    |
    | Įvykiai skirstomi į du atskirus poaplankius pagal event_type:
    | - "error" ir "warning" tipo įvykiai -> {app_name}/error/
    | - "info", "security", "system" -> {app_name}/audit/
    |
    | Naudojamas Monolog RotatingFileHandler - kiekvienai dienai
    | automatiškai sukuriamas atskiras failas su data pavadinime, pvz.:
    | {base_path}/{app_name}/audit/audit-2026-08-13.log
    | {base_path}/{app_name}/error/error-2026-08-13.log
    |
    */
    'audit_filename' => env('AUDIT_LOG_AUDIT_FILENAME', 'audit.log'),
    'error_filename' => env('AUDIT_LOG_ERROR_FILENAME', 'error.log'),

    /*
    |--------------------------------------------------------------------
    | Numatytieji neloginami (jautrūs) laukai
    |--------------------------------------------------------------------
    |
    | Šie laukai VISADA pašalinami iš old_values/new_values Auditable
    | trait naudojant modeliuose, nepriklausomai nuo to, ar modelis
    | juos eksplicitiškai išskiria per auditExclude(). Kiekvienas
    | modelis gali pridėti papildomų laukų per savo auditExclude().
    |
    | Sąrašas apima dažniausius slaptažodžio lauko pavadinimų variantus,
    | nes skirtingi projektai/lentelės naudoja skirtingas konvencijas
    | (pvz. "pass" vietoj "password"). VIS TIEK PATIKRINKITE kiekvieno
    | audituojamo modelio laukus rankomis prieš diegdami - šis sąrašas
    | yra saugus numatytasis startas, ne garantija, kad visi jautrūs
    | laukai (asmens kodai, gimimo datos ir pan.) bus automatiškai
    | aptikti. Naudokite auditExclude() modelyje papildomiems laukams.
    |
    */
    'exclude' => [
        'password', 'pass', 'passwd', 'pwd',
        'remember_token', 'api_token',
    ],

    /*
    |--------------------------------------------------------------------
    | Automatinis VISŲ Eloquent modelių audito fiksavimas
    |--------------------------------------------------------------------
    |
    | Jei true (numatytoji reikšmė), paketas automatiškai audituoja
    | KIEKVIENO projekto Eloquent modelio create/update/delete įvykius,
    | BE JOKIO "use Auditable;" pridėjimo modelio faile. Modeliai, kurie
    | JAU naudoja Auditable trait'ą, praleidžiami šiame globaliame
    | mechanizme (kad nebūtų dubliuoto fiksavimo) - jų auditavimą
    | toliau tvarko pats trait'as.
    |
    | Nustatykite false, jei norite tvarkytis IŠIMTINAI rankiniu būdu
    | (tik su Auditable trait pažymėtus modelius).
    |
    */
    'audit_all_models' => env('AUDIT_LOG_ALL_MODELS', true),

    /*
    |--------------------------------------------------------------------
    | Modeliai, kurių NEREIKIA automatiškai audituoti
    |--------------------------------------------------------------------
    |
    | Pilnai kvalifikuoti klasių pavadinimai (su namespace), kuriuos
    | globalus audito mechanizmas turi PRALEISTI. Naudinga aukšto
    | dažnio, techniniams, ar nejautriems modeliams (pvz. sesijos,
    | notifikacijų, cache lentelės), kuriems auditas tik kurtų
    | nereikalingą triukšmą žurnaluose.
    |
    | Pavyzdys:
    |   'exclude_models' => [
    |       \App\Models\Session::class,
    |       \App\Models\PageView::class,
    |   ],
    |
    */
    'exclude_models' => [],

    /*
    |--------------------------------------------------------------------
    | SQL užklausų lygmens fiksavimas (DB::table ir kt.)
    |--------------------------------------------------------------------
    |
    | Eloquent modelio event'ai NESUVEIKIA, kai duomenys keičiami apeinant
    | modelio instanciją:
    |
    |     DB::table('news')->where('id', 5)->update([...]);
    |     Model::where('id', 5)->update([...]);   // query builder
    |
    | Jei jūsų projekte tokių vietų yra (dažna praktika senesniuose
    | kontroleriuose), įjunkite šią opciją - tada fiksuojamos VISOS
    | INSERT/UPDATE/DELETE užklausos SQL lygmeniu.
    |
    | SVARBŪS APRIBOJIMAI:
    | - NĖRA old_values (SQL nežino, kas buvo prieš pakeitimą - tai žino
    |   tik iš DB įkeltas Eloquent modelis).
    | - Nėra subject_type/subject_id modelio konteksto.
    | - Generuoja ŽYMIAI daugiau įrašų, įskaitant dubliuotus su Eloquent
    |   fiksavimu (tas pats pakeitimas per modelį bus užfiksuotas du kartus:
    |   kaip "update" ir kaip "db_update").
    |
    | Rekomendacija: naudokite laikinai, kol pertvarkysite kontrolerius
    | naudoti modelio instancijas ($model->save()), arba nuolat, jei
    | pilnas SQL lygmens padengimas svarbesnis už žurnalų glaustumą.
    |
    */
    'log_queries' => env('AUDIT_LOG_QUERIES', false),

    /*
    |--------------------------------------------------------------------
    | Lentelės, kurių SQL užklausų NEFIKSUOTI
    |--------------------------------------------------------------------
    |
    | Taikoma tik kai log_queries = true. Aukšto dažnio techninės lentelės,
    | kurios kurtų tik triukšmą žurnaluose.
    |
    */
    'exclude_query_tables' => [
        'sessions',
        'cache',
        'jobs',
        'failed_jobs',
        'password_resets',
    ],

    /*
    |--------------------------------------------------------------------
    | Maksimalus SQL parametro reikšmės ilgis žurnale
    |--------------------------------------------------------------------
    |
    | Taikoma tik kai log_queries = true. Ilgesnės reikšmės trumpinamos,
    | kad vienas žurnalo įrašas neišaugtų iki šimtų kilobaitų (dažna
    | problema su WYSIWYG redaktorių HTML turiniu). Base64 įterpti
    | paveikslėliai atskirai pakeičiami į [BASE64_IMAGE] žymą.
    |
    */
    'max_binding_length' => env('AUDIT_LOG_MAX_BINDING_LENGTH', 500),

    /*
    |--------------------------------------------------------------------
    | Senų reikšmių nuskaitymas SQL užklausoms
    |--------------------------------------------------------------------
    |
    | Taikoma tik kai log_queries = true. Prieš kiekvieną UPDATE/DELETE
    | atliekama PAPILDOMA SELECT užklausa, kad būtų nuskaityta įrašo
    | būsena prieš pakeitimą - be to neįmanoma parodyti "iš ko į ką
    | pakeitė", kai naudojamas DB::table() vietoj Eloquent modelio.
    |
    | REIKALAUJA Laravel 8+ (DB::beforeExecuting). Senesnėse versijose
    | (5.7-7.x) tyliai išjungiama.
    |
    | Našumo kaina: viena papildoma SELECT užklausa kiekvienam
    | UPDATE/DELETE. Nustatykite false, jei našumas svarbesnis už
    | senų reikšmių fiksavimą.
    |
    */
    'capture_old_values' => env('AUDIT_LOG_CAPTURE_OLD_VALUES', true),

    /*
    |--------------------------------------------------------------------
    | Maksimalus nuskaitomų eilučių kiekis senoms reikšmėms
    |--------------------------------------------------------------------
    |
    | Masiniai atnaujinimai (UPDATE be griežtų WHERE sąlygų) gali paliesti
    | tūkstančius eilučių - be šio apribojimo vienas žurnalo įrašas
    | išaugtų iki milžiniško dydžio.
    |
    */
    'old_values_max_rows' => env('AUDIT_LOG_OLD_VALUES_MAX_ROWS', 5),

    /*
    |--------------------------------------------------------------------
    | Saugojimo terminas dienomis
    |--------------------------------------------------------------------
    |
    | Tiesiogiai naudojamas Monolog RotatingFileHandler maxFiles parametrui -
    | automatiškai ištrina audit/error failus, senesnius už nurodytą dienų
    | skaičių. 0 = niekada automatiškai netrinti (rankinis archyvavimas arba
    | OS lygmens logrotate turi tvarkyti retenciją patys).
    |
    */
    'retention_days' => env('AUDIT_LOG_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------
    | Automatinis failų atsisiuntimų fiksavimas
    |--------------------------------------------------------------------
    |
    | Jei true (numatytoji reikšmė), paketas automatiškai registruoja
    | globalų middleware'į, kuris fiksuoja VISUS failų atsisiuntimus
    | (Excel::download(), PDF::download(), Storage::download() ir t.t.)
    | be jokio Kernel.php ar kontrolerio redagavimo. Nustatykite false,
    | jei projektui to nereikia arba norite tvarkytis rankiniu būdu.
    |
    */
    'log_downloads' => env('AUDIT_LOG_DOWNLOADS', true),

    /*
    |--------------------------------------------------------------------
    | Naršyklės talpyklos blokavimas atsisiunčiamiems failams
    |--------------------------------------------------------------------
    |
    | Jei true (numatytoji reikšmė), kiekvienam užfiksuotam atsisiuntimui
    | pridedama "Cache-Control: no-store" antraštė - be to, PAKARTOTINIAI
    | to paties failo atsisiuntimai galėtų būti aptarnaujami iš naršyklės
    | talpyklos, o serveris (ir auditas) apie tai niekada nesužinotų.
    | Taip pat gera saugumo praktika jautriems dokumentams bendro
    | naudojimo kompiuteriuose. Nustatykite false, jei projektui svarbi
    | talpyklos nauda našumui (pvz. dideli, dažnai atsisiunčiami vieši
    | failai, kurių audito pilnumas nekritiškas).
    |
    */
    'prevent_download_caching' => env('AUDIT_LOG_PREVENT_DOWNLOAD_CACHING', true),

    /*
    |--------------------------------------------------------------------
    | Pasikartojančių atsisiuntimų sujungimas (deduplication)
    |--------------------------------------------------------------------
    |
    | Naršyklės (pvz. Chrome PDF viewer) gali siųsti DVI atskiras užklausas
    | TAI PAČIAI URL vienam vartotojo veiksmui - pirma peržiūrai naršyklėje,
    | tada, paspaudus atsisiuntimo mygtuką, dar kartą, kad išsaugotų failą.
    | Serveris negali patikimai atskirti šių dviejų atvejų. Jei per šį
    | sekundžių skaičių tas pats vartotojas (ar IP, jei neprisijungęs)
    | pakartotinai kviečia tą pačią URL, antras kvietimas NEBEFIKSUOJAMAS -
    | laikoma tuo pačiu veiksmu. 0 = išjungti, fiksuoti kiekvieną kvietimą
    | atskirai.
    |
    */
    'download_dedup_seconds' => env('AUDIT_LOG_DOWNLOAD_DEDUP_SECONDS', 10),

    /*
    |--------------------------------------------------------------------
    | Aktyvus tvarkyklė (driver)
    |--------------------------------------------------------------------
    |
    | Šiuo metu palaikoma: "file". Ateityje planuojama: "database".
    | Pasirinkimas leidžia pakeisti saugojimo būdą nekeičiant
    | aplikacijos kodo, kuris naudoja AuditLog fasadą.
    |
    */
    'driver' => env('AUDIT_LOG_DRIVER', 'file'),

];
