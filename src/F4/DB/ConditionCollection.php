<?php

declare(strict_types=1);

namespace F4\DB;

use Composer\Pcre\Preg;
use F4\DB\{
    Fragment,
    FragmentCollection,
    FragmentInterface,
    StaticOfMethodTrait,
    Reference\ColumnReference,
};
use F4\DB\Adapter\AdapterInterface;
use InvalidArgumentException;

use function
    array_filter,
    array_map,
    count,
    implode,
    is_array,
    is_numeric,
    is_scalar,
    sprintf
;

/**
 * 
 * ConditionCollection is an abstraction of sql expressions allowed inside a "WHERE" part of a statement
 * 
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 * 
 */
class ConditionCollection extends FragmentCollection
{
    use StaticOfMethodTrait;
    protected const string GLUE = ' AND ';
    public function __construct(...$arguments)
    {
        $this->addExpression($arguments);
    }
    public function getQuery(?AdapterInterface $adapter = null): string
    {
        $query = implode(static::GLUE, array_filter(
            array: array_map(
                callback: fn (FragmentInterface $fragment): string => $fragment->getQuery($adapter),
                array: $this->fragments
            ),
            callback: fn($query) => $query !== '')
        );
        return match ($query === '') {
            true => '',
            default => match ($this->prefix) {
                null => sprintf('(%s)', match(Preg::isMatch('/^\([^\)]+\)$/', $query)) {
                    true => Preg::replace('/^\((.*)\)$/', '$1', $query),
                    default => $query,
                }),
                default => sprintf('%s %s', $this->prefix, $query)
            }
        };
    }
    public function addExpression(mixed $expression): void
    {
        if (is_array($expression)) {
            foreach ($expression as $key => $value) {
                if (is_numeric($key)) {
                    $this->addExpression($value);
                } else {
                    $reference = new ColumnReference($key)->getDelimited();
                    if (is_array($value)) {
                        if ($reference === null) {
                            $query = $key;
                            $value = match(count(Fragment::extractPlaceholders($query)) > 1) {
                                true => $value,
                                default => [$value]
                            };
                            $this->append(new Fragment($query, $value));
                        } else {
                            if (count($value) === 0) {
                                throw new InvalidArgumentException('IN condition values must not be empty');
                            }
                            $this->append(new Fragment(
                                sprintf('%s IN (%s)', Fragment::SUBQUERY_PARAMETER_PLACEHOLDER, Fragment::COMMA_PARAMETER_PLACEHOLDER),
                                [$reference, $value]
                            ));
                        }
                    } elseif ($value instanceof FragmentInterface) {
                        /**
                         * By default, we assume that subquery returns a single value
                         * If not, a "field" IN ({#::#}) is still supported in custom query mode
                         */
                        $this->append(match ($reference) {
                            null => new Fragment($key, [$value]),
                            default => new Fragment(
                                sprintf('%s = (%s)', Fragment::SUBQUERY_PARAMETER_PLACEHOLDER, Fragment::SUBQUERY_PARAMETER_PLACEHOLDER),
                                [$reference, $value]
                            )
                        });
                    } else if ($value === null) {
                        match ($reference) {
                            /**
                             * This is questionable, since bound value of null will always require extra tricks
                             * like type cast in order for the expression to work
                             */
                            null => $this->append(new Fragment($key, [$value])),
                            default => $this->append(new Fragment(
                                sprintf('%s IS NULL', Fragment::SUBQUERY_PARAMETER_PLACEHOLDER),
                                [$reference]
                            ))
                        };
                    } else if (is_scalar($value)) {
                        $this->append(match ($reference) {
                            null => new Fragment($key, [$value]),
                            default => new Fragment(
                                sprintf('%s = %s', Fragment::SUBQUERY_PARAMETER_PLACEHOLDER, Fragment::SINGLE_PARAMETER_PLACEHOLDER),
                                [$reference, $value]
                            )
                        });
                    } else {
                        throw new InvalidArgumentException('Unsupported condition type');
                    }
                }
            }
        } elseif ($expression instanceof FragmentInterface) {
            $this->append($expression);
        } else {
            $this->append(new Fragment($expression, []));
        }
    }
}
