<?php

declare(strict_types=1);

namespace F4\DB;

use InvalidArgumentException;
use F4\DB\{
    Reference\SimpleReference,
    Reference\TableReferenceWithAlias,
};

use function
    is_array,
    is_numeric,
    is_scalar,
    sprintf
;

/**
 * 
 * TableReferenceCollection is an abstraction of sql sql expressions allowed inside a "FROM" part of a statement
 * 
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 * 
 */
class TableReferenceCollection extends FragmentCollection
{
    protected const string GLUE = ', ';
    public function __construct(...$arguments)
    {
        $this->addExpression($arguments);
    }

    public function addExpression(mixed $expression): void
    {
        if (is_array($expression)) {
            foreach ($expression as $key => $value) {
                if (is_numeric($key)) {
                    $this->addExpression($value);
                } else {
                    if ($value instanceof FragmentInterface) {
                        $reference = new SimpleReference($key)->getDelimited();
                        $this->append(match ($reference) {
                            null => new Fragment($key, [$value]),
                            default => new Fragment(
                                sprintf('(%s) AS %s', Fragment::SUBQUERY_PARAMETER_PLACEHOLDER, Fragment::SUBQUERY_PARAMETER_PLACEHOLDER),
                                [$value, $reference]
                            )
                        });
                    } else if (is_scalar($value)) {
                        throw new InvalidArgumentException('Scalar values as table references are not supported');
                    } else if (is_array($value)) {
                        throw new InvalidArgumentException('Array values as table references are not supported');
                    } else {
                        throw new InvalidArgumentException('Unsupported reference');
                    }
                }
            }
        } elseif ($expression instanceof FragmentInterface) {
            throw new InvalidArgumentException('Subqueries must have an alias');
        } else {
            $reference = new TableReferenceWithAlias((string) $expression)->getDelimited();
            $this->append($reference ?? new Fragment((string) $expression, []));
        }
    }
}

