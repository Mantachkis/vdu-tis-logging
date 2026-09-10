<?php

namespace Vdu\TisLogging\Support;

/**
 * Ištraukia iš SQL sakinio lentelės pavadinimą ir stulpelius, tada
 * suriša stulpelius su atitinkamais parametrais (bindings).
 *
 * Be šito žurnalo įrašas būtų praktiškai neskaitomas - stulpelių
 * pavadinimai SQL sakinyje, o reikšmės atskirame poziciniame masyve,
 * tad administratoriui tektų juos suvesti mintyse.
 *
 * Palaiko standartinę Laravel Query Builder generuojamą sintaksę su
 * bet kokiais identifikatorių skirtukais (" MySQL/Oracle/Postgres,
 * ` MySQL, [ ] SQL Server).
 *
 * SVARBU: tai teksto analizė, ne pilnas SQL parseris. Sudėtingoms
 * užklausoms (subqueries, JOIN, CASE WHEN) gali nepavykti - tokiu
 * atveju grąžinama null, o žurnale lieka žalias SQL su parametrais,
 * kaip ir anksčiau.
 */
class SqlStatementParser
{
    /**
     * @return array{table: ?string, values: ?array, conditions: ?array}
     */
    public function parse(string $sql, array $bindings): array
    {
        $statement = strtolower(strtok(trim($sql), " \t\n"));

        switch ($statement) {
            case 'update':
                return $this->parseUpdate($sql, $bindings);
            case 'insert':
                return $this->parseInsert($sql, $bindings);
            case 'delete':
                return $this->parseDelete($sql, $bindings);
            default:
                return ['table' => null, 'values' => null, 'conditions' => null];
        }
    }

    protected function parseUpdate(string $sql, array $bindings): array
    {
        $table = $this->extractTable($sql, '/^\s*update\s+([^\s]+)\s+set\s/i');

        // SET dalis - tarp "set" ir "where" (arba iki galo, jei WHERE nėra).
        if (!preg_match('/\sset\s+(.+?)(?:\s+where\s+(.+))?$/is', $sql, $m)) {
            return ['table' => $table, 'values' => null, 'conditions' => null];
        }

        $setPart = $m[1];
        $wherePart = $m[2] ?? null;

        $setColumns = $this->extractAssignedColumns($setPart);

        // Parametrai eina ta pačia tvarka: pirma SET, tada WHERE.
        $values = $this->zip($setColumns, array_slice($bindings, 0, count($setColumns)));

        $conditions = null;
        if ($wherePart !== null) {
            $whereColumns = $this->extractAssignedColumns($wherePart);
            $conditions = $this->zip($whereColumns, array_slice($bindings, count($setColumns)));
        }

        return ['table' => $table, 'values' => $values, 'conditions' => $conditions];
    }

    protected function parseInsert(string $sql, array $bindings): array
    {
        $table = $this->extractTable($sql, '/^\s*insert\s+into\s+([^\s(]+)/i');

        if (!preg_match('/\(\s*(.+?)\s*\)\s*values/is', $sql, $m)) {
            return ['table' => $table, 'values' => null, 'conditions' => null];
        }

        $columns = array_map(
            function ($c) { return $this->unquote(trim($c)); },
            explode(',', $m[1])
        );

        return [
            'table' => $table,
            'values' => $this->zip($columns, array_slice($bindings, 0, count($columns))),
            'conditions' => null,
        ];
    }

    protected function parseDelete(string $sql, array $bindings): array
    {
        $table = $this->extractTable($sql, '/^\s*delete\s+from\s+([^\s]+)/i');

        $conditions = null;
        if (preg_match('/\swhere\s+(.+)$/is', $sql, $m)) {
            $whereColumns = $this->extractAssignedColumns($m[1]);
            $conditions = $this->zip($whereColumns, $bindings);
        }

        return ['table' => $table, 'values' => null, 'conditions' => $conditions];
    }

    protected function extractTable(string $sql, string $pattern): ?string
    {
        if (preg_match($pattern, $sql, $m)) {
            return $this->unquote($m[1]);
        }

        return null;
    }

    /**
     * Ištraukia stulpelių pavadinimus iš "stulpelis = ?" tipo išraiškų.
     * Įtraukiami TIK tie, kurių reikšmė yra "?" placeholder'is - kitaip
     * sutriktų parametrų pozicijų atitikimas.
     */
    protected function extractAssignedColumns(string $part): array
    {
        preg_match_all('/([`"\[]?[\w.]+[`"\]]?)\s*(?:=|<>|!=|>=|<=|>|<|\slike\s)\s*\?/i', $part, $m);

        return array_map(function ($c) { return $this->unquote($c); }, $m[1]);
    }

    protected function zip(array $columns, array $values): ?array
    {
        if (empty($columns)) {
            return null;
        }

        $result = [];

        foreach ($columns as $i => $column) {
            $result[$column] = $values[$i] ?? null;
        }

        return $result;
    }

    /**
     * Nuvalo identifikatoriaus skirtukus ir schemos prefiksą.
     *
     * Oracle (ir kai kurie kiti) lentelės vardą pateikia su schema:
     * "LUADM"."SSO_USERS". Paliekame tik patį lentelės vardą - kitaip
     * jis nesutaptų su Eloquent modelio getTable() reikšme, ir
     * dubliavimosi vengimas nesuveiktų.
     */
    protected function unquote(string $identifier): string
    {
        $identifier = trim($identifier, '`"[] ');

        // Schemos prefiksas: LUADM"."SSO_USERS arba schema.table
        if (strpos($identifier, '.') !== false) {
            $parts = preg_split('/["`\]\[]*\.["`\]\[]*/', $identifier);
            $identifier = trim((string) end($parts), '`"[] ');
        }

        return $identifier;
    }
}
