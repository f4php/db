<?php

declare(strict_types=1);

namespace F4\DB;

use F4\DB\Adapter\AdapterInterface;
use F4\DB\FragmentCollectionInterface;
use F4\DB\FragmentInterface;

interface QueryBuilderInterface extends FragmentCollectionInterface, FragmentInterface
{
    public function addColumn(...$arguments): static;
    public function addColumnIfNotExists(...$arguments): static;
    public function alterColumn(...$arguments): static;
    public function alterTableIfExists(...$arguments): static;
    public function asRow(): ?array;
    public function asSQL(): string;
    public function asTable(): array;
    public function asValue(string|int $index = 0): mixed;
    public function createIndex(...$arguments): static;
    public function createIndexIfNotExists(...$arguments): static;
    public function createTable(string $name, array $columns): static;
    public function createTableIfNotExists(string $name, array $columns): static;
    public function createView(...$arguments): static;
    public function createOrReplaceView(...$arguments): static;
    public function createMaterializedView(...$arguments): static;
    public function createMaterializedViewIfNotExists(...$arguments): static;
    public function commit(?int $stopAfter = null): array;
    public function crossJoin(...$arguments): static;
    public function crossJoinLateral(...$arguments): static;
    public function delete(): static;
    public function doNothing(): static;
    public function doUpdateSet(...$arguments): static;
    public function dropColumn(...$arguments): static;
    public function dropColumnIfExists(...$arguments): static;
    public function dropTable(...$arguments): static;
    public function dropTableIfExists(...$arguments): static;
    public function dropTableWithCascade(...$arguments): static;
    public function dropTableIfExistsWithCascade(...$arguments): static;
    public function escapeIdentifier(string $identifier): string;
    public function except(): static;
    public function exceptAll(): static;
    public function from(...$arguments): static;
    public function fullOuterJoin(...$arguments): static;
    public function group(...$arguments): static;
    public function groupBy(...$arguments): static;
    public function groupByAll(...$arguments): static;
    public function groupByDistinct(...$arguments): static;
    public function having(...$arguments): static;
    public function innerJoin(...$arguments): static;
    public function innerJoinLateral(...$arguments): static;
    public function insert(): static;
    public function intersect(): static;
    public function intersectAll(): static;
    public function into(...$arguments): static;
    public function join(...$arguments): static;
    public function joinLateral(...$arguments): static;
    public function leftJoin(...$arguments): static;
    public function leftJoinLateral(...$arguments): static;
    public function leftOuterJoin(...$arguments): static;
    public function limit(int $limit, int $offset = 0): static;
    public function naturalJoin(...$arguments): static;
    public function naturalLeftOuterJoin(...$arguments): static;
    public function naturalRightOuterJoin(...$arguments): static;
    public function offset(int $offset): static;
    public function on(...$arguments): static;
    public function onConflict(...$arguments): static;
    public function order(...$arguments): static;
    public function orderBy(...$arguments): static;
    public function raw(...$arguments): static;
    public function returning(...$arguments): static;
    public function rightJoin(...$arguments): static;
    public function rightOuterJoin(...$arguments): static;
    public function select(...$arguments): static;
    public function selectDistinct(...$arguments): static;
    public function set(...$arguments): static;
    public function update(...$arguments): static;
    public function union(...$arguments): static;
    public function unionAll(...$arguments): static;
    public function useAdapter(AdapterInterface $adapter): static;
    public function using(...$arguments): static;
    public function values(...$arguments): static;
    public function where(...$arguments): static;
    public function with(...$arguments): static;
    public function withRecursive(...$arguments): static;
}