<?php

declare(strict_types=1);

namespace F4\DB\Adapter\PostgresqlAdapter;

use F4\DB\Adapter\PostgresqlAdapter\ParseException;

use function ctype_space;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strtoupper;
use function substr;

/**
 *
 * Parser for PostgreSQL array output literals (the text representation returned by
 * the server, e.g. `{1,2,3}`, `{"a,b",NULL}`, `{{1,2},{3,4}}`).
 *
 * Self-contained and dependency-free: it performs no type casting and has no
 * knowledge of the adapter, ext-pgsql, or the F4\DB\Exception hierarchy. It maps a
 * literal string to a nested PHP array whose leaves are the raw element strings
 * (or null for an unquoted SQL NULL), leaving element casting to the caller.
 *
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 *
 */
class ArrayParser
{
    /**
     * Parse a PostgreSQL array output literal into a nested PHP array.
     *
     * Unquoted `NULL` (case-insensitive) becomes PHP null; a quoted `"NULL"` stays
     * the string "NULL". Elements are returned verbatim (no type casting); nested
     * arrays are represented as nested PHP arrays.
     *
     * @param string $literal   A PostgreSQL array output literal.
     * @param string $delimiter Single-character element delimiter (`,` for every
     *                          built-in type except `box`, which uses `;`).
     * @return array<int, string|null|array<mixed>>
     * @throws ParseException When the literal is malformed.
     */
    public function parse(string $literal, string $delimiter = ','): array
    {
        if (strlen($delimiter) !== 1) {
            throw new ParseException('Array element delimiter must be a single character');
        }
        // Postgres prefixes arrays with an explicit dimension when the lower bound is
        // non-default, e.g. "[1:3]={1,2,3}" or "[-2:0][1:2]={...}". Strip that prefix.
        $offset = $this->skipDimensionPrefix($literal);
        $offset = $this->skipSpace($literal, $offset);
        if (!str_starts_with(substr($literal, $offset), '{')) {
            throw new ParseException('PostgreSQL array literal must begin with "{"');
        }
        $result = $this->parseArray($literal, $offset, $delimiter);
        $offset = $this->skipSpace($literal, $offset);
        if ($offset !== strlen($literal)) {
            throw new ParseException(sprintf('Unexpected trailing content at offset %d', $offset));
        }
        return $result;
    }

    /**
     * Parse one `{...}` group starting at $offset (which must point at "{").
     * Advances $offset past the matching "}".
     *
     * @param int $offset Cursor into $literal; updated by reference.
     * @return array<int, string|null|array<mixed>>
     * @throws ParseException
     */
    private function parseArray(string $literal, int &$offset, string $delimiter): array
    {
        $length = strlen($literal);
        $offset++; // consume "{"
        $result = [];
        $offset = $this->skipSpace($literal, $offset);
        if ($offset < $length && $literal[$offset] === '}') {
            $offset++; // empty array "{}"
            return $result;
        }
        while (true) {
            $offset = $this->skipSpace($literal, $offset);
            if ($offset >= $length) {
                throw new ParseException('Unterminated PostgreSQL array literal');
            }
            if ($literal[$offset] === '{') {
                $result[] = $this->parseArray($literal, $offset, $delimiter);
            } elseif ($literal[$offset] === '"') {
                $result[] = $this->parseQuoted($literal, $offset);
            } else {
                $result[] = $this->parseUnquoted($literal, $offset, $delimiter);
            }
            $offset = $this->skipSpace($literal, $offset);
            if ($offset >= $length) {
                throw new ParseException('Unterminated PostgreSQL array literal');
            }
            $character = $literal[$offset];
            if ($character === '}') {
                $offset++;
                return $result;
            }
            if ($character !== $delimiter) {
                throw new ParseException(sprintf('Expected "%s" or "}" at offset %d', $delimiter, $offset));
            }
            $offset++; // consume delimiter
        }
    }

    /**
     * Read a double-quoted element starting at $offset (pointing at the opening
     * quote), honoring backslash escapes. Advances $offset past the closing quote.
     *
     * @param int $offset Cursor into $literal; updated by reference.
     * @throws ParseException
     */
    private function parseQuoted(string $literal, int &$offset): string
    {
        $length = strlen($literal);
        $offset++; // consume opening quote
        $value = '';
        while ($offset < $length) {
            $character = $literal[$offset];
            if ($character === '\\') {
                if ($offset + 1 >= $length) {
                    throw new ParseException('Unterminated escape in PostgreSQL array literal');
                }
                $value .= $literal[$offset + 1];
                $offset += 2;
                continue;
            }
            if ($character === '"') {
                $offset++; // consume closing quote
                return $value;
            }
            $value .= $character;
            $offset++;
        }
        throw new ParseException('Unterminated quoted element in PostgreSQL array literal');
    }

    /**
     * Read an unquoted element starting at $offset, up to (but not consuming) the
     * next delimiter or closing brace. Trailing whitespace is trimmed; an unquoted
     * NULL (case-insensitive) yields PHP null.
     *
     * @param int $offset Cursor into $literal; updated by reference.
     * @throws ParseException
     */
    private function parseUnquoted(string $literal, int &$offset, string $delimiter): string|null
    {
        $length = strlen($literal);
        $raw = '';
        while ($offset < $length) {
            $character = $literal[$offset];
            if ($character === $delimiter || $character === '}' || $character === '{' || $character === '"') {
                break;
            }
            $raw .= $character;
            $offset++;
        }
        // Whitespace between the token and the following delimiter/brace is not part
        // of the value; leading whitespace was already skipped by the caller.
        $trimmed = '';
        for ($i = strlen($raw) - 1; $i >= 0; $i--) {
            if (!ctype_space($raw[$i])) {
                $trimmed = substr($raw, 0, $i + 1);
                break;
            }
        }
        if ($trimmed === '') {
            throw new ParseException(sprintf('Empty unquoted element at offset %d', $offset));
        }
        if (strtoupper($trimmed) === 'NULL') {
            return null;
        }
        return $trimmed;
    }

    /**
     * Skip a leading dimension prefix such as "[1:3]=" or "[-2:0][1:2]=" and return
     * the offset just past the "=". Returns 0 when no prefix is present.
     *
     * @throws ParseException
     */
    private function skipDimensionPrefix(string $literal): int
    {
        $offset = $this->skipSpace($literal, 0);
        if ($offset >= strlen($literal) || $literal[$offset] !== '[') {
            return 0;
        }
        $length = strlen($literal);
        while ($offset < $length && $literal[$offset] === '[') {
            $close = false;
            $offset++;
            while ($offset < $length) {
                if ($literal[$offset] === ']') {
                    $close = true;
                    $offset++;
                    break;
                }
                $offset++;
            }
            if (!$close) {
                throw new ParseException('Unterminated dimension prefix in PostgreSQL array literal');
            }
        }
        if ($offset >= $length || $literal[$offset] !== '=') {
            throw new ParseException('Malformed dimension prefix in PostgreSQL array literal');
        }
        return $offset + 1; // consume "="
    }

    private function skipSpace(string $literal, int $offset): int
    {
        $length = strlen($literal);
        while ($offset < $length && ctype_space($literal[$offset])) {
            $offset++;
        }
        return $offset;
    }
}
