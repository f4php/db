<?php

declare(strict_types=1);

namespace F4\DB;

use Composer\Pcre\Preg;
use F4\DB\ConditionCollection;
use F4\DB\FragmentInterface;
use F4\DB\StaticOfMethodTrait;

use function array_filter;
use function array_map;
use function implode;
use function sprintf;

/**
 * 
 * AnyConditionCollection is an abstraction of sql expressions allowed inside a "WHERE" part of a statement but with OR as a glue
 * 
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 * 
 */
class NoneConditionCollection extends ConditionCollection
{
    use StaticOfMethodTrait;
    protected const string GLUE = ' OR ';
    public function getQuery(): string
    {
        $query = implode(
            separator: static::GLUE,
            array: array_filter(
                array: array_map(
                    callback: fn(FragmentInterface $fragment): string => $fragment->getQuery(),
                    array: $this->fragments,
                ),
                callback: fn($query) => $query !== ''
            ),
        );
        return match ($query === '') {
            true => '',
            default => match ($this->prefix) {
                    null => sprintf('NOT (%s)', Preg::replace('/^\((.*)\)$/', '$1', $query)),
                    default => sprintf('%s NOT (%s)', $this->prefix, $query)
                }
        };
    }

}

