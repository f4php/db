<?php

declare(strict_types=1);

namespace F4\DB\Exception;

/**
 * Thrown when a transaction query fails and the subsequent ROLLBACK also fails.
 *
 * A failed ROLLBACK is a more severe condition than the original query failure:
 * the connection may be left in an aborted or open transaction. This exception is
 * therefore thrown as the primary error, with the original query failure available
 * via getPrevious(). On this failure DBTransaction also discards the adapter
 * connection so the poisoned handle is not reused.
 */
class RollbackFailedException extends Exception
{
    protected $message = 'Transaction rollback failed';
}
