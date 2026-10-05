<?php

declare(strict_types=1);

namespace F4\DB\Exception;

use F4\DB\Exception\Exception;

/**
 * Thrown in strict concealment mode (DB_STRICT_CONCEAL) when a concealed key
 * is absent from a non-empty result set, which usually indicates a typo or an
 * unexpected alias that would otherwise let a sensitive column through.
 *
 * The statement has already been executed when this is thrown, so it must not
 * be treated as a retriable database failure. The result is deliberately not
 * attached to the exception, since it may contain the columns meant to be concealed.
 */
class ConcealedColumnNotFoundException extends Exception
{
    protected $message = 'Concealed column not found in result';
    protected $code = 500;
}
