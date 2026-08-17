<?php

declare(strict_types=1);

namespace F4\DB;

use F4\DB\{
    Fragment,
    Reference\SimpleReference,
    Reference\ColumnReferenceWithAlias,
};
use InvalidArgumentException;

use function
    count,
    is_array,
    is_numeric,
    is_scalar,
    sprintf
;

/**
 * 
 * SelectExpressionCollection is an abstraction of sql expressions allowed inside a "SELECT" part of a statement
 * 
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 * 
 */
class SelectExpressionCollection extends FragmentCollection
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
                    $reference = new SimpleReference($key)->getDelimited();
                    if (is_array($value)) {
                        if ($reference === null) {
                            $query = $key;
                            $value = match(count(Fragment::extractPlaceholders($query)) > 1) {
                                true => $value,
                                default => [$value]
                            };
                            $this->append(new Fragment($query, $value));
                        } else {
                            $this->append(new Fragment(
                                sprintf('(%s) AS %s', Fragment::COMMA_PARAMETER_PLACEHOLDER, Fragment::SUBQUERY_PARAMETER_PLACEHOLDER),
                                [$value, $reference]
                            ));
                        }
                    } else if ($value instanceof FragmentInterface) {
                        $this->append(match ($reference) {
                            null => new Fragment($key, [$value]),
                            default => new Fragment(
                                sprintf('(%s) AS %s', Fragment::SUBQUERY_PARAMETER_PLACEHOLDER, Fragment::SUBQUERY_PARAMETER_PLACEHOLDER),
                                [$value, $reference]
                            )
                        });
                    } else if ($value === null || is_scalar($value)) {
                        $this->append(match ($reference) {
                            null => new Fragment($key, [$value]),
                            default => new Fragment(
                                sprintf('%s AS %s', Fragment::SINGLE_PARAMETER_PLACEHOLDER, Fragment::SUBQUERY_PARAMETER_PLACEHOLDER),
                                [$value, $reference]
                            )
                        });
                    } else {
                        throw new InvalidArgumentException('Unsupported type');
                    }
                }
            }
        } elseif ($expression instanceof FragmentInterface) {
            $this->append($expression);
        } else {
            $reference = new ColumnReferenceWithAlias((string) $expression)->getDelimited();
            $this->append($reference ?? new Fragment((string) $expression, []));
        }
    }
}

