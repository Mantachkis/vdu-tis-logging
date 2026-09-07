<?php

namespace Vdu\TisLogging\Database;

use Illuminate\Database\Connection;

/**
 * Perkelia VISĄ jungties vidinę būseną iš vienos jungties objekto į kitą.
 *
 * KAM TO REIKIA: kai kurie paketai (pvz. yajra/laravel-oci8) registruoja
 * savo jungties resolverį, kuris atlieka svarbią konfigūraciją - Oracle
 * atveju tai NLS_DATE_FORMAT, NLS_NUMERIC_CHARACTERS, CURRENT_SCHEMA
 * ir kiti seanso kintamieji. Tokio resolverio PERRAŠYTI negalima -
 * sugriūtų datų formatai ir dešimtainiai skirtukai visame projekte.
 *
 * Todėl elgiamės kitaip: leidžiame originaliam resolveriui atlikti visą
 * savo darbą, o tada sukuriame savo poklasio egzempliorių ir tiksliai
 * perkopijuojame į jį visą būseną. PDO objektas lieka TAS PATS, tad
 * jau nustatyti seanso kintamieji galioja toliau.
 *
 * Naudojama refleksija, o ne rankinis setter'ių sąrašas, nes taip
 * perkeliamos ir tos savybės, kurių paketas gali turėti savo (pvz.
 * yajra $schema, $sequence, $trigger) - rankinis sąrašas jas tyliai
 * prarastų.
 */
class ConnectionStateCopier
{
    /**
     * Sukuria $targetClass egzempliorių su tiksliai tokia pačia būsena.
     *
     * @param  Connection  $source  originalaus resolverio grąžinta jungtis
     * @param  string  $targetClass  mūsų poklasis su CapturesOldValues trait
     */
    public static function copyInto(Connection $source, string $targetClass): Connection
    {
        // KRITIŠKAI SVARBU: Laravel "pdo" savybė dažnai yra Closure
        // (tinginys prisijungimas). Jei nukopijuotume patį Closure,
        // originalas ir kopija jį iškviestų ATSKIRAI - atsirastų DVI
        // skirtingos DB jungtys. Oracle atveju antroji būtų be yajra
        // nustatytų NLS seanso kintamųjų, t.y. sugriūtų datų formatai.
        // Todėl pirma priverstinai išsprendžiame Closure, kad savybėje
        // liktų konkretus, bendrai naudojamas PDO objektas.
        self::resolveLazyConnections($source);

        // Kuriame BE konstruktoriaus - konstruktorius sukurtų naujus
        // pagalbinius objektus (pvz. Sequence/Trigger), kurie perrašytų
        // originalius. Visas reikalingas būsenas perkeliame patys.
        $reflection = new \ReflectionClass($targetClass);
        $target = $reflection->newInstanceWithoutConstructor();

        foreach (self::collectProperties($source) as $property) {
            $property->setAccessible(true);
            $property->setValue($target, $property->getValue($source));
        }

        return $target;
    }

    /**
     * Paverčia Closure pavidalo PDO į konkretų objektą, kad kopija
     * dalintųsi TA PAČIA jungtimi, o ne atidarytų savo naują.
     */
    protected static function resolveLazyConnections(Connection $source): void
    {
        if (self::propertyValue($source, 'pdo') instanceof \Closure) {
            $source->getPdo();
        }

        if (self::propertyValue($source, 'readPdo') instanceof \Closure) {
            $source->getReadPdo();
        }
    }

    protected static function propertyValue(Connection $source, string $name)
    {
        $class = new \ReflectionClass($source);

        while ($class) {
            if ($class->hasProperty($name)) {
                $property = $class->getProperty($name);
                $property->setAccessible(true);

                return $property->getValue($source);
            }

            $class = $class->getParentClass();
        }

        return null;
    }

    /**
     * Surenka visas savybes iš klasės ir VISŲ jos tėvinių klasių -
     * ReflectionClass::getProperties() negrąžina privačių tėvinės
     * klasės savybių, tad einame hierarchija rankomis.
     *
     * @return \ReflectionProperty[]
     */
    protected static function collectProperties(Connection $source): array
    {
        $properties = [];
        $class = new \ReflectionClass($source);

        while ($class) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic()) {
                    continue;
                }

                // Vaikinės klasės savybė turi pirmenybę prieš tėvinę
                // tuo pačiu pavadinimu.
                if (!isset($properties[$property->getName()])) {
                    $properties[$property->getName()] = $property;
                }
            }

            $class = $class->getParentClass();
        }

        return array_values($properties);
    }
}
