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
    /**
     * Drop the adapter's local connection handle and attempt driver-specific cleanup.
     * The next connection-bearing operation opens a new connection through the adapter.
     * Used after a connection may be left in a dirty transactional state (e.g. a failed
     * ROLLBACK). Note this operates on the adapter's own handle only: with persistent
     * connections the underlying backend session may be pooled and reused, so a clean
     * session cannot be guaranteed — disable persistent connections where that matters.
     * Implementations must not throw: any internal driver error is swallowed so this can
     * never mask the failure that triggered the discard.
     */
    public function discardConnection(): void;
    public function enumerateParameters(int $index): string;
    public function getEscapedBinary(string $value): string;
    /** @param scalar|null|DateTimeInterface $value */
    public function getEscapedValue(mixed $value): string;
    public function getEscapedIdentifier(string $identifier): string;
}
