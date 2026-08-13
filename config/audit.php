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
    */
    'exclude' => ['password', 'remember_token'],

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
