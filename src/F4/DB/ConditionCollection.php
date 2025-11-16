<?php

declare(strict_types=1);

namespace F4\DB;

use Composer\Pcre\Preg;
use F4\DB\Reference\ColumnReference;
use F4\DB\Fragment;
use F4\DB\FragmentCollection;
use F4\DB\FragmentInterface;
use F4\DB\StaticOfMethodTrait;
use InvalidArgumentException;

use function array_filter;
use function array_map;
use function implode;
use function is_array;
use function is_numeric;
use function is_scalar;
use function sprintf;

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
    public function getQuery(): string
    {
        $query = implode(static::GLUE, array_filter(
            array: array_map(
                callback: fn (FragmentInterface $fragment): string => $fragment->getQuery(),
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
                    if (is_array($value)) {
                        $query = match ($quoted = (new ColumnReference($key))->delimitedIdentifier) {
                            null => $key,
                            default => sprintf('%s IN ({#,...#})', $quoted)
                        };
                        $value = match(count(Fragment::extractPlaceholders($query)) > 1) {
                            true => $value,
                            default => [$value]
                        };
                        $this->append(new Fragment($query, $value));
                    } elseif ($value instanceof FragmentInterface) {
                        $query = match ($quoted = (new ColumnReference($key))->delimitedIdentifier) {
                            null => $key,
                            /**
                             * By default, we assume that subquery returns a single value
                             * If not, a "field" IN ({#::#}) is still supported in custom query mode
                             */
                            default => sprintf('%s = ({#::#})', $quoted)
                        };
                        $this->append(new Fragment($query, [$value]));
                    } else if ($value === null) {
                        $query = match ($quoted = (new ColumnReference($key))->delimitedIdentifier) {
                            null => $key,
                            default => sprintf('%s IS NULL', $quoted)
                        };
                        match ($quoted) {
                            /**
                             * This is questionable, since bound value of null will always require extra tricks 
                             * like type cast in order for the expression to work
                             */
                            null => $this->append(new Fragment($query, [$value])),
                            default => $this->append(new Fragment($query))
                        };
                    } else if (is_scalar($value)) {
                        $query = match ($quoted = (new ColumnReference($key))->delimitedIdentifier) {
                            null => $key,
                            default => sprintf('%s = {#}', $quoted)
                        };
                        $this->append(new Fragment($query, [$value]));
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

