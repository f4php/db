<?php

declare(strict_types=1);

namespace F4\DB\Exception;

use Throwable;

/**
 * Thrown when an AFTER_SQL_SUBMIT hook subscriber throws.
 *
 * The database operation itself completed successfully and its result is
 * committed; only a post-submit observer failed. This exception is therefore
 * NON-RETRIABLE as a database failure — retrying the write would duplicate an
 * operation that already happened. The successful result is available via
 * getResult(), and the subscriber's own error is chained via getPrevious().
 */
class PostSubmitHookException extends Exception
{
    protected $message = 'A post-submit (AFTER_SQL_SUBMIT) hook failed after the database operation had already succeeded';

    /**
     * @param array<mixed> $result the successful, already-committed database result
     */
    public function __construct(protected array $result, ?Throwable $previous = null)
    {
        parent::__construct($this->message, $this->code, $previous);
    }

    /**
     * The successful database result. The write has already been committed;
     * callers should recover this instead of retrying the operation.
     *
     * @return array<mixed>
     */
    public function getResult(): array
    {
        return $this->result;
    }
}
