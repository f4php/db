<?php

declare(strict_types=1);

namespace F4\Tests\DB;

use F4\DB\Adapter\AdapterInterface;
use F4\DB\PreparedStatement;

use function sprintf;

/**
 * A second connectionless mock adapter that quotes identifiers with [brackets] instead of "double quotes".
 * It also uses positional question-mark parameters, proving that identifier quoting and parameter
 * enumeration both go through the ACTIVE adapter at render time.
 */
final class BracketMockAdapter implements AdapterInterface
{
    public function __construct() {}

    public function execute(PreparedStatement $statement, ?int $stopAfter = null): mixed
    {
        return [];
    }
    public function enumerateParameters(int $index): string
    {
        return '?';
    }
    public function getEscapedBinary(string $value): string
    {
        return $value;
    }
    public function getEscapedIdentifier(string $value): string
    {
        return sprintf('[%s]', $value);
    }
    public function getEscapedValue(mixed $value): string
    {
        return (string) $value;
    }
}
