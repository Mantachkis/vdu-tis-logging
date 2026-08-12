# Changelog

Visi svarbūs paketo pakeitimai fiksuojami šiame faile.
Versijavimas pagal [Semantic Versioning](https://semver.org/): MAJOR.MINOR.PATCH.

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
