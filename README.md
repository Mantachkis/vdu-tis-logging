# vdu/audit-log

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
- [ ] 2 etapas – EventLogger branduolys (Monolog + PSR-3)
- [ ] 3 etapas – integraciniai hook'ai (auth, model, view)
- [ ] 4 etapas – diegimo automatizavimas (`audit:install` komanda)
- [ ] 5 etapas – versijavimas ir platinimo kanalas
- [ ] 6 etapas – pilotinis diegimas
- [ ] 7 etapas – diegimas į visus projektus
- [ ] 8 etapas – centralizuotas monitoringas

## Reikalavimai

- PHP ^7.1.3
- Laravel ~5.7.0

## Diegimas (kai paketas bus paruoštas naudoti)

```bash
composer require vdu/audit-log
php artisan vendor:publish --tag=audit-config
```

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
    ├── adresas1/
    │   ├── audit.log       ← info, security, system įvykiai
    │   └── error.log       ← error IR warning tipo įvykiai
    ├── adresas2/
    │   ├── audit.log
    │   └── error.log
```

**Svarbu:** `AUDIT_LOG_BASE_PATH` VISADA reikia nurodyti eksplicitiškai kiekviename projekto `.env`
faile. Automatinis `getenv('HOME')` fallback (jei kintamasis nenurodytas) grąžintų projekto
vartotojo home katalogą (pvz. `/home/studentas`), o NE bendrą `/home/logs` katalogą - tai reikštų,
kad kiekvienas projektas rašytų į savo atskirą, izoliuotą vietą, o ne į bendrą, visiems
administratoriams matomą žurnalų katalogą.

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
