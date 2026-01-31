<?php

declare(strict_types=1);

namespace F4\DB;

use F4\DB\{
    ConditionCollection,
    StaticOfMethodTrait,
};

/**
 * 
 * AnyConditionCollection is an abstraction of sql expressions allowed inside a "WHERE" part of a statement but with OR as a glue
 * 
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 * 
 */
class AnyConditionCollection extends ConditionCollection
{
    use StaticOfMethodTrait;
    protected const string GLUE = ' OR ';
}

