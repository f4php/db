<?php

declare(strict_types=1);

namespace F4\Tests\DB\Adapter;

use F4\DB\Adapter\PostgresqlAdapter;
use F4\DB\Exception\{
    DuplicateColumnException,
    DuplicateObjectException,
    InvalidTextRepresentationException,
    SyntaxErrorException,
};
use PHPUnit\Framework\TestCase;
use Throwable;

final class PostgresqlAdapterErrorMappingTest extends TestCase
{
    public function testSeparatesInvalidTextFromSyntaxErrors(): void
    {
        $adapter = new TestablePostgresqlErrorMappingAdapter();

        $this->assertInstanceOf(InvalidTextRepresentationException::class, $adapter->exposeError('22P02'));
        $this->assertInstanceOf(SyntaxErrorException::class, $adapter->exposeError('42601'));
    }

    public function testSeparatesDuplicateObjectsFromDuplicateColumns(): void
    {
        $adapter = new TestablePostgresqlErrorMappingAdapter();

        $this->assertInstanceOf(DuplicateObjectException::class, $adapter->exposeError('42710'));
        $this->assertInstanceOf(DuplicateColumnException::class, $adapter->exposeError('42701'));
    }
}

final class TestablePostgresqlErrorMappingAdapter extends PostgresqlAdapter
{
    public function exposeError(string $code): Throwable
    {
        return $this->convertErrorToException($code, 'Test PostgreSQL error');
    }
}
