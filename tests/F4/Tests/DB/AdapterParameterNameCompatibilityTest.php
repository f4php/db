<?php

declare(strict_types=1);

namespace F4\Tests\DB;

use F4\DB;
use F4\DBTransaction;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AdapterParameterNameCompatibilityTest extends TestCase
{
    public function testQueryExecutionDoesNotDependOnAdapterParameterNames(): void
    {
        $adapter = new RenamedParamsMockAdapter();

        $row = DB::select()
            ->from('items')
            ->where(['id' => 7])
            ->useAdapter($adapter)
            ->asRow();

        $this->assertSame(['value' => 'ok'], $row);
        $this->assertSame([
            [
                'query' => 'SELECT * FROM "items" WHERE "id" = $1',
                'parameters' => [7],
                'limit' => 1,
            ],
        ], $adapter->executions);
    }

    public function testTransactionRollbackDoesNotDependOnAdapterParameterNames(): void
    {
        $failingQuery = 'SELECT * FROM "broken"';
        $adapter = new RenamedParamsMockAdapter($failingQuery);
        $transaction = (new DBTransaction(null, $adapter))->add(
            DB::select()->from('broken'),
        );

        try {
            $transaction->commit();
            $this->fail('Expected the adapter failure to be rethrown');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced adapter failure', $exception->getMessage());
        }

        $this->assertSame(
            ['BEGIN', $failingQuery, 'ROLLBACK'],
            array_column($adapter->executions, 'query'),
        );
    }

    public function testTransactionRollbackFailureDoesNotReplaceOriginalException(): void
    {
        $failingQuery = 'SELECT * FROM "broken"';
        $adapter = new RenamedParamsMockAdapter($failingQuery, failRollback: true);
        $transaction = (new DBTransaction(null, $adapter))->add(
            DB::select()->from('broken'),
        );

        try {
            $transaction->commit();
            $this->fail('Expected the adapter failure to be rethrown');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced adapter failure', $exception->getMessage());
        }

        $this->assertSame(
            ['BEGIN', $failingQuery, 'ROLLBACK'],
            array_column($adapter->executions, 'query'),
        );
    }
}
