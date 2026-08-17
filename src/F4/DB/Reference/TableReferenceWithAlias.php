<?php

declare(strict_types=1);

namespace F4\DB\Reference;

use InvalidArgumentException;
use LogicException;
use F4\DB\DelimitedIdentifier;
use F4\DB\Reference\SimpleReference;
use F4\DB\Adapter\AdapterInterface;

use function sprintf;

/**
 *
 * TableReferenceWithAlias detects table references with an optional alias and converts them to delimited identifiers
 *
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 *
 */
class TableReferenceWithAlias extends SimpleReference
{
    private ?DelimitedIdentifier $alias = null;

    public const string IDENTIFIER_PATTERN = '(?<table>[a-zA-Z_][a-zA-Z0-9_]{0,62})(\s+(?<alias>[a-zA-Z_][a-zA-Z0-9_]{0,62}))?';
    protected function buildIdentifiers(array $matches): array
    {
        if (empty($matches['table'])) {
            throw new InvalidArgumentException('Cannot locate table identifier');
        }
        if (!empty($matches['alias'])) {
            $this->alias = new DelimitedIdentifier($matches['alias']);
        }
        return [new DelimitedIdentifier($matches['table'])];
    }
    public function getQuery(?AdapterInterface $adapter = null): string
    {
        if ($this->identifiers === null) {
            throw new LogicException('Reference has no delimited identifier; branch on getDelimited() first');
        }
        $table = $this->identifiers[0]->getQuery($adapter);
        return $this->alias === null ? $table : sprintf('%s AS %s', $table, $this->alias->getQuery($adapter));
    }
}
