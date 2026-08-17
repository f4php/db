<?php

declare(strict_types=1);

namespace F4\DB\Exception;

class InvalidResultValueException extends Exception
{
    protected $message = 'Invalid database result value';
    protected $code = 500;
}
