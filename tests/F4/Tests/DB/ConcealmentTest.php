<?php

declare(strict_types=1);

namespace F4\Tests\DB;

use F4\DB;
use F4\DB\Adapter\SqliteAdapter;
use F4\DB\Exception\ConcealedColumnNotFoundException;
use F4\DB\Exception\PostSubmitHookException;
use F4\DB\QueryBuilder;
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
}
