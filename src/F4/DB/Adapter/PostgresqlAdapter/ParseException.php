<?php

declare(strict_types=1);

namespace F4\DB\Adapter\PostgresqlAdapter;

use RuntimeException;

/**
 *
 * Thrown by ArrayParser when a PostgreSQL array literal is malformed.
 *
 * This exception is intentionally independent of the F4\DB\Exception hierarchy so
 * that the parser stays a self-contained, extraction-ready unit. Adapters that use
 * the parser are expected to catch this and translate it into their own result-value
 * exception type.
 *
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 *
 */
class ParseException extends RuntimeException
{
}
