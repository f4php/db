<?php

declare(strict_types=1);

namespace F4\Tests\DB\Adapter\PostgresqlAdapter;

use F4\DB\Adapter\PostgresqlAdapter\ArrayParser;
use F4\DB\Adapter\PostgresqlAdapter\ParseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArrayParserTest extends TestCase
{
    /**
     * @param array<int, string|null|array<mixed>> $expected
     */
    #[DataProvider('validLiterals')]
    public function testParsesValidLiterals(string $literal, array $expected): void
    {
        $this->assertSame($expected, new ArrayParser()->parse($literal));
    }

    /**
     * @return iterable<string, array{string, array<int, string|null|array<mixed>>}>
     */
    public static function validLiterals(): iterable
    {
        yield 'empty array' => ['{}', []];
        yield 'flat integers' => ['{1,2,3}', ['1', '2', '3']];
        yield 'unquoted null' => ['{a,b,NULL}', ['a', 'b', null]];
        yield 'lowercase null' => ['{null}', [null]];
        yield 'quoted delimiters and braces' => ['{"a,b","c}d"}', ['a,b', 'c}d']];
        yield 'quoted null stays string' => ['{"NULL"}', ['NULL']];
        yield 'backslash escapes' => ['{"a\\"b","c\\\\d"}', ['a"b', 'c\\d']];
        yield 'unquoted whitespace trimmed' => ['{ 1 , 2 }', ['1', '2']];
        yield 'nested' => ['{{1,2},{3,4}}', [['1', '2'], ['3', '4']]];
        yield 'dimension prefix' => ['[1:3]={1,2,3}', ['1', '2', '3']];
        yield 'multi-dimension prefix' => ['[-2:0][1:2]={{1,2},{3,4},{5,6}}', [['1', '2'], ['3', '4'], ['5', '6']]];
        yield 'utf-8 preserved' => ['{"héllo","wörld"}', ['héllo', 'wörld']];
        yield 'quoted whitespace preserved' => ['{" a "}', [' a ']];
    }

    public function testParsesWithCustomDelimiter(): void
    {
        $this->assertSame(['a', 'b'], new ArrayParser()->parse('{a;b}', ';'));
    }

    #[DataProvider('malformedLiterals')]
    public function testRejectsMalformedLiterals(string $literal): void
    {
        $this->expectException(ParseException::class);

        new ArrayParser()->parse($literal);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedLiterals(): iterable
    {
        yield 'unterminated array' => ['{1,2'];
        yield 'unterminated quote' => ['{"unterminated}'];
        yield 'trailing brace' => ['{1}}'];
        yield 'not an array' => ['abc'];
        yield 'missing opening brace' => ['1,2,3}'];
        yield 'trailing content' => ['{1,2} extra'];
        yield 'dangling delimiter' => ['{1,}'];
        yield 'empty element' => ['{1,,2}'];
    }

    public function testRejectsMultiCharacterDelimiter(): void
    {
        $this->expectException(ParseException::class);

        new ArrayParser()->parse('{a,b}', ', ');
    }
}
