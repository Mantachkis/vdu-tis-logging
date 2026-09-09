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
    | Išsiųsti el. laiškai
    |--------------------------------------------------------------------
    |
    | Laiško išsiuntimas (ypač naujienlaiškio šimtams gavėjų) yra
    | reikšmingas veiksmas su asmens duomenimis, bet DB nekeičia, tad
    | modelio/SQL mechanizmai jo nepamato. Fiksuojama automatiškai per
    | Laravel MessageSent event'ą, be kontrolerių redagavimo.
    |
    | BDAR pastaba: gavėjų el. paštai yra asmens duomenys. Pagal
    | nutylėjimą fiksuojami VISI adresai. Jei naujienlaiškiai siunčiami
    | tūkstančiams gavėjų, apsvarstykite max_recipients ribą - kitaip
    | vienas žurnalo įrašas gali turėti labai daug asmens duomenų.
    |
    */
    'mail' => [
        'enabled' => env('AUDIT_LOG_MAIL', true),

        // 0 = fiksuoti visus adresus. Didesnis nei 0 - fiksuoti tik tiek
        // adresų, o likusius pakeisti į "... ir dar N".
        'max_recipients' => env('AUDIT_LOG_MAIL_MAX_RECIPIENTS', 0),

        /*
        | Kiek laiškų fiksuoti ATSKIRAIS įrašais per vieną užklausą.
        |
        | Naujienlaiškiai dažnai siunčiami cikle, kur kiekvienas gavėjas
        | yra atskiras laiškas - 500 gavėjų duotų 500 beveik identiškų
        | žurnalo įrašų.
        |
        | Pirmi N fiksuojami atskirai (išsaugoma detali informacija), o
        | viskas virš ribos sukaupiama ir užklausos pabaigoje įrašoma
        | VIENA suvestine su likusių gavėjų sąrašu. Suvestinės įrašas
        | pažymimas "summary": true.
        |
        | 0 = be ribos, kiekvienas laiškas atskiru įrašu.
        */
        'max_individual_per_request' => env('AUDIT_LOG_MAIL_MAX_INDIVIDUAL', 20),
    ],

    /*
    |--------------------------------------------------------------------
    | Klientinės pusės (naršyklės) įvykiai
    |--------------------------------------------------------------------
    |
    | Kai kurie veiksmai vyksta VIEN naršyklėje ir nesukelia jokios HTTP
    | užklausos - pvz. Excel eksportas per SheetJS (XLSX.writeFile),
    | window.print(), kopijavimas į iškarpinę. Serveris apie juos nieko
    | nesužino, tad vienintelis būdas užfiksuoti - kad pati naršyklė
    | praneštų per šį endpoint'ą.
    |
    | SVARBU: klientinės pusės pranešimai NĖRA tokie patys patikimi kaip
    | serverio užfiksuoti įvykiai - vartotojas techniškai gali jų
    | neišsiųsti. Tokie įrašai žymimi "source": "client".
    |
    */
    'client_events' => [
        'enabled' => env('AUDIT_LOG_CLIENT_EVENTS', false),

        'route' => env('AUDIT_LOG_CLIENT_EVENTS_ROUTE', '/audit/client-event'),

        // "web" - kad veiktų sesija ir CSRF apsauga (vartotojo
        // identifikavimui). "throttle" - apsauga nuo žurnalo užtvindymo.
        'middleware' => ['web', 'throttle:60,1'],

        // Leidžiamos kategorijos - baltasis sąrašas, kad klientas
        // negalėtų prikimšti žurnalo bet kokiais įrašais.
        'allowed_categories' => ['export', 'print', 'view', 'copy'],

        'max_description_length' => 200,
        'max_context_keys' => 10,
    ],

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
    | Automatinis puslapių peržiūrų fiksavimas
    |--------------------------------------------------------------------
    |
    | Alternatyva rankiniam LogsViews trait naudojimui - fiksuoja
    | peržiūras BE jokio kontrolerių redagavimo.
    |
    | REŽIMAI:
    |
    |   'off'       - išjungta (NUMATYTOJI). Peržiūros fiksuojamos tik
    |                 rankiniu $this->logView() kvietimu.
    |
    |   'whitelist' - fiksuojami TIK žemiau išvardinti maršrutai.
    |                 REKOMENDUOJAMA: automatizavimo patogumas be žurnalų
    |                 užtvindymo, o kiekvienas įrašas išlieka prasmingas.
    |
    |   'all'       - fiksuojamas KIEKVIENAS puslapio atidarymas.
    |                 ĮSPĖJIMAS: generuoja labai daug įrašų (navigacija,
    |                 AJAX, atgal/pirmyn). Rasti tikrai svarbų įvykį tampa
    |                 sunkiau, o BDAR duomenų minimizavimo principą
    |                 sunkiau pagrįsti. Naudokite tik jei reglamentas
    |                 aiškiai to reikalauja.
    |
    | Visais režimais fiksuojami tik GET/HEAD sėkmingi HTML atsakymai -
    | duomenų keitimą jau padengia modelio/SQL mechanizmai, atsisiuntimus -
    | LogFileDownloads.
    |
    */
    'log_page_views' => [
        'mode' => env('AUDIT_LOG_PAGE_VIEWS', 'off'),

        /*
        | POST užklausų elgsena:
        |
        |   'off'       - POST niekada nefiksuojamas kaip peržiūra.
        |   'whitelist' - tik "post_routes" sąraše išvardinti (NUMATYTOJI).
        |   'auto'      - fiksuojamas, JEI per užklausą nebuvo užfiksuota
        |                 jokio reikšmingo veiksmo (duomenų pakeitimo ar
        |                 laiško išsiuntimo). Automatiškai atskiria
        |                 POST-peržiūras nuo POST-veiksmų, be rankinio sąrašo.
        |
        | 'auto' režimo niuansas: jei tas pats maršrutas kartais keičia
        | duomenis, o kartais ne, jis kartais atsiras kaip "update",
        | kartais kaip "view". Tai teisingai atspindi, kas realiai įvyko,
        | bet žurnalo skaitytojui gali pasirodyti nenuoseklu.
        */
        'post_mode' => env('AUDIT_LOG_POST_VIEWS', 'whitelist'),

        // Naudojama TIK 'whitelist' režimu. Palaiko "*" šablonus.
        // Route parametrai (pvz. {id}) automatiškai įrašomi kaip
        // subject_id - t.y. matysite, KIENO duomenys peržiūrėti.
        'routes' => [
            // 'admin/userInfoList',
            // 'admin/userCompetenceView/*',
            // 'admin/payments_list/*',
            // 'user/person_request_view/*',
        ],

        // Praleidžiami maršrutai (taikoma VISUOSE režimuose). Naudinga
        // 'all' režimu, kad nefiksuotų techninių/viešų puslapių.
        'exclude' => [
            'audit/*',
            '_debugbar/*',
            'captcha/*',
        ],

        /*
        | POST-peržiūros
        |
        | Kai kurios sistemos naudoja POST ne duomenų keitimui, o peržiūrai
        | su filtrais (pvz. /user/personal_studies). Pagal nutylėjimą POST
        | NEfiksuojamas kaip peržiūra - kitaip kiekvienas formos išsaugojimas
        | atsirastų žurnale DU kartus (kaip "update" iš modelio mechanizmo
        | ir kaip "view").
        |
        | Čia išvardinkite maršrutus, kurie POST metodu tik PARODO duomenis.
        | Veikia kaip baltasis sąrašas visuose režimuose - 'whitelist'
        | režimu jų nereikia dubliuoti "routes" sąraše.
        |
        | SVARBU: netraukite čia maršrutų, kurie realiai keičia duomenis -
        | gausite dubliuotus įrašus.
        */
        'post_routes' => [
            // 'user/personal_studies',
        ],

        /*
        | JSON/AJAX peržiūros
        |
        | Dauguma JSON atsakymų yra techniniai (statuso tikrinimai, kalbos
        | failai), tad pagal nutylėjimą jie NEfiksuojami. Bet kai kurie
        | atiduoda asmens duomenis - tokie yra reali duomenų peržiūra ir
        | juos reikia išvardinti čia.
        |
        | KADA TO REIKIA - dažniausi atvejai:
        |
        | 1) MODALŲ TURINYS. Jei modalas duomenis gauna per AJAX:
        |
        |        $('#editModal').on('show.bs.modal', function () {
        |            $.get('/admin/person/' + id, function (data) { ... });
        |        });
        |
        |    tai reali asmens duomenų peržiūra, kurios serveris kitaip
        |    neužfiksuotų kaip peržiūros. Įtraukite tokį endpoint'ą:
        |    'admin/person/*'
        |
        |    SVARBU: jei modalas duomenis skaito iš data-* atributų, jau
        |    įrašytų puslapyje renderinimo metu (dažnas atvejis), tai
        |    JOKIOS užklausos į serverį nevyksta - ir įtraukti nieko
        |    nereikia. Tie duomenys jau buvo atiduoti atidarant puslapį,
        |    o TAS atidarymas jau užfiksuotas.
        |
        | 2) AUTOCOMPLETE su asmenų sąrašais.
        |
        | 3) Bet kuris kitas AJAX, grąžinantis JSON su asmens duomenimis.
        |
        | Server-side DataTables įtraukti NEREIKIA - jie atpažįstami
        | automatiškai (žr. detect_datatables žemiau).
        |
        | KAIP SURASTI tokius endpoint'us naujame projekte: naršyklėje
        | F12 -> Network -> XHR, atidarykite kelis modalus ir puslapius su
        | lentelėmis. Jei atsiranda užklausų, grąžinančių JSON su asmens
        | duomenimis - jų URL įtraukite čia.
        */
        'json_routes' => [
            // 'admin/userInfoList/data',
            // 'admin/person/*',
        ],

        /*
        | Automatinis server-side DataTables aptikimas
        |
        | Kai lentelė pildoma per AJAX (yajra/laravel-datatables serverSide
        | režimu), puslapis įkeliamas tuščias, o realūs asmens duomenys
        | atiduodami atskira JSON užklausa. Fiksuojant tik puslapio
        | atidarymą, auditas parodytų "atidarė vartotojų sąrašą", bet ne
        | tai, kad realiai buvo atiduoti 500 asmenų duomenys.
        |
        | DataTables atsakymai atpažįstami pagal STRUKTŪRĄ (draw,
        | recordsTotal, recordsFiltered, data laukai) - tai standartizuota
        | specifikacijos dalis, tad jokio maršrutų sąrašo NEREIKIA.
        |
        | Fiksuojama: kiek įrašų atiduota, kiek iš viso egzistuoja, ko
        | buvo ieškoma (search frazė) ir kuris lapas. Paieškos frazė
        | auditui ypač vertinga - "administratorius ieškojo 'Jonaitis'"
        | pasako daugiau nei "atidarė vartotojų sąrašą".
        |
        | Veikia VISUOSE režimuose (išskyrus 'off'), nes tai realus asmens
        | duomenų atidavimas.
        */
        'detect_datatables' => env('AUDIT_LOG_DETECT_DATATABLES', true),

        /*
        | DataTables generuoja atskirą užklausą kiekvienam lapo perėjimui,
        | rikiavimui ir net kiekvienam paieškos simboliui. Vienas
        | administratorius, ieškantis žmogaus, gali sugeneruoti dešimtis
        | beveik identiškų užklausų - todėl sujungiame tas, kurios per šį
        | sekundžių langą turi tą patį URL ir tuos pačius parametrus.
        | 0 = išjungti sujungimą.
        */
        'datatables_dedup_seconds' => env('AUDIT_LOG_DATATABLES_DEDUP_SECONDS', 5),

        /*
        | Didesnių nei ši riba (baitais) JSON atsakymų nedekoduojame -
        | DataTables atsakymai su tūkstančiais įrašų gali būti kelių
        | megabaitų, o jų dekodavimas be reikalo apkrautų atmintį.
        */
        'datatables_max_decode_bytes' => 2097152,
    ],

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
