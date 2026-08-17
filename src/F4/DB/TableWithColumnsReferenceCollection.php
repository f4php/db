<?php

declare(strict_types=1);

namespace F4\DB;

use InvalidArgumentException;
use F4\DB\{
    Parenthesize,
    SimpleColumnReferenceCollection,
    Reference\TableReferenceWithAlias,
};

use function
    is_array,
    is_numeric
;

/**
 * 
 * TableWithColumnsReferenceCollection is an abstraction of sql sql expressions allowed after an "INSERT INTO" part of a statement
 * 
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 * 
 */
class TableWithColumnsReferenceCollection extends FragmentCollection
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
                    if (is_array($value)) {
                        $reference = new TableReferenceWithAlias($key)->getDelimited();
                        $this
                            ->append(new FragmentCollection($reference ?? $key, new Parenthesize(new SimpleColumnReferenceCollection($value))));
                    } else {
                        throw new InvalidArgumentException('Unsupported column reference');
                    }
                }
            }
        } elseif ($expression instanceof FragmentInterface) {
            throw new InvalidArgumentException('Subqueries are not supported');
        } else {
            $reference = new TableReferenceWithAlias((string) $expression)->getDelimited();
            $this->append($reference ?? new Fragment((string) $expression, []));
        }
    }
}

