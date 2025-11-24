<?php

declare(strict_types=1);

namespace F4\Tests;
use PHPUnit\Framework\TestCase;

use F4\DB;
use F4\DBTransaction;

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
}