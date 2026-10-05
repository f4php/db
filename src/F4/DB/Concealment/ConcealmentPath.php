<?php

declare(strict_types=1);

namespace F4\DB\Concealment;

use InvalidArgumentException;

use function
    addcslashes,
    array_map,
    implode,
    sprintf,
    str_starts_with,
    strcspn,
    strlen,
    substr
;

/**
 *
 * ConcealmentPath addresses a key in a returned row, either as a plain top-level key
 * ("passwordHash") or as a path into decoded JSON values ("$.leadEmployee.passwordHash").
 *
 * Path grammar:
 *   path    := '$' segment+
 *   segment := '.' name | '["' quoted-name '"]'
 *   name    := one or more characters except . [ ] "
 * Inside a quoted name, \" and \\ are escapes for " and \.
 *
 * Any string that does not start with $ is a plain key and is used verbatim, so
 * "a.b" addresses a column literally named a.b. A plain key and the equivalent
 * single-segment path share the same canonical form.
 *
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 *
 */
final class ConcealmentPath
{
    /** @param list<string> $segments */
    private function __construct(private readonly array $segments) {}

    public static function fromString(string $path): self
    {
        if ($path === '') {
            throw new InvalidArgumentException('Concealment key must not be empty');
        }
        if (!str_starts_with($path, '$')) {
            return new self([$path]);
        }
        $segments = [];
        $length = strlen($path);
        $position = 1;
        while ($position < $length) {
            if ($path[$position] === '.') {
                $position++;
                $nameLength = strcspn($path, '.[]"', $position);
                if ($nameLength === 0) {
                    throw new InvalidArgumentException(sprintf('Empty segment name at offset %d in concealment path "%s"', $position, $path));
                }
                $segments[] = substr($path, $position, $nameLength);
                $position += $nameLength;
            } elseif (substr($path, $position, 2) === '["') {
                $position += 2;
                $name = '';
                while (true) {
                    if ($position >= $length) {
                        throw new InvalidArgumentException(sprintf('Unterminated quoted segment in concealment path "%s"', $path));
                    }
                    $character = $path[$position];
                    if ($character === '\\' && $position + 1 < $length && ($path[$position + 1] === '"' || $path[$position + 1] === '\\')) {
                        $name .= $path[$position + 1];
                        $position += 2;
                    } elseif ($character === '"') {
                        break;
                    } else {
                        $name .= $character;
                        $position++;
                    }
                }
                if (substr($path, $position, 2) !== '"]') {
                    throw new InvalidArgumentException(sprintf('Unterminated quoted segment in concealment path "%s"', $path));
                }
                if ($name === '') {
                    throw new InvalidArgumentException(sprintf('Empty quoted segment in concealment path "%s"', $path));
                }
                $segments[] = $name;
                $position += 2;
            } else {
                throw new InvalidArgumentException(sprintf('Unexpected character "%s" at offset %d in concealment path "%s"', $path[$position], $position, $path));
            }
        }
        if (empty($segments)) {
            throw new InvalidArgumentException(sprintf('Concealment path "%s" has no segments', $path));
        }
        return new self($segments);
    }

    /** @return list<string> */
    public function getSegments(): array
    {
        return $this->segments;
    }

    public function getCanonical(): string
    {
        return self::render($this->segments);
    }

    /**
     * Render segments as a path, using the dot form where possible.
     *
     * @param list<string> $segments
     */
    public static function render(array $segments): string
    {
        return '$' . implode('', array_map(
            callback: static fn(string $segment): string => match (strcspn($segment, '.[]"\\') === strlen($segment)) {
                true => ".{$segment}",
                default => '["' . addcslashes($segment, '"\\') . '"]',
            },
            array: $segments,
        ));
    }
}
