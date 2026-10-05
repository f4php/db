<?php

declare(strict_types=1);

namespace F4\Tests\DB;

use F4\DB;
use F4\DB\Adapter\AdapterInterface;
use F4\DB\Adapter\SqliteAdapter;
use F4\DB\Exception\ConcealedColumnNotFoundException;
use F4\DB\Exception\PostSubmitHookException;
use F4\DB\PreparedStatement;
use F4\DB\QueryBuilderInterface;
use F4\HookManager;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[RequiresPhpExtension('sqlite3')]
final class ConcealmentTest extends TestCase
{
    private SqliteAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new SqliteAdapter(':memory:');
        $this->query()->raw(['CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, password_hash TEXT, totp_secret TEXT)'])->commit();
        $this->query()->insert()->into('users')->values(['id' => 1, 'name' => 'alice', 'password_hash' => 'h1', 'totp_secret' => 's1'])->commit();
        $this->query()->insert()->into('users')->values(['id' => 2, 'name' => 'bob', 'password_hash' => 'h2', 'totp_secret' => 's2'])->commit();
    }

    protected function tearDown(): void
    {
        HookManager::resetHooks(HookManager::AFTER_SQL_SUBMIT);
    }

    private function query(): DB
    {
        return new DB(adapter: $this->adapter);
    }

    private function users(): QueryBuilderInterface
    {
        return $this->query()->select()->from('users')->orderBy('id');
    }

    public function testConcealingRemovesKeysFromEveryRow(): void
    {
        $this->assertSame(
            [
                ['id' => 1, 'name' => 'alice', 'totp_secret' => 's1'],
                ['id' => 2, 'name' => 'bob', 'totp_secret' => 's2'],
            ],
            $this->users()->concealing('password_hash')->asTable(),
        );
    }

    public function testRevealingAfterConcealingReveals(): void
    {
        $this->assertSame(
            ['id' => 1, 'name' => 'alice', 'password_hash' => 'h1', 'totp_secret' => 's1'],
            $this->users()->concealing('password_hash')->revealing('password_hash')->asRow(),
        );
    }

    public function testConcealingAfterRevealingConceals(): void
    {
        $this->assertSame(
            ['id' => 1, 'name' => 'alice', 'totp_secret' => 's1'],
            $this->users()->concealing('password_hash')->revealing('password_hash')->concealing('password_hash')->asRow(),
        );
    }

    public function testRevealingWithoutConcealingIsNoOp(): void
    {
        $this->assertSame(
            ['id' => 1, 'name' => 'alice', 'password_hash' => 'h1', 'totp_secret' => 's1'],
            $this->users()->revealing('password_hash')->asRow(),
        );
    }

    public static function argumentFormsProvider(): array
    {
        return [
            'variadic strings' => [['password_hash', 'totp_secret']],
            'single array' => [[['password_hash', 'totp_secret']]],
            'mixed' => [['password_hash', ['totp_secret']]],
            'nested arrays' => [[[['password_hash'], ['x' => 'totp_secret']]]],
            'repeated chained-equivalent' => [['password_hash', 'totp_secret', 'password_hash']],
        ];
    }

    #[DataProvider('argumentFormsProvider')]
    public function testArgumentFormsAreEquivalent(array $arguments): void
    {
        $this->assertSame(
            ['id' => 1, 'name' => 'alice'],
            $this->users()->concealing(...$arguments)->asRow(),
        );
    }

    public function testRevealingAcceptsSameArgumentForms(): void
    {
        $this->assertSame(
            ['id' => 1, 'name' => 'alice', 'password_hash' => 'h1', 'totp_secret' => 's1'],
            $this->users()->concealing('password_hash', 'totp_secret')->revealing(['password_hash', ['totp_secret']])->asRow(),
        );
    }

    public static function invalidArgumentsProvider(): array
    {
        return [
            'int' => [[1]],
            'empty string' => [['']],
            'null' => [[null]],
            'object' => [[new stdClass()]],
            'nested int' => [[['password_hash', [42]]]],
        ];
    }

    #[DataProvider('invalidArgumentsProvider')]
    public function testInvalidArgumentsThrowOnConcealing(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->users()->concealing(...$arguments);
    }

    #[DataProvider('invalidArgumentsProvider')]
    public function testInvalidArgumentsThrowOnRevealing(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->users()->revealing(...$arguments);
    }

    public function testAsValueByNameReturnsNullForConcealedKey(): void
    {
        $this->assertNull($this->users()->concealing('password_hash')->asValue('password_hash'));
    }

    public function testAsValueByIndexCountsColumnsAfterConcealment(): void
    {
        $this->assertSame('s1', $this->users()->concealing('password_hash')->asValue(2));
    }

    public function testAfterSubmitHookReceivesConcealedResult(): void
    {
        $observed = null;
        HookManager::addHook(HookManager::AFTER_SQL_SUBMIT, function (array $context) use (&$observed): void {
            $observed = $context['result'];
        });
        $this->users()->concealing('password_hash', 'totp_secret')->asTable();
        $this->assertSame([['id' => 1, 'name' => 'alice'], ['id' => 2, 'name' => 'bob']], $observed);
    }

    public function testPostSubmitHookExceptionCarriesConcealedResult(): void
    {
        HookManager::addHook(HookManager::AFTER_SQL_SUBMIT, function (): void {
            throw new RuntimeException('after-hook failed');
        });
        try {
            $this->users()->concealing('password_hash', 'totp_secret')->asTable();
            $this->fail('Expected PostSubmitHookException to be thrown');
        } catch (PostSubmitHookException $exception) {
            $this->assertSame([['id' => 1, 'name' => 'alice'], ['id' => 2, 'name' => 'bob']], $exception->getResult());
        }
    }

    public function testClonesHaveIndependentConcealmentState(): void
    {
        $base = $this->users()->concealing('password_hash');
        $revealed = (clone $base)->revealing('password_hash');
        $this->assertArrayNotHasKey('password_hash', $base->asRow());
        $this->assertArrayHasKey('password_hash', $revealed->asRow());
    }

    public function testConcealmentDoesNotAffectGeneratedSql(): void
    {
        $plain = $this->users();
        $concealed = $this->users()->concealing('password_hash');
        $this->assertSame($plain->getPreparedStatement()->query, $concealed->getPreparedStatement()->query);
        $this->assertSame($plain->asSQL(), $concealed->asSQL());
    }

    public function testStrictModeThrowsWhenConcealedKeyIsMissingFromResult(): void
    {
        $this->expectException(ConcealedColumnNotFoundException::class);
        $this->expectExceptionMessage('"pasword_hash"');
        $this->users()->concealing('pasword_hash')->asTable();
    }

    public function testStrictModeThrowsBeforeAfterSubmitHook(): void
    {
        $observed = false;
        HookManager::addHook(HookManager::AFTER_SQL_SUBMIT, function () use (&$observed): void {
            $observed = true;
        });
        try {
            $this->users()->concealing('pasword_hash')->asTable();
            $this->fail('Expected ConcealedColumnNotFoundException to be thrown');
        } catch (ConcealedColumnNotFoundException) {
            $this->assertFalse($observed);
        }
    }

    public function testStrictModeAllowsEmptyResult(): void
    {
        $this->assertSame([], $this->query()->select()->from('users')->where(['id' => 999])->concealing('pasword_hash')->asTable());
        $this->assertSame([], $this->query()->delete()->from('users')->where(['id' => 999])->concealing('pasword_hash')->commit());
    }

    public function testStrictModeIgnoresRevealedKeys(): void
    {
        $this->assertSame(
            ['id' => 1, 'name' => 'alice', 'password_hash' => 'h1', 'totp_secret' => 's1'],
            $this->users()->concealing('pasword_hash')->revealing('pasword_hash')->asRow(),
        );
    }

    public function testPlainKeyAndPathAreTheSameRule(): void
    {
        $this->assertArrayHasKey('password_hash', $this->users()->concealing('password_hash')->revealing('$.password_hash')->asRow());
        $this->assertArrayHasKey('password_hash', $this->users()->concealing('$["password_hash"]')->revealing('password_hash')->asRow());
        $this->assertArrayNotHasKey('password_hash', $this->users()->revealing('password_hash')->concealing('$.password_hash')->asRow());
    }

    public function testInvalidPathThrowsAtCallTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->users()->concealing('$.employees[*].passwordHash');
    }

    public function testRulesHaveNoHierarchy(): void
    {
        $adapter = self::fixtureAdapter([['id' => 1, 'lead' => ['name' => 'n', 'passwordHash' => 'h']]]);
        $query = fn(): QueryBuilderInterface => new DB(adapter: $adapter)->select()->from('t');
        $this->assertSame([['id' => 1]], $query()->concealing('$.lead')->revealing('$.lead.passwordHash')->asTable());
        $this->assertSame(
            [['id' => 1, 'lead' => ['name' => 'n']]],
            $query()->concealing('$.lead.passwordHash')->revealing('$.lead')->asTable(),
        );
    }

    /**
     * Mirrors a PostgreSQL LEFT JOIN LATERAL with to_jsonb() / jsonb_agg(), whose
     * jsonb columns the PostgreSQL adapter decodes into PHP arrays.
     */
    public function testConcealsInsideLateralJoinedRelations(): void
    {
        $employee = fn(string $uuid): array => ['employeeUUID' => $uuid, 'name' => "n{$uuid}", 'passwordHash' => "h{$uuid}"];
        $adapter = self::fixtureAdapter([
            ['contractorUUID' => 'c1', 'leadEmployee' => $employee('e1'), 'employees' => [$employee('e1'), $employee('e2')]],
            ['contractorUUID' => 'c2', 'leadEmployee' => null, 'employees' => null],
        ]);
        $query = new DB(adapter: $adapter)
            ->select([
                'contractor.*',
                '"employee"."relation_jsonb" AS "leadEmployee"',
                '"employees"."relation_jsonb" AS "employees"',
            ])
            ->from('contractor')
            ->leftJoinLateral([
                '({#::#}) AS "employee"' => DB::select('to_jsonb("employee".*) AS "relation_jsonb"')
                    ->from('employee')
                    ->where(['"contractor"."leadEmployeeUUID" = "employee"."employeeUUID"']),
            ])
            ->on('true')
            ->leftJoinLateral([
                '({#::#}) AS "employees"' => DB::select('jsonb_agg(to_jsonb("employee".*)) AS "relation_jsonb"')
                    ->from('employee')
                    ->where(['"employee"."contractorUUID" = "contractor"."contractorUUID"']),
            ])
            ->on('true');
        $sql = $query->getPreparedStatement()->query;
        $query->concealing('$.leadEmployee.passwordHash', '$.employees.passwordHash');
        $this->assertSame($sql, $query->getPreparedStatement()->query);
        $this->assertSame(
            [
                [
                    'contractorUUID' => 'c1',
                    'leadEmployee' => ['employeeUUID' => 'e1', 'name' => 'ne1'],
                    'employees' => [['employeeUUID' => 'e1', 'name' => 'ne1'], ['employeeUUID' => 'e2', 'name' => 'ne2']],
                ],
                ['contractorUUID' => 'c2', 'leadEmployee' => null, 'employees' => null],
            ],
            $query->asTable(),
        );
    }

    public function testStrictModeThrowsOnNestedTypo(): void
    {
        $adapter = self::fixtureAdapter([['id' => 1, 'lead' => ['name' => 'n', 'passwordHash' => 'h']]]);
        $this->expectException(ConcealedColumnNotFoundException::class);
        $this->expectExceptionMessage('Concealed key "pasword" not found at $.lead');
        new DB(adapter: $adapter)->select()->from('t')->concealing('$.lead.pasword')->asTable();
    }

    public function testHooksReceiveNestedConcealedResult(): void
    {
        $observed = null;
        HookManager::addHook(HookManager::AFTER_SQL_SUBMIT, function (array $context) use (&$observed): void {
            $observed = $context['result'];
        });
        $adapter = self::fixtureAdapter([['id' => 1, 'lead' => [['name' => 'n', 'passwordHash' => 'h']]]]);
        new DB(adapter: $adapter)->select()->from('t')->concealing('$.lead.passwordHash')->asTable();
        $this->assertSame([['id' => 1, 'lead' => [['name' => 'n']]]], $observed);
    }

    public function testConcealsInsideSqliteJsonDecodedByResultConverter(): void
    {
        $adapter = new SqliteAdapter(
            ':memory:',
            resultConverter: fn(mixed $value, string $columnName): mixed => match ($columnName) {
                'lead', 'everyone' => $value === null ? null : json_decode($value, true, flags: JSON_THROW_ON_ERROR),
                default => $value,
            },
        );
        $query = fn(): DB => new DB(adapter: $adapter);
        $query()->raw(['CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, password_hash TEXT)'])->commit();
        $query()->insert()->into('users')->values(['id' => 1, 'name' => 'alice', 'password_hash' => 'h1'])->commit();
        $query()->insert()->into('users')->values(['id' => 2, 'name' => 'bob', 'password_hash' => 'h2'])->commit();
        $this->assertSame(
            [
                'lead' => ['id' => 1, 'name' => 'alice'],
                'everyone' => [['id' => 1, 'name' => 'alice'], ['id' => 2, 'name' => 'bob']],
            ],
            $query()
                ->select([
                    '(SELECT json_object(\'id\', id, \'name\', name, \'password_hash\', password_hash) FROM users WHERE id = 1) AS lead',
                    '(SELECT json_group_array(json_object(\'id\', id, \'name\', name, \'password_hash\', password_hash)) FROM (SELECT * FROM users ORDER BY id)) AS everyone',
                ])
                ->concealing('$.lead.password_hash', '$.everyone.password_hash')
                ->asRow(),
        );
    }

    private static function fixtureAdapter(array $rows): AdapterInterface
    {
        return new class($rows) implements AdapterInterface {
            private MockAdapter $mock;
            public function __construct(private array $rows)
            {
                $this->mock = new MockAdapter();
            }
            public function execute(PreparedStatement $statement, ?int $stopAfter = null): mixed
            {
                return $stopAfter === null ? $this->rows : array_slice($this->rows, 0, $stopAfter);
            }
            public function discardConnection(): void {}
            public function enumerateParameters(int $index): string
            {
                return $this->mock->enumerateParameters($index);
            }
            public function getEscapedBinary(string $value): string
            {
                return $this->mock->getEscapedBinary($value);
            }
            public function getEscapedValue(mixed $value): string
            {
                return $this->mock->getEscapedValue($value);
            }
            public function getEscapedIdentifier(string $identifier): string
            {
                return $this->mock->getEscapedIdentifier($identifier);
            }
        };
    }
}
