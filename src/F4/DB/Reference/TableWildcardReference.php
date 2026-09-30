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
 * TableWildcardReference detects qualified wildcard references (e.g. table.*) and converts the table name to a
 * delimited identifier, leaving the asterisk as is
 *
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 *
 */
class TableWildcardReference extends SimpleReference
{
    public const string IDENTIFIER_PATTERN = '(?<table>[a-zA-Z_][a-zA-Z0-9_]{0,62})\s*\.\s*\*';
    protected function buildIdentifiers(array $matches): array
    {
        if (empty($matches['table'])) {
            throw new InvalidArgumentException('Cannot locate table identifier');
        }
        return [new DelimitedIdentifier($matches['table'])];
    }
    public function getQuery(?AdapterInterface $adapter = null): string
    {
        if ($this->identifiers === null) {
            throw new LogicException('Reference has no delimited identifier; branch on getDelimited() first');
        }
        return sprintf('%s.*', $this->identifiers[0]->getQuery($adapter));
    }
}
