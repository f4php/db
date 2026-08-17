<?php

declare(strict_types=1);

namespace F4\Tests\DB;

use F4\DB\Adapter\AdapterInterface;
use F4\DB\PreparedStatement;
use RuntimeException;

use function sprintf;
use function str_replace;

/**
 * Uses parameter names that deliberately differ from AdapterInterface.
 */
final class RenamedParamsMockAdapter implements AdapterInterface
{
    /** @var list<array{query: string, parameters: array, limit: ?int}> */
    public array $executions = [];

    public function __construct(
        private readonly ?string $failingQuery = null,
        private readonly bool $failRollback = false,
    ) {}

    public function execute(PreparedStatement $command, ?int $rowLimit = null): mixed
    {
        $this->executions[] = [
            'query' => $command->query,
            'parameters' => $command->parameters,
            'limit' => $rowLimit,
        ];

        if ($command->query === $this->failingQuery) {
            throw new RuntimeException('Forced adapter failure');
        }
        if ($this->failRollback && $command->query === 'ROLLBACK') {
            throw new RuntimeException('Forced rollback failure');
        }

        return [['value' => 'ok']];
    }

    public function enumerateParameters(int $position): string
    {
        return sprintf('$%d', $position);
    }

    public function getEscapedBinary(string $bytes): string
    {
        return $bytes;
    }

    public function getEscapedIdentifier(string $name): string
    {
        return sprintf('"%s"', str_replace('"', '""', $name));
    }

    public function getEscapedValue(mixed $rawValue): string
    {
        return (string) $rawValue;
    }
}
