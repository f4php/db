<?php

declare(strict_types=1);

namespace F4\Tests\DB;

use F4\DB\Concealment\ConcealmentPath;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConcealmentPathTest extends TestCase
{
    public static function validPathsProvider(): array
    {
        return [
            'plain key' => ['passwordHash', ['passwordHash'], '$.passwordHash'],
            'plain key with dot is literal' => ['a.b', ['a.b'], '$["a.b"]'],
            'single segment path' => ['$.passwordHash', ['passwordHash'], '$.passwordHash'],
            'nested path' => ['$.leadEmployee.passwordHash', ['leadEmployee', 'passwordHash'], '$.leadEmployee.passwordHash'],
            'quoted segment' => ['$["leadEmployee"]["passwordHash"]', ['leadEmployee', 'passwordHash'], '$.leadEmployee.passwordHash'],
            'quoted segment with dot' => ['$.meta["a.b"]', ['meta', 'a.b'], '$.meta["a.b"]'],
            'quoted segment with dollar' => ['$["$weird"]', ['$weird'], '$.$weird'],
            'quoted segment with escapes' => ['$["a\\"b\\\\c"]', ['a"b\\c'], '$["a\\"b\\\\c"]'],
            'quoted segment with brackets' => ['$["[x]"].y', ['[x]', 'y'], '$["[x]"].y'],
            'numeric segment' => ['$.items.0', ['items', '0'], '$.items.0'],
        ];
    }

    #[DataProvider('validPathsProvider')]
    public function testParsesValidPaths(string $input, array $segments, string $canonical): void
    {
        $path = ConcealmentPath::fromString($input);
        $this->assertSame($segments, $path->getSegments());
        $this->assertSame($canonical, $path->getCanonical());
    }

    #[DataProvider('validPathsProvider')]
    public function testCanonicalFormRoundTrips(string $input, array $segments, string $canonical): void
    {
        $this->assertSame($segments, ConcealmentPath::fromString($canonical)->getSegments());
    }

    public function testPlainKeyAndPathShareCanonicalForm(): void
    {
        $this->assertSame(
            ConcealmentPath::fromString('passwordHash')->getCanonical(),
            ConcealmentPath::fromString('$.passwordHash')->getCanonical(),
        );
    }

    public static function invalidPathsProvider(): array
    {
        return [
            'empty' => [''],
            'root only' => ['$'],
            'trailing dot' => ['$.'],
            'double dot' => ['$..a'],
            'wildcard' => ['$.a[*]'],
            'index' => ['$.a[0]'],
            'unterminated quote' => ['$["a'],
            'unterminated bracket' => ['$["a"'],
            'empty quoted' => ['$[""]'],
            'missing dot' => ['$a'],
            'quote in dot name' => ['$.a"b'],
            'bracket in dot name' => ['$.a]'],
        ];
    }

    #[DataProvider('invalidPathsProvider')]
    public function testRejectsInvalidPaths(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        ConcealmentPath::fromString($input);
    }
}
