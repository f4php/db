<?php

declare(strict_types=1);

namespace F4\DB;

use F4\DB\Adapter\AdapterInterface;
use F4\DB\FragmentCollectionInterface;
use F4\DB\FragmentInterface;

interface QueryBuilderInterface extends FragmentCollectionInterface, FragmentInterface
{
    public function addColumn(): static;
    public function addColumnIfNotExists(): static;
    public function alterColumn(): static;
    public function alterTableIfExists(): static;
    public function asRow(): ?array;
    public function asSQL(): string;
    public function asTable(): array;
    public function asValue(string|int $index = 0): mixed;
    public function createIndex(): static;
    public function createIndexIfNotExists(): static;
    public function createTable(): static;
    public function createTableIfNotExists(): static;
    public function createView(): static;
    public function createOrReplaceView(): static;
    public function createMaterializedView(): static;
    public function createMaterializedViewIfNotExists(): static;
    public function commit(?int $stopAfter = null): array;
    public function crossJoin(): static;
    public function crossJoinLateral(): static;
    public function delete(): static;
    public function doNothing(): static;
    public function doUpdateSet(): static;
    public function dropColumn(): static;
    public function dropColumnIfExists(): static;
    public function dropTable(): static;
    public function dropTableIfExists(): static;
    public function dropTableWithCascade(): static;
    public function dropTableIfExistsWithCascade(): static;
    public function escapeIdentifier(string $identifier): string;
    public function except(): static;
    public function exceptAll(): static;
    public function from(): static;
    public function fullOuterJoin(): static;
    public function group(): static;
    public function groupBy(): static;
    public function groupByAll(): static;
    public function groupByDistinct(): static;
    public function having(): static;
    public function innerJoin(): static;
    public function innerJoinLateral(): static;
    public function insert(): static;
    public function intersect(): static;
    public function intersectAll(): static;
    public function into(): static;
    public function join(): static;
    public function joinLateral(): static;
    public function leftJoin(): static;
    public function leftJoinLateral(): static;
    public function leftOuterJoin(): static;
    public function limit(int $limit, int $offset = 0): static;
    public function naturalJoin(): static;
    public function naturalLeftOuterJoin(): static;
    public function naturalRightOuterJoin(): static;
    public function offset(int $offset): static;
    public function on(): static;
    public function onConflict(): static;
    public function order(): static;
    public function orderBy(): static;
    public function raw(): static;
    public function returning(): static;
    public function rightJoin(): static;
    public function rightOuterJoin(): static;
    public function select(): static;
    public function selectDistinct(): static;
    public function set(): static;
    public function update(): static;
    public function union(): static;
    public function unionAll(): static;
    public function useAdapter(AdapterInterface $adapter): static;
    public function using(): static;
    public function values(): static;
    public function where(): static;
    public function with(): static;
    public function withRecursive(): static;
}