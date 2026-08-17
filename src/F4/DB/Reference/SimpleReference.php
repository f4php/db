<?php

declare(strict_types=1);

namespace F4\DB\Reference;

use Composer\Pcre\Regex;
use InvalidArgumentException;
use LogicException;
use F4\DB\Fragment;
use F4\DB\DelimitedIdentifier;
use F4\DB\Adapter\AdapterInterface;

use function
    array_map,
    implode,
    mb_trim
;

/**
 *
 * SimpleReference is used for simple references like isolated field or table names.
 *
 * A reference parses its input into one or more DelimitedIdentifier instances (its $identifiers) and, being a
 * Fragment, renders them via the active adapter at render time. When the input is not a valid identifier the
 * reference owns none ($identifiers stays null) and getDelimited() returns null, so the consuming collection
 * supplies its own literal Fragment for the raw case instead.
 *
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 *
 */
class SimpleReference extends Fragment
{
    /** @var ?list<DelimitedIdentifier> null when the input is not a recognized identifier; never an empty array */
    protected ?array $identifiers = null;

    /**
     *
     * Currently (Nov 2024) the maximum length of an identifier is 63 characters
     *
     * https://www.postgresql.org/docs/current/sql-syntax-lexical.html#SQL-SYNTAX-IDENTIFIERS
     *
     */
    public const string IDENTIFIER_PATTERN = '(?<identifier>[a-zA-Z_][a-zA-Z0-9_]{0,62})';

    public function __construct(string $reference)
    {
        parent::__construct();
        $match = Regex::match('/' . static::IDENTIFIER_PATTERN . '$/Anu', mb_trim($reference));
        $this->identifiers = $match->matched ? $this->buildIdentifiers($match->matches) : null;
    }
    public function getDelimited(): ?static
    {
        return $this->identifiers === null ? null : $this;
    }
    public function getQuery(?AdapterInterface $adapter = null): string
    {
        if ($this->identifiers === null) {
            throw new LogicException('Reference has no delimited identifier; branch on getDelimited() first');
        }
        return implode('.', array_map(fn (DelimitedIdentifier $identifier): string => $identifier->getQuery($adapter), $this->identifiers));
    }
    /**
     * @return non-empty-list<DelimitedIdentifier>
     */
    protected function buildIdentifiers(array $matches): array
    {
        if (empty($matches['identifier'])) {
            throw new InvalidArgumentException('Cannot locate identifier');
        }
        return [new DelimitedIdentifier($matches['identifier'])];
    }
}
