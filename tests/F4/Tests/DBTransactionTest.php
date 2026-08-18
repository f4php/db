<?php

declare(strict_types=1);

namespace F4\Tests;
use PHPUnit\Framework\TestCase;

use DateTimeInterface;
use F4\DB;
use F4\DBTransaction;
use F4\DB\Adapter\AdapterInterface;
use F4\DB\Exception\Exception;
use F4\DB\Exception\RollbackFailedException;
use F4\DB\PreparedStatement;

use function sprintf;
use function str_starts_with;
use function strtoupper;

/**
 * Adapter that simulates a connection already inside a transaction owned by the
 * caller: a nested BEGIN throws, and every statement executed is recorded so a
 * test can assert whether ROLLBACK was (wrongly) issued.
 */
final class RecordingTransactionAdapter implements AdapterInterface
{
    /** @var list<string> */
    public array $executed = [];
    public int $discardCount = 0;
    /**
     * @param null|callable(string):void $onExecute inspects each uppercased SQL string
     *        and may throw to simulate a driver failure at that step
     * @param bool $discardThrows simulates a misbehaving adapter whose discardConnection()
     *        violates the non-throwing contract
     */
    public function __construct(private mixed $onExecute = null, private bool $discardThrows = false) {}

    public function execute(PreparedStatement $statement, ?int $stopAfter = null): mixed
    {
        $sql = strtoupper($statement->query);
        $this->executed[] = $sql;
        if ($this->onExecute !== null) {
            ($this->onExecute)($sql);
        }
        return [];
    }
    public function discardConnection(): void
    {
        $this->discardCount++;
        if ($this->discardThrows) {
            throw new Exception('discard boom');
        }
    }
    public function enumerateParameters(int $index): string
    {
        return sprintf('$%d', $index);
    }
    public function getEscapedBinary(string $value): string
    {
        return $value;
    }
    public function getEscapedIdentifier(string $value): string
    {
        return sprintf('"%s"', $value);
    }
    public function getEscapedValue(mixed $value): string
    {
        return match ($value instanceof DateTimeInterface) {
            true => sprintf("'%s'", $value->format('Y-m-d\TH:i:s.uP')),
            default => (string) $value,
        };
    }
}

final class DBTransactionTest extends TestCase
{
    public function testSelect(): void
    {
        $db1 = DBTransaction::add([
            DB::select()->from('t1'),
            DB::select()->from('t2'),
        ])
            ->add(DB::select()->from('t3'));
        $this->assertSame('BEGIN; SELECT * FROM "t1"; SELECT * FROM "t2"; SELECT * FROM "t3"; COMMIT', $db1->asSQL());
    }

    /**
     * Finding #1: when BEGIN fails (e.g. the injected adapter is already inside a
     * transaction owned by the caller), commit() must rethrow without issuing a
     * ROLLBACK — otherwise it would roll back a transaction it did not start.
     */
    public function testFailedBeginDoesNotRollBack(): void
    {
        $adapter = new RecordingTransactionAdapter(onExecute: function (string $sql): void {
            if (str_starts_with($sql, 'BEGIN')) {
                throw new Exception('cannot start a transaction within a transaction');
            }
        });
        $transaction = new DBTransaction(adapter: $adapter);
        $transaction->add(DB::select()->from('t1'));

        try {
            $transaction->commit();
            $this->fail('Expected the failed BEGIN to propagate.');
        } catch (Exception $e) {
            $this->assertStringContainsString('within a transaction', $e->getMessage());
        }

        $this->assertSame(['BEGIN'], $adapter->executed, 'Only BEGIN should have run; no ROLLBACK when we never started.');
        $this->assertNotContains('ROLLBACK', $adapter->executed);
    }

    /**
     * A failure after a successful BEGIN must still roll back, and the result
     * shape stays [BEGIN result, ...user results, COMMIT result].
     */
    public function testFailureAfterBeginRollsBack(): void
    {
        $adapter = new RecordingTransactionAdapter(onExecute: function (string $sql): void {
            if (str_starts_with($sql, 'SELECT')) {
                throw new Exception('boom');
            }
        });
        $transaction = new DBTransaction(adapter: $adapter);
        $transaction->add(DB::select()->from('t1'));

        try {
            $transaction->commit();
            $this->fail('Expected the failing query to propagate.');
        } catch (Exception $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(['BEGIN', 'SELECT * FROM "T1"', 'ROLLBACK'], $adapter->executed);
        $this->assertSame(0, $adapter->discardCount, 'A successful rollback must not discard the connection.');
    }

    /**
     * Finding #3: when ROLLBACK itself fails, the connection may be left dirty, so the
     * adapter connection is discarded and a RollbackFailedException is thrown with the
     * original query failure available via getPrevious().
     */
    public function testRollbackFailureDiscardsConnectionAndThrowsRollbackFailed(): void
    {
        $adapter = new RecordingTransactionAdapter(onExecute: function (string $sql): void {
            if (str_starts_with($sql, 'SELECT')) {
                throw new Exception('query boom');
            }
            if (str_starts_with($sql, 'ROLLBACK')) {
                throw new Exception('rollback boom');
            }
        });
        $transaction = new DBTransaction(adapter: $adapter);
        $transaction->add(DB::select()->from('t1'));

        try {
            $transaction->commit();
            $this->fail('Expected a RollbackFailedException.');
        } catch (RollbackFailedException $e) {
            $this->assertStringContainsString('rollback boom', $e->getMessage());
            $this->assertInstanceOf(Exception::class, $e->getPrevious());
            $this->assertSame('query boom', $e->getPrevious()->getMessage());
        }

        $this->assertSame(['BEGIN', 'SELECT * FROM "T1"', 'ROLLBACK'], $adapter->executed);
        $this->assertSame(1, $adapter->discardCount, 'A failed rollback must discard the connection.');
    }

    /**
     * A third-party adapter whose discardConnection() violates the non-throwing
     * contract must not mask the RollbackFailedException (nor the original query error).
     */
    public function testThrowingDiscardDoesNotMaskRollbackFailure(): void
    {
        $adapter = new RecordingTransactionAdapter(
            onExecute: function (string $sql): void {
                if (str_starts_with($sql, 'SELECT')) {
                    throw new Exception('query boom');
                }
                if (str_starts_with($sql, 'ROLLBACK')) {
                    throw new Exception('rollback boom');
                }
            },
            discardThrows: true,
        );
        $transaction = new DBTransaction(adapter: $adapter);
        $transaction->add(DB::select()->from('t1'));

        try {
            $transaction->commit();
            $this->fail('Expected a RollbackFailedException.');
        } catch (RollbackFailedException $e) {
            $this->assertStringContainsString('rollback boom', $e->getMessage());
            $this->assertSame('query boom', $e->getPrevious()?->getMessage());
        }

        $this->assertSame(1, $adapter->discardCount, 'discardConnection() was still attempted.');
    }

    public function testSuccessfulCommitResultShape(): void
    {
        $adapter = new RecordingTransactionAdapter();
        $transaction = new DBTransaction(adapter: $adapter);
        $transaction->add(DB::select()->from('t1'));

        $result = $transaction->commit();

        $this->assertIsArray($result);
        $this->assertCount(3, $result, 'Result is [BEGIN, user query, COMMIT].');
        $this->assertSame(['BEGIN', 'SELECT * FROM "T1"', 'COMMIT'], $adapter->executed);
        $this->assertSame(0, $adapter->discardCount, 'A successful commit must not discard the connection.');
    }
}
