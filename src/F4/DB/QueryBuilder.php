<?php

declare(strict_types=1);

namespace F4\DB;

use BadMethodCallException;
use InvalidArgumentException;

use F4\DB\Adapter\AdapterInterface;
use F4\DB\AssignmentCollection;
use F4\DB\ConditionCollection;
use F4\DB\FragmentInterface;
use F4\DB\FragmentCollection;
use F4\DB\FragmentCollectionInterface;
use F4\DB\OrderCollection;
use F4\DB\Parenthesize;
use F4\DB\QueryBuilderInterface;
use F4\DB\SelectExpressionCollection;
use F4\DB\SimpleColumnReferenceCollection;
use F4\DB\TableReferenceCollection;
use F4\DB\TableWithColumnsReferenceCollection;
use F4\DB\ValueExpressionCollection;
use F4\DB\WithTableReferenceCollection;

use F4\HookManager;

use F4\Config;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

class QueryBuilder extends FragmentCollection implements FragmentInterface, FragmentCollectionInterface, QueryBuilderInterface
{
    protected AdapterInterface $adapter;
    public function __construct(?string $connectionString = null, string|AdapterInterface $adapter = Config::DB_ADAPTER_CLASS)
    {
        $this->adapter = match (is_string($adapter)) {
            true => new $adapter($connectionString),
            default => $adapter,
        };
    }
    protected function resetAllFragmentCollectionsNames()
    {
        array_map(
            callback: function (FragmentInterface|FragmentCollectionInterface $fragment): void {
                if ($fragment instanceof FragmentCollectionInterface) {
                    $fragment->resetName();
                }
            },
            array: $this->getFragments(),
        );
    }
    public function addColumn(...$arguments): static
    {
        throw new BadMethodCallException('not implemented yet');
    }
    public function addColumnIfNotExists(...$arguments): static
    {
        throw new BadMethodCallException('not implemented yet');
    }
    public function alterColumn(...$arguments): static
    {
        throw new BadMethodCallException('not implemented yet');
    }
    public function alterTable(...$arguments): static
    {
        throw new BadMethodCallException('not implemented yet');
    }
    public function alterTableIfExists(...$arguments): static
    {
        throw new BadMethodCallException('not implemented yet');
    }
    public function asRow(): ?array
    {
        return $this->commit(stopAfter: 1)[0] ?? null;
    }
    public function asSQL(): string
    {
        $parameters = $this->getPreparedStatement()->parameters;
        $escapedParametersEnumerator = function (mixed $index) use ($parameters): mixed {
            if (!array_key_exists($index - 1, $parameters)) {
                throw new InvalidArgumentException('Unexpected parameter index');
            }
            $value = $parameters[$index - 1];
            return $this->adapter->getEscapedValue($value);
            ;
        };
        return $this->getPreparedStatement($escapedParametersEnumerator)->query;
    }
    public function asTable(): array
    {
        return $this->commit();
    }
    public function asValue(string|int $index = 0): mixed
    {
        return match (is_int($index)) {
            true => array_values($this->commit(stopAfter: 1)[0] ?? [])[$index] ?? null,
            default => ($this->commit(stopAfter: 1)[0][$index] ?? null)
        };
    }
    public function commit(?int $stopAfter = null): array
    {
        $preparedStatement = $this->getPreparedStatement($this->adapter->enumerateParameters(...));
        HookManager::triggerHook(HookManager::BEFORE_SQL_SUBMIT, ['statement' => $preparedStatement->query, 'parameters' => $preparedStatement->parameters]);
        $result = $this->adapter->execute(statement: $preparedStatement, stopAfter: $stopAfter);
        HookManager::triggerHook(HookManager::AFTER_SQL_SUBMIT, ['statement' => $preparedStatement->query, 'parameters' => $preparedStatement->parameters, 'result' => $result]);
        return $result;
    }
    public function createIndex(...$arguments): static
    {
        throw new BadMethodCallException('not implemented yet');
    }
    public function createIndexIfNotExists(...$arguments): static
    {
        throw new BadMethodCallException('not implemented yet');
    }
    public function createOrReplaceView(...$arguments): static
    {
        throw new BadMethodCallException('not implemented yet');
    }
    public function createTable(string $name, array $columns): static
    {
        throw new BadMethodCallException('not implemented yet');
    }
    public function createTableIfNotExists(string $name, array $columns): static
    {
        throw new BadMethodCallException('not implemented yet');
    }
    public function createMaterializedView(...$arguments): static
    {
        throw new BadMethodCallException('not implemented yet');
    }
    public function createMaterializedViewIfNotExists(...$arguments): static
    {
        throw new BadMethodCallException('not implemented yet');
    }
    public function createView(...$arguments): static
    {
        throw new BadMethodCallException('not implemented yet');
    }
    public function crossJoin(...$arguments): static
    {
        $this->append(new TableReferenceCollection(...$arguments)->withPrefix('CROSS JOIN'));
        return $this;
    }
    public function crossJoinLateral(...$arguments): static
    {
        $this->append(new TableReferenceCollection(...$arguments)->withPrefix('CROSS JOIN LATERAL'));
        return $this;
    }
    public function delete(): static
    {
        $this->append('DELETE');
        return $this;
    }
    public function doNothing(): static
    {
        $this->append('DO NOTHING');
        return $this;
    }
    public function doUpdateSet(...$arguments): static
    {
        match ($existingNamedFragmentCollection = $this->findFragmentCollectionByName('do_update_set')) {
            null => $this
                ->append(new AssignmentCollection(...$arguments)->withPrefix('DO UPDATE SET')->withName('do_update_set')),
            default => $existingNamedFragmentCollection
                ->append(new AssignmentCollection(...$arguments))
        };
        return $this;
    }
    public function dropColumn(...$arguments): static
    {
        throw new BadMethodCallException('not implemented yet');
    }
    public function dropColumnIfExists(...$arguments): static
    {
        throw new BadMethodCallException('not implemented yet');
    }
    public function dropTable(...$arguments): static
    {
        $this->append('DROP TABLE')
            ->append(new TableReferenceCollection(...$arguments));
        $this->resetAllFragmentCollectionsNames();
        return $this;
    }
    public function dropTableIfExists(...$arguments): static
    {
        $this->append('DROP TABLE IF EXISTS')
            ->append(new TableReferenceCollection(...$arguments));
        $this->resetAllFragmentCollectionsNames();
        return $this;
    }
    public function dropTableWithCascade(...$arguments): static
    {
        $this->append('DROP TABLE')
            ->append(new TableReferenceCollection(...$arguments))
            ->append('CASCADE');
        $this->resetAllFragmentCollectionsNames();
        return $this;
    }
    public function dropTableIfExistsWithCascade(...$arguments): static
    {
        $this->append('DROP TABLE IF EXISTS')
            ->append(new TableReferenceCollection(...$arguments))
            ->append('CASCADE');
        $this->resetAllFragmentCollectionsNames();
        return $this;
    }
    public function escapeIdentifier(string $identifier): string
    {
        return $this->adapter->getEscapedIdentifier($identifier);
    }
    public function except(): static
    {
        $this->append('EXCEPT');
        return $this;
    }
    // TODO: add support for parenthesis via argumens to union() to control order of evaluation for multiple unions/intersects/excepts
    public function exceptAll(): static
    {
        $this->append('EXCEPT ALL');
        return $this;
    }
    public function from(...$arguments): static
    {
        $this
            ->append('FROM')
            ->append(new TableReferenceCollection(...$arguments));
        return $this;
    }
    public function fullOuterJoin(...$arguments): static
    {
        $this
            ->append('FULL OUTER JOIN');
        return $this;
    }
    public function group(...$arguments): static
    {
        match ($existingNamedFragmentCollection = $this->findFragmentCollectionByName('group_by')) {
            null => $this
                ->append(new Parenthesize(new SimpleColumnReferenceCollection(...$arguments)->withName('group_by_collection'))->withPrefix('GROUP BY')->withName('group_by')),
            default => $existingNamedFragmentCollection
                ->findFragmentCollectionByName('group_by_collection')
                ->append(new SimpleColumnReferenceCollection(...$arguments))
        };
        return $this;
    }
    public function groupBy(...$arguments): static
    {
        return $this->group(...$arguments);
    }
    public function groupByAll(...$arguments): static
    {
        match ($existingNamedFragmentCollection = $this->findFragmentCollectionByName('group_by')) {
            null => $this
                ->append(new Parenthesize(new SimpleColumnReferenceCollection(...$arguments)->withName('group_by_collection'))->withPrefix('GROUP BY ALL')->withName('group_by')),
            default => $existingNamedFragmentCollection
                ->findFragmentCollectionByName('group_by_collection')
                ->append(new SimpleColumnReferenceCollection(...$arguments))
        };
        return $this;
    }
    public function groupByDistinct(...$arguments): static
    {
        match ($existingNamedFragmentCollection = $this->findFragmentCollectionByName('group_by')) {
            null => $this
                ->append(new Parenthesize(new SimpleColumnReferenceCollection(...$arguments)->withName('group_by_collection'))->withPrefix('GROUP BY DISTINCT')->withName('group_by')),
            default => $existingNamedFragmentCollection
                ->findFragmentCollectionByName('group_by_collection')
                ->append(new SimpleColumnReferenceCollection(...$arguments))
        };
        return $this;
    }
    public function having(...$arguments): static
    {
        $this->append(new ConditionCollection(...$arguments)->withPrefix('HAVING'));
        return $this;
    }
    public function innerJoin(...$arguments): static
    {
        $this
            ->append(new TableReferenceCollection(...$arguments)->withPrefix('INNER JOIN'));
        return $this;
    }
    public function innerJoinLateral(...$arguments): static
    {
        $this
            ->append(new TableReferenceCollection(...$arguments)->withPrefix('INNER JOIN LATERAL'));
        return $this;
    }
    public function insert(): static
    {
        $this->append('INSERT');
        $this->resetAllFragmentCollectionsNames();
        return $this;
    }
    public function intersect(): static
    {
        $this->append('INTERSECT');
        return $this;
    }
    // TODO: add support for parenthesis via argumens to union() to control order of evaluation for multiple unions/intersects/excepts
    public function intersectAll(): static
    {
        $this->append('INTERSECT ALL');
        return $this;
    }
    public function into(...$arguments): static
    {
        $this->append(new TableWithColumnsReferenceCollection(...$arguments)->withPrefix('INTO'));
        return $this;
    }
    public function join(...$arguments): static
    {
        $this->append(new TableReferenceCollection(...$arguments)->withPrefix('JOIN'));
        return $this;
    }
    public function joinLateral(...$arguments): static
    {
        $this->append(new TableReferenceCollection(...$arguments)->withPrefix('JOIN LATERAL'));
        return $this;
    }
    public function leftJoin(...$arguments): static
    {
        $this->append(new TableReferenceCollection(...$arguments)->withPrefix('LEFT JOIN'));
        return $this;
    }
    public function leftJoinLateral(...$arguments): static
    {
        $this->append(new TableReferenceCollection(...$arguments)->withPrefix('LEFT JOIN LATERAL'));
        return $this;
    }
    public function leftOuterJoin(...$arguments): static
    {
        $this->append(new TableReferenceCollection(...$arguments)->withPrefix('LEFT OUTER JOIN'));
        return $this;
    }
    public function limit(int $limit, ?int $offset = null): static
    {
        $this->append(match ($offset === null) {
            true => sprintf('LIMIT %d', $limit),
            default => sprintf('LIMIT %d OFFSET %d', $limit, $offset)
        });
        return $this;
    }
    public function naturalJoin(...$arguments): static
    {
        $this->append(new TableReferenceCollection(...$arguments)->withPrefix('NATURAL JOIN'));
        return $this;
    }
    public function naturalLeftOuterJoin(...$arguments): static
    {
        $this->append(new TableReferenceCollection(...$arguments)->withPrefix('NATURAL LEFT OUTER JOIN'));
        return $this;
    }
    public function naturalRightOuterJoin(...$arguments): static
    {
        $this->append(new TableReferenceCollection(...$arguments)->withPrefix('NATURAL RIGHT OUTER JOIN'));
        return $this;
    }
    public function offset(int $offset): static
    {
        $this->append(sprintf('OFFSET %d', $offset));
        return $this;
    }
    public function on(...$arguments): static
    {
        $this->append(new ConditionCollection(...$arguments)->withPrefix('ON'));
        return $this;
    }
    public function onConflict(...$arguments): static
    {
        $this
            ->append('ON CONFLICT')
            ->append(match (count($arguments) > 0) {
                true => new Parenthesize(new SimpleColumnReferenceCollection($arguments)),
                default => '',
            });
        return $this;
    }
    public function order(...$arguments): static
    {
        match ($existingNamedFragmentCollection = $this->findFragmentCollectionByName('order_by')) {
            null => $this
                ->append(new OrderCollection(...$arguments)->withPrefix('ORDER BY')->withName('order_by')),
            default => $existingNamedFragmentCollection
                ->append(new OrderCollection(...$arguments))
        };
        return $this;
    }
    public function orderBy(...$arguments): static
    {
        return $this->order(...$arguments);
    }
    public function raw(...$arguments): static
    {
        $this->append(new FragmentCollection(...$arguments));
        return $this;
    }
    public function returning(...$arguments): static
    {
        $this->append(new SimpleColumnReferenceCollection($arguments ?: '*')->withPrefix('RETURNING'));
        return $this;
    }
    public function rightJoin(...$arguments): static
    {
        $this->append(new TableReferenceCollection(...$arguments)->withPrefix('RIGHT JOIN'));
        return $this;
    }
    public function rightOuterJoin(...$arguments): static
    {
        $this->append(new TableReferenceCollection(...$arguments)->withPrefix('RIGHT OUTER JOIN'));
        return $this;
    }
    public function select(...$arguments): static
    {
        $this->append(new SelectExpressionCollection($arguments ?: '*')->withPrefix('SELECT'));
        $this->resetAllFragmentCollectionsNames();
        return $this;
    }
    public function selectDistinct(...$arguments): static
    {
        $this->append(new SelectExpressionCollection($arguments ?: '*')->withPrefix('SELECT DISTINCT'));
        $this->resetAllFragmentCollectionsNames();
        return $this;
    }
    public function set(...$arguments): static
    {
        match ($existingNamedFragmentCollection = $this->findFragmentCollectionByName('set')) {
            null => $this
                ->append(new AssignmentCollection(...$arguments)->withPrefix('SET')->withName('set')),
            default => $existingNamedFragmentCollection
                ->append(new AssignmentCollection(...$arguments))
        };
        return $this;
    }
    public function update(...$arguments): static
    {
        $this->append(new TableReferenceCollection(...$arguments)->withPrefix('UPDATE'));
        $this->resetAllFragmentCollectionsNames();
        return $this;
    }
    public function union(...$arguments): static
    {
        $this->append('UNION');
        $this->resetAllFragmentCollectionsNames();
        return $this;
    }
    public function unionAll(...$arguments): static
    {
        $this->append('UNION ALL');
        $this->resetAllFragmentCollectionsNames();
        return $this;
    }
    public function useAdapter(AdapterInterface $adapter): static
    {
        $this->adapter = $adapter;
        return $this;
    }
    public function using(...$arguments): static
    {
        $this->append(new Parenthesize(new SimpleColumnReferenceCollection(...$arguments))->withPrefix('USING'));
        return $this;
    }
    public function values(...$arguments): static
    {
        array_map(
            callback: function ($argument) {
                if (is_array($argument)) {
                    $existingFieldsFragmentCollection = $this->findFragmentCollectionByName('insert_fields');
                    $existingValuesFragmentCollection = $this->findFragmentCollectionByName('insert_values');
                    if ($existingFieldsFragmentCollection && $existingValuesFragmentCollection) {
                        $existingFieldsFragmentCollection
                            ->findFragmentCollectionByName('insert_fields_collection')
                            ->append(new SimpleColumnReferenceCollection(...array_keys($argument)));
                        $existingValuesFragmentCollection
                            ->findFragmentCollectionByName('insert_values_collection')
                            ->append(new ValueExpressionCollection(...array_values($argument)));
                    } else {
                        $this
                            ->append(new Parenthesize(new SimpleColumnReferenceCollection(...array_keys($argument))->withName('insert_fields_collection'))->withName('insert_fields'))
                            ->append(new Parenthesize(new ValueExpressionCollection(...array_values($argument))->withName('insert_values_collection'))->withPrefix('VALUES')->withName('insert_values'));
                    };
                }
            },
            array: $arguments,
        );
        return $this;
    }
    public function where(...$arguments): static
    {
        match ($existingNamedFragmentCollection = $this->findFragmentCollectionByName('where')) {
            null => $this->append(new ConditionCollection(...$arguments)->withPrefix('WHERE')->withName('where')),
            default => array_map(
                callback: $existingNamedFragmentCollection->addExpression(...),
                array: $arguments,
            )
        };
        return $this;
    }
    public function with(...$arguments): static
    {
        $this->append(new WithTableReferenceCollection(...$arguments)->withPrefix('WITH'));
        return $this;
    }
    public function withRecursive(...$arguments): static
    {
        $this->append(new WithTableReferenceCollection(...$arguments)->withPrefix('WITH RECURSIVE'));
        return $this;
    }
}