<?php

namespace Vdu\TisLogging\Tests;

use Vdu\TisLogging\Support\SqlStatementParser;

class SqlStatementParserTest extends TestCase
{
    protected function parser(): SqlStatementParser
    {
        return new SqlStatementParser();
    }

    /** @test */
    public function it_parses_oracle_style_update_with_double_quotes_and_uppercase()
    {
        // Tikslus formatas iš realaus VDU TIS žurnalo (Oracle jungtis).
        $sql = 'update "MAKADEMIJA_STUDY_DESC" set "DESCRIPTION" = ?, "NAME" = ?, "BANNER" = ? where "TKODS" = ?';
        $bindings = ['Aprašymas', 'Pavadinimas', null, 'MA7000'];

        $result = $this->parser()->parse($sql, $bindings);

        $this->assertSame('MAKADEMIJA_STUDY_DESC', $result['table']);
        $this->assertSame('Aprašymas', $result['values']['DESCRIPTION']);
        $this->assertSame('Pavadinimas', $result['values']['NAME']);
        $this->assertNull($result['values']['BANNER']);
        $this->assertSame('MA7000', $result['conditions']['TKODS']);
    }

    /** @test */
    public function it_parses_mysql_style_update_with_backticks()
    {
        $sql = 'update `news` set `title` = ?, `sort` = ? where `id` = ?';
        $bindings = ['Antraštė', 3, 42];

        $result = $this->parser()->parse($sql, $bindings);

        $this->assertSame('news', $result['table']);
        $this->assertSame('Antraštė', $result['values']['title']);
        $this->assertSame(3, $result['values']['sort']);
        $this->assertSame(42, $result['conditions']['id']);
    }

    /** @test */
    public function it_parses_insert_statements()
    {
        $sql = 'insert into "users" ("name", "email") values (?, ?)';
        $bindings = ['Jonas', 'jonas@vdu.lt'];

        $result = $this->parser()->parse($sql, $bindings);

        $this->assertSame('users', $result['table']);
        $this->assertSame('Jonas', $result['values']['name']);
        $this->assertSame('jonas@vdu.lt', $result['values']['email']);
        $this->assertNull($result['conditions']);
    }

    /** @test */
    public function it_parses_delete_statements()
    {
        $sql = 'delete from "news" where "id" = ?';
        $bindings = [7];

        $result = $this->parser()->parse($sql, $bindings);

        $this->assertSame('news', $result['table']);
        $this->assertNull($result['values']);
        $this->assertSame(7, $result['conditions']['id']);
    }

    /** @test */
    public function it_handles_updates_with_multiple_where_conditions()
    {
        $sql = 'update "logs" set "status" = ? where "user_id" = ? and "type" = ?';
        $bindings = ['done', 5, 'export'];

        $result = $this->parser()->parse($sql, $bindings);

        $this->assertSame('done', $result['values']['status']);
        $this->assertSame(5, $result['conditions']['user_id']);
        $this->assertSame('export', $result['conditions']['type']);
    }

    /** @test */
    public function it_returns_nulls_for_unparseable_statements()
    {
        $result = $this->parser()->parse('select * from users', []);

        $this->assertNull($result['table']);
        $this->assertNull($result['values']);
    }

    /** @test */
    public function it_strips_the_oracle_schema_prefix_from_the_table_name()
    {
        // Oracle lenteles varda pateikia su schema: "LUADM"."SSO_USERS".
        // Be prefikso salinimo jis nesutaptu su Eloquent getTable()
        // reiksme, ir dubliavimosi vengimas nesuveiktu.
        $sql = 'update "LUADM"."SSO_USERS" set "REMEMBER_TOKEN" = ? where "ID" = ?';

        $result = $this->parser()->parse($sql, ['raktas', 33131]);

        $this->assertSame('SSO_USERS', $result['table']);
    }

    /** @test */
    public function it_strips_a_plain_schema_prefix_too()
    {
        $result = $this->parser()->parse('delete from myschema.news where "id" = ?', [7]);

        $this->assertSame('news', $result['table']);
    }

    /** @test */
    public function a_table_without_schema_prefix_is_unchanged()
    {
        $result = $this->parser()->parse('insert into "users" ("name") values (?)', ['Jonas']);

        $this->assertSame('users', $result['table']);
    }
}
