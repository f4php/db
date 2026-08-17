<?php

declare(strict_types=1);

namespace F4\Tests\DB;

use PHPUnit\Framework\TestCase;
use LogicException;
use F4\DB\DelimitedIdentifier;

/**
 * Direct coverage for DelimitedIdentifier, in particular getPreparedStatement(): because a
 * DelimitedIdentifier stores no query string of its own and quotes itself in getQuery(),
 * getPreparedStatement() must render through getQuery($adapter) — otherwise it would return an
 * empty statement (the regression this pins).
 */
final class DelimitedIdentifierTest extends TestCase
{
    public function testGetPreparedStatementRendersViaCapturedAdapter(): void
    {
        $preparedStatement = new DelimitedIdentifier('col', new MockAdapter())->getPreparedStatement();
        $this->assertSame('"col"', $preparedStatement->query);
        $this->assertSame([], $preparedStatement->parameters);
    }

    public function testGetPreparedStatementUsesThreadedAdapterOverCaptured(): void
    {
        // Built with the default MockAdapter, but rendered with a bracket adapter passed at call time.
        $preparedStatement = new DelimitedIdentifier('col', new MockAdapter())
            ->getPreparedStatement(null, new BracketMockAdapter());
        $this->assertSame('[col]', $preparedStatement->query);
    }

    public function testGetPreparedStatementQuotesEmbeddedDoubleQuote(): void
    {
        // Proves quoting actually ran through the adapter (not a passthrough of the raw name).
        $preparedStatement = new DelimitedIdentifier('we"ird', new MockAdapter())->getPreparedStatement();
        $this->assertSame('"we""ird"', $preparedStatement->query);
    }

    public function testGetQueryMatchesGetPreparedStatement(): void
    {
        $identifier = new DelimitedIdentifier('col', new MockAdapter());
        $this->assertSame($identifier->getQuery(), $identifier->getPreparedStatement()->query);
    }

    public function testRenderingWithoutAnyAdapterThrows(): void
    {
        $this->expectException(LogicException::class);
        new DelimitedIdentifier('col')->getPreparedStatement();
    }
}
