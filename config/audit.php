<?php

return [

    /*
    |--------------------------------------------------------------------
    | Aplikacijos (sistemos) identifikatorius
    |--------------------------------------------------------------------
    |
    | Naudojamas kaip poaplankio pavadinimas šakniniame žurnalų kataloge,
    | pvz. "project-a" -> ~/logs/project-a/audit.log ir ~/logs/project-a/error.log
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
    | Audit ir error failų pavadinimai
    |--------------------------------------------------------------------
    |
    | Įvykiai skirstomi į du atskirus failus pagal event_type:
    | - "error" ir "warning" tipo įvykiai -> error_filename
    | - "info", "security", "system" -> audit_filename
    |
    | Galutinis kelias: {base_path}/{app_name}/{*_filename}
    |
    */
    'audit_filename' => env('AUDIT_LOG_AUDIT_FILENAME', 'audit.log'),
    'error_filename' => env('AUDIT_LOG_ERROR_FILENAME', 'error.log'),

    /*
    |--------------------------------------------------------------------
    | Saugojimo terminas dienomis
    |--------------------------------------------------------------------
    |
    | 0 = niekada automatiškai netrinti (rotaciją/archyvavimą tvarko OS
    | lygmens logrotate arba atskira BDAR retencijos politika).
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
