<?php

declare(strict_types=1);

namespace F4\Tests\DB;
use PHPUnit\Framework\TestCase;

use F4\DB\Reference\ColumnReference;
use F4\DB\Reference\ColumnReferenceWithAlias;
use F4\DB\Reference\SimpleReference;
use F4\DB\Reference\TableReference;
use F4\DB\Reference\TableReferenceWithAlias;

final class ReferenceTest extends TestCase
{
    public function testReferences(): void
    {
        $adapter = new MockAdapter();
        $reference0 = new SimpleReference(' someField ');
        $this->assertSame('"someField"', $reference0->getQuery($adapter));
        $reference1 = new ColumnReference(' someField ');
        $this->assertSame('"someField"', $reference1->getQuery($adapter));
        $reference2 = new ColumnReference(' someTable .  someField ');
        $this->assertSame('"someTable"."someField"', $reference2->getQuery($adapter));
        $reference3 = new ColumnReferenceWithAlias('someTable .  someField  alias ');
        $this->assertSame('"someTable"."someField" AS "alias"', $reference3->getQuery($adapter));
        $reference4 = new TableReference('someTable');
        $this->assertSame('"someTable"', $reference4->getQuery($adapter));
        $reference5 = new TableReferenceWithAlias(' someTable t1');
        $this->assertSame('"someTable" AS "t1"', $reference5->getQuery($adapter));
    }

    public function testGetDelimitedSignalsRecognition(): void
    {
        $this->assertNotNull(new ColumnReference('t.col')->getDelimited());
        $this->assertNull(new ColumnReference('a > 1')->getDelimited());
    }

    public function testGetPreparedStatementRendersReferenceViaAdapter(): void
    {
        // A reference stores no query of its own; getPreparedStatement() must render through
        // getQuery($adapter), joining its identifiers — not return an empty statement.
        $preparedStatement = new ColumnReference('t.col')->getPreparedStatement(null, new MockAdapter());
        $this->assertSame('"t"."col"', $preparedStatement->query);
        $this->assertSame([], $preparedStatement->parameters);
    }
}