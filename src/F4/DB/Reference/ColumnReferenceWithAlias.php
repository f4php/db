<?php

declare(strict_types=1);

namespace F4\DB\Reference;

use InvalidArgumentException;
use LogicException;
use F4\DB\DelimitedIdentifier;
use F4\DB\Reference\SimpleReference;
use F4\DB\Adapter\AdapterInterface;

use function
    array_map,
    implode,
    sprintf
;

/**
 *
 * ColumnReferenceWithAlias detects column references with an optional alias and converts them to delimited identifiers
 *
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 *
 */
class ColumnReferenceWithAlias extends SimpleReference
{
    private ?DelimitedIdentifier $alias = null;

    public const string IDENTIFIER_PATTERN = '((?<table>[a-zA-Z_][a-zA-Z0-9_]{0,62})\s*\.\s*)?(?<column>[a-zA-Z_][a-zA-Z0-9_]{0,62})(\s+(?<alias>[a-zA-Z_][a-zA-Z0-9_]{0,62}))?';
    protected function buildIdentifiers(array $matches): array
    {
        if (empty($matches['column'])) {
            throw new InvalidArgumentException('Cannot locate column identifier');
        }
        if (!empty($matches['alias'])) {
            $this->alias = new DelimitedIdentifier($matches['alias']);
        }
        return empty($matches['table'])
            ? [new DelimitedIdentifier($matches['column'])]
            : [new DelimitedIdentifier($matches['table']), new DelimitedIdentifier($matches['column'])];
    }
    public function getQuery(?AdapterInterface $adapter = null): string
    {
        if ($this->identifiers === null) {
            throw new LogicException('Reference has no delimited identifier; branch on getDelimited() first');
        }
        $column = implode('.', array_map(fn (DelimitedIdentifier $identifier): string => $identifier->getQuery($adapter), $this->identifiers));
        return $this->alias === null ? $column : sprintf('%s AS %s', $column, $this->alias->getQuery($adapter));
    }
}
