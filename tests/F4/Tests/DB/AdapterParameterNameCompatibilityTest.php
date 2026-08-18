<?php

declare(strict_types=1);

namespace F4\Tests\DB;

use F4\DB;
use F4\DBTransaction;
use F4\DB\Exception\RollbackFailedException;
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

    public function testTransactionRollbackFailureThrowsRollbackFailedWithOriginalChained(): void
    {
        // When both the query and the subsequent ROLLBACK fail, the rollback failure
        // is the primary exception (more severe: the connection is left dirty), and the
        // original query failure remains available via getPrevious(). The adapter's
        // connection is discarded so the poisoned handle is not reused (finding #3).
        $failingQuery = 'SELECT * FROM "broken"';
        $adapter = new RenamedParamsMockAdapter($failingQuery, failRollback: true);
        $transaction = (new DBTransaction(null, $adapter))->add(
            DB::select()->from('broken'),
        );

        try {
            $transaction->commit();
            $this->fail('Expected a RollbackFailedException');
        } catch (RollbackFailedException $exception) {
            $this->assertStringContainsString('Forced rollback failure', $exception->getMessage());
            $previous = $exception->getPrevious();
            $this->assertInstanceOf(RuntimeException::class, $previous);
            $this->assertSame('Forced adapter failure', $previous->getMessage());
        }

        $this->assertSame(
            ['BEGIN', $failingQuery, 'ROLLBACK'],
            array_column($adapter->executions, 'query'),
        );
        $this->assertSame(1, $adapter->discardCount, 'Connection must be discarded on rollback failure.');
    }
}
