<?php

declare(strict_types=1);

namespace F4\DB\Concealment;

use F4\DB\Exception\ConcealedColumnNotFoundException;

use function
    array_is_list,
    array_key_exists,
    array_map,
    count,
    get_debug_type,
    is_array,
    sprintf
;

/**
 *
 * Concealer removes concealed keys from a result set. Paths are compiled into a tree,
 * so every row is walked once regardless of the number of paths. Lists met along a
 * path are traversed element by element, so one path covers both a single related
 * object (to_jsonb(...)) and a list of them (jsonb_agg(...)).
 *
 * In strict mode every object reached by a path must contain the next segment, and
 * every value traversed must be an object or a list. Null values and empty lists
 * mean "no related data" and are skipped in both modes.
 *
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 *
 */
final class Concealer
{
    /**
     * Each node maps a segment to a child node; a null child conceals that key.
     *
     * @var array<string, array|null>
     */
    private array $tree = [];

    public function __construct(ConcealmentPath ...$paths)
    {
        foreach ($paths as $path) {
            $node = &$this->tree;
            $segments = $path->getSegments();
            $lastIndex = count($segments) - 1;
            foreach ($segments as $index => $segment) {
                if (array_key_exists($segment, $node) && $node[$segment] === null) {
                    // An ancestor key is already concealed entirely
                    break;
                }
                if ($index === $lastIndex) {
                    $node[$segment] = null;
                    break;
                }
                $node[$segment] ??= [];
                $node = &$node[$segment];
            }
            unset($node);
        }
    }

    public function apply(array $result, bool $strict): array
    {
        if (empty($this->tree)) {
            return $result;
        }
        return array_map(
            callback: fn(mixed $row): mixed => match (is_array($row)) {
                true => $this->concealObject($row, $this->tree, [], $strict),
                default => $row,
            },
            array: $result,
        );
    }

    /**
     * @param list<string> $prefix
     */
    private function concealObject(array $object, array $node, array $prefix, bool $strict): array
    {
        foreach ($node as $segment => $child) {
            $segment = (string) $segment;
            if (!array_key_exists($segment, $object)) {
                if ($strict) {
                    throw new ConcealedColumnNotFoundException(sprintf(
                        'Concealed key "%s" not found at %s',
                        $segment,
                        ConcealmentPath::render($prefix),
                    ));
                }
                continue;
            }
            if ($child === null) {
                unset($object[$segment]);
                continue;
            }
            $object[$segment] = $this->concealValue($object[$segment], $child, [...$prefix, $segment], $strict);
        }
        return $object;
    }

    /**
     * @param list<string> $prefix
     */
    private function concealValue(mixed $value, array $node, array $prefix, bool $strict): mixed
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            if ($strict) {
                throw new ConcealedColumnNotFoundException(sprintf(
                    'Expected an object or a list at %s, got %s',
                    ConcealmentPath::render($prefix),
                    get_debug_type($value),
                ));
            }
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(
                callback: fn(mixed $element): mixed => $this->concealValue($element, $node, $prefix, $strict),
                array: $value,
            );
        }
        return $this->concealObject($value, $node, $prefix, $strict);
    }
}
