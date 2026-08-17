<?php

declare(strict_types=1);

namespace F4\DB\Adapter;

use DateTimeInterface;
use F4\DB\PreparedStatement;

interface AdapterInterface
{
    /**
     * Prepared statement parameters may contain scalar, null, or DateTimeInterface values.
     * Adapters must normalize DateTimeInterface values before passing them to the driver.
     */
    public function execute(PreparedStatement $statement, ?int $stopAfter = null): mixed;
    public function enumerateParameters(int $index): string;
    public function getEscapedBinary(string $value): string;
    /** @param scalar|null|DateTimeInterface $value */
    public function getEscapedValue(mixed $value): string;
    public function getEscapedIdentifier(string $identifier): string;
}
