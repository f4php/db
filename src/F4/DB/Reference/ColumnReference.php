<?php

declare(strict_types=1);

namespace F4\DB\Reference;

use InvalidArgumentException;
use F4\DB\DelimitedIdentifier;
use F4\DB\Reference\SimpleReference;

/**
 *
 * ColumnReference is a class used to detect column references and convert them to delimited identifiers
 *
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 *
 */
class ColumnReference extends SimpleReference
{
    public const string IDENTIFIER_PATTERN = '((?<table>[a-zA-Z_][a-zA-Z0-9_]{0,62})\s*\.\s*)?(?<column>[a-zA-Z_][a-zA-Z0-9_]{0,62})';
    protected function buildIdentifiers(array $matches): array
    {
        if (empty($matches['column'])) {
            throw new InvalidArgumentException('Cannot locate column identifier');
        }
        return empty($matches['table'])
            ? [new DelimitedIdentifier($matches['column'])]
            : [new DelimitedIdentifier($matches['table']), new DelimitedIdentifier($matches['column'])];
    }
}
