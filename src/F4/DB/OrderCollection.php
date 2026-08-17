<?php

declare(strict_types=1);

namespace F4\DB;

use F4\DB\Reference\ColumnReference;
use InvalidArgumentException;

use function
    count,
    is_array,
    is_numeric,
    is_scalar,
    is_string,
    mb_strtoupper,
    mb_trim,
    sprintf
;

/**
 * 
 * OrderCollection is an abstraction of sql expressions allowed inside a "ORDER BY" part of a statement
 * 
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 * 
 */
class OrderCollection extends FragmentCollection
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
                    continue;
                }

                $query = (string) $key;
                $placeholders = Fragment::extractPlaceholders($query);
                $direction = is_string($value) ? mb_trim(mb_strtoupper($value)) : null;

                if ($placeholders === [] && ($direction === 'ASC' || $direction === 'DESC')) {
                    $reference = new ColumnReference($query)->getDelimited();
                    $this->append(match ($reference) {
                        null => new Fragment(sprintf('%s %s', $query, $direction)),
                        default => new Fragment(sprintf('%s %s', Fragment::SUBQUERY_PARAMETER_PLACEHOLDER, $direction), [$reference])
                    });
                } else {
                    $parameters = is_array($value) && count($placeholders) > 1 ? $value : [$value];
                    $this->append(new Fragment($query, $parameters));
                }
            }
        } elseif ($expression instanceof FragmentInterface) {
            $this->append($expression);
        } elseif (is_scalar($expression)) {
            $query = (string) $expression;
            $reference = new ColumnReference($query)->getDelimited();
            $this->append($reference ?? new Fragment($query));
        } else {
            throw new InvalidArgumentException('Order expression must be an identifier, SQL string, FragmentInterface, or array');
        }
    }
}
