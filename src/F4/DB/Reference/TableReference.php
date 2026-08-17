<?php

declare(strict_types=1);

namespace F4\DB\Reference;

use InvalidArgumentException;
use F4\DB\DelimitedIdentifier;
use F4\DB\Reference\SimpleReference;

/**
 *
 * TableReference is a class used to detect table references and convert them to delimited identifiers
 *
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 *
 */
class TableReference extends SimpleReference
{
    public const string IDENTIFIER_PATTERN = '(?<table>[a-zA-Z_][a-zA-Z0-9_]{0,62})';
    protected function buildIdentifiers(array $matches): array
    {
        if (empty($matches['table'])) {
            throw new InvalidArgumentException('Cannot locate table identifier');
        }
        return [new DelimitedIdentifier($matches['table'])];
    }
}
