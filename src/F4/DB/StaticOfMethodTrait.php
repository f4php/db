<?php

declare(strict_types=1);

namespace F4\DB;

use F4\DB\ConditionCollection;

trait StaticOfMethodTrait
{
    static public function of(...$arguments): ConditionCollection
    {
        $instance = new static(...$arguments);
        return $instance;
    }
}