<?php

declare(strict_types=1);

namespace F4\Tests\DB;

use F4\DB\Concealment\Concealer;
use F4\DB\Concealment\ConcealmentPath;
use F4\DB\Exception\ConcealedColumnNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConcealerTest extends TestCase
{
    private static function concealer(string ...$paths): Concealer
    {
        return new Concealer(...array_map(ConcealmentPath::fromString(...), $paths));
    }

    private static function employee(string $uuid): array
    {
        return ['employeeUUID' => $uuid, 'name' => "n{$uuid}", 'passwordHash' => "h{$uuid}"];
    }

    public function testNoPathsReturnsResultUnchanged(): void
    {
        $result = [['a' => 1]];
        $this->assertSame($result, self::concealer()->apply($result, true));
    }

    public function testConcealsTopLevelKeys(): void
    {
        $this->assertSame(
            [['id' => 1], ['id' => 2]],
            self::concealer('secret')->apply([['id' => 1, 'secret' => 'x'], ['id' => 2, 'secret' => 'y']], true),
        );
    }

    public function testConcealsKeyInSingleRelatedObject(): void
    {
        $this->assertSame(
            [['id' => 1, 'leadEmployee' => ['employeeUUID' => 'e1', 'name' => 'ne1']]],
            self::concealer('$.leadEmployee.passwordHash')->apply([['id' => 1, 'leadEmployee' => self::employee('e1')]], true),
        );
    }

    public function testConcealsKeyInEveryElementOfRelatedList(): void
    {
        $this->assertSame(
            [['id' => 1, 'employees' => [
                ['employeeUUID' => 'e1', 'name' => 'ne1'],
                ['employeeUUID' => 'e2', 'name' => 'ne2'],
            ]]],
            self::concealer('$.employees.passwordHash')->apply([['id' => 1, 'employees' => [self::employee('e1'), self::employee('e2')]]], true),
        );
    }

    public function testTraversesNestedLists(): void
    {
        $this->assertSame(
            [['groups' => [[['employeeUUID' => 'e1', 'name' => 'ne1']], [['employeeUUID' => 'e2', 'name' => 'ne2']]]]],
            self::concealer('$.groups.passwordHash')->apply([['groups' => [[self::employee('e1')], [self::employee('e2')]]]], true),
        );
    }

    public function testConcealsDeeplyNestedKeys(): void
    {
        $this->assertSame(
            [['contractor' => ['leadEmployee' => ['name' => 'x', 'auth' => ['login' => 'l']]]]],
            self::concealer('$.contractor.leadEmployee.auth.passwordHash')->apply(
                [['contractor' => ['leadEmployee' => ['name' => 'x', 'auth' => ['login' => 'l', 'passwordHash' => 'h']]]]],
                true,
            ),
        );
    }

    public function testMultiplePathsShareOneWalk(): void
    {
        $this->assertSame(
            [['leadEmployee' => ['employeeUUID' => 'e1'], 'employees' => [['employeeUUID' => 'e2', 'name' => 'ne2']]]],
            self::concealer('$.leadEmployee.passwordHash', '$.leadEmployee.name', '$.employees.passwordHash')->apply(
                [['leadEmployee' => self::employee('e1'), 'employees' => [self::employee('e2')]]],
                true,
            ),
        );
    }

    public function testAncestorPathConcealsWholeSubtreeRegardlessOfOrder(): void
    {
        $rows = [['id' => 1, 'leadEmployee' => self::employee('e1')]];
        $this->assertSame([['id' => 1]], self::concealer('$.leadEmployee', '$.leadEmployee.passwordHash')->apply($rows, true));
        $this->assertSame([['id' => 1]], self::concealer('$.leadEmployee.passwordHash', '$.leadEmployee')->apply($rows, true));
    }

    public static function emptyRelationsProvider(): array
    {
        return [
            'null relation' => [null],
            'empty list' => [[]],
            'list with null element' => [[null]],
        ];
    }

    #[DataProvider('emptyRelationsProvider')]
    public function testEmptyRelationsAreSkippedInStrictMode(mixed $relation): void
    {
        $rows = [['id' => 1, 'leadEmployee' => $relation]];
        $this->assertSame($rows, self::concealer('$.leadEmployee.passwordHash')->apply($rows, true));
    }

    public function testEmptyResultIsSkippedInStrictMode(): void
    {
        $this->assertSame([], self::concealer('$.missing.key')->apply([], true));
    }

    public function testStrictModeThrowsOnMissingTopLevelKey(): void
    {
        $this->expectException(ConcealedColumnNotFoundException::class);
        $this->expectExceptionMessage('Concealed key "pasword_hash" not found at $');
        self::concealer('pasword_hash')->apply([['id' => 1]], true);
    }

    public function testStrictModeThrowsOnMissingNestedKey(): void
    {
        $this->expectException(ConcealedColumnNotFoundException::class);
        $this->expectExceptionMessage('Concealed key "pasword" not found at $.leadEmployee');
        self::concealer('$.leadEmployee.pasword')->apply([['leadEmployee' => self::employee('e1')]], true);
    }

    public function testStrictModeThrowsOnMissingIntermediateKey(): void
    {
        $this->expectException(ConcealedColumnNotFoundException::class);
        $this->expectExceptionMessage('Concealed key "auth" not found at $.leadEmployee');
        self::concealer('$.leadEmployee.auth.passwordHash')->apply([['leadEmployee' => self::employee('e1')]], true);
    }

    public function testStrictModeThrowsWhenAnyListElementLacksKey(): void
    {
        $this->expectException(ConcealedColumnNotFoundException::class);
        self::concealer('$.employees.passwordHash')->apply([['employees' => [self::employee('e1'), ['employeeUUID' => 'e2']]]], true);
    }

    public function testStrictModeThrowsWhenAnyRowLacksKey(): void
    {
        $this->expectException(ConcealedColumnNotFoundException::class);
        self::concealer('secret')->apply([['id' => 1, 'secret' => 'x'], ['id' => 2]], true);
    }

    public static function scalarsProvider(): array
    {
        return [
            'string (undecoded json)' => ['{"passwordHash":"h"}', 'string'],
            'int' => [1, 'int'],
            'bool' => [true, 'bool'],
            'list of scalars' => [['a', 'b'], 'string'],
        ];
    }

    #[DataProvider('scalarsProvider')]
    public function testStrictModeThrowsOnNonTraversableValue(mixed $value, string $type): void
    {
        $this->expectException(ConcealedColumnNotFoundException::class);
        $this->expectExceptionMessage("Expected an object or a list at \$.leadEmployee, got {$type}");
        self::concealer('$.leadEmployee.passwordHash')->apply([['leadEmployee' => $value]], true);
    }

    public function testNonStrictModeSkipsMissingKeysAndScalars(): void
    {
        $rows = [
            ['id' => 1, 'secret' => 'x', 'leadEmployee' => '{"passwordHash":"h"}', 'employees' => [self::employee('e1'), ['employeeUUID' => 'e2']]],
            ['id' => 2, 'leadEmployee' => self::employee('e3'), 'employees' => []],
        ];
        $this->assertSame(
            [
                ['id' => 1, 'leadEmployee' => '{"passwordHash":"h"}', 'employees' => [['employeeUUID' => 'e1', 'name' => 'ne1'], ['employeeUUID' => 'e2']]],
                ['id' => 2, 'leadEmployee' => ['employeeUUID' => 'e3', 'name' => 'ne3'], 'employees' => []],
            ],
            self::concealer('secret', 'missing', '$.leadEmployee.passwordHash', '$.employees.passwordHash')->apply($rows, false),
        );
    }

    public function testNumericKeysAreMatched(): void
    {
        $this->assertSame(
            [['data' => ['x' => ['b' => 2]]]],
            self::concealer('$.data.x.1')->apply([['data' => ['x' => ['b' => 2, '1' => 'secret']]]], true),
        );
    }

    public function testNonArrayRowsArePassedThrough(): void
    {
        $this->assertSame(['scalar'], self::concealer('secret')->apply(['scalar'], true));
    }
}
