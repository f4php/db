<?php

declare(strict_types=1);

namespace F4\DB\Exception;

class InvalidTextRepresentationException extends Exception
{
    protected $message = 'Invalid text representation';
    protected $code = 500;
}
