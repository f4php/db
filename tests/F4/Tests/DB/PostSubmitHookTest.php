<?php

declare(strict_types=1);

namespace F4\Tests\DB;

use F4\DB;
use F4\DB\Adapter\SqliteAdapter;
use F4\DB\Exception\PostSubmitHookException;
use F4\HookManager;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[RequiresPhpExtension('sqlite3')]
final class PostSubmitHookTest extends TestCase
{
    private SqliteAdapter $adapter;

    protected function setUp(): void
    {
        // One adapter instance keeps a single live :memory: connection. Each query
        // uses a fresh DB (fresh builder) but shares this adapter, so a follow-up
        // query observes writes made by earlier queries.
        $this->adapter = new SqliteAdapter(':memory:');
        $this->query()->raw(['CREATE TABLE items (id INTEGER PRIMARY KEY)'])->commit();
    }

    protected function tearDown(): void
    {
        HookManager::resetHooks(HookManager::AFTER_SQL_SUBMIT);
    }

    private function query(): DB
    {
        return new DB(adapter: $this->adapter);
    }

    public function testThrowingAfterHookYieldsPostSubmitHookExceptionAndDoesNotHideSuccessfulWrite(): void
    {
        $hookError = new RuntimeException('after-hook failed');
        HookManager::addHook(HookManager::AFTER_SQL_SUBMIT, function () use ($hookError): void {
            throw $hookError;
        });

        try {
            $this->query()->insert()->into('items')->values(['id' => 1])->commit();
            $this->fail('Expected PostSubmitHookException to be thrown');
        } catch (PostSubmitHookException $exception) {
            // The subscriber error is chained as the previous exception, so a
            // caller can distinguish an observer failure from a DB failure.
            $this->assertSame($hookError, $exception->getPrevious());
            // The successful, already-committed result is carried on the exception.
            $this->assertSame([], $exception->getResult());
        }

        // The write really happened despite the observer failure: the row persists.
        HookManager::resetHooks(HookManager::AFTER_SQL_SUBMIT);
        $this->assertSame(
            [['id' => 1]],
            $this->query()->select()->from('items')->commit(),
        );
    }

    public function testNonThrowingAfterHookLeavesResultUnchanged(): void
    {
        HookManager::addHook(HookManager::AFTER_SQL_SUBMIT, fn() => null);

        $result = $this->query()->insert()->into('items')->values(['id' => 7])->commit();

        $this->assertSame([], $result);
    }
}
