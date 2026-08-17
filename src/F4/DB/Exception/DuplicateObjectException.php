<?php

declare(strict_types=1);

namespace F4\DB\Exception;

class DuplicateObjectException extends Exception
{
    protected $message = 'Object already exists';
    protected $code = 500;
}
