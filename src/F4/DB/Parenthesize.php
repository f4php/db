<?php

declare(strict_types=1);

namespace F4\DB;

use F4\DB\{
    FragmentCollection,
    FragmentCollectionInterface,
    FragmentInterface
};

use function
    array_filter,
    array_map,
    implode,
    sprintf
;

/**
 * 
 * Parenthesize is simple wrapper that adds parenthesis to its arguments
 * 
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 * 
 */
class Parenthesize extends FragmentCollection implements FragmentCollectionInterface, FragmentInterface
{
    public function __construct(...$arguments)
    {
        array_map(
            callback: $this->append(...),
            array: $arguments,
        );
    }
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
                    null => sprintf("(%s)", $query),
                    default => sprintf('%s (%s)', $this->prefix, $query)
                }
        };
    }

}

