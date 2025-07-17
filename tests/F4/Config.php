<?php

declare(strict_types=1);

namespace F4;

class Config  {
    public const string DB_ADAPTER_CLASS = \F4\Tests\DB\MockAdapter::class;
    public const string DB_HOST = 'localhost';
    public const string DB_CHARSET = 'UTF8';
    public const string DB_PORT = '5432';
    public const string DB_NAME = '';
    public const string DB_USERNAME = '';
    public const string DB_PASSWORD = '';
    public const string DB_SCHEMA = '';
    public const ?string DB_APP_NAME = null;
    public const bool DB_PERSIST = true;
    public const bool DEBUG_MODE = true;
    public const string TIMEZONE = '';
}