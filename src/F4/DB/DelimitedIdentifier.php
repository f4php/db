<?php

declare(strict_types=1);

namespace F4\DB;

use LogicException;
use F4\DB\Adapter\AdapterInterface;

/**
 *
 * DelimitedIdentifier is a single SQL identifier that quotes itself via the active adapter at render time.
 *
 * It is always a valid quoted identifier and never carries raw or unrecognized text. It never composes
 * multiple names — composition (e.g. "table"."column", "column" AS "alias") lives in the Reference classes,
 * which hold one or more DelimitedIdentifier instances and join them.
 *
 * Being a Fragment, it slots into the {#::#} subquery placeholder seam as a single parameter and binds nothing.
 *
 * @package F4\DB
 * @author Dennis Kreminsky <dennis@kreminsky.com>
 *
 */
class DelimitedIdentifier extends Fragment
{
    public function __construct(
        private readonly string $name,
        private readonly ?AdapterInterface $adapter = null,
    ) {
        parent::__construct();
    }
    public function getQuery(?AdapterInterface $adapter = null): string
    {
        $adapter ??= $this->adapter;
        if ($adapter === null) {
            throw new LogicException('DelimitedIdentifier has no adapter to render with');
        }
        return $adapter->getEscapedIdentifier($this->name);
    }
    public function __toString(): string
    {
        trigger_error('Coercing DelimitedIdentifier to string is deprecated; use getQuery().', E_USER_DEPRECATED);
        return $this->getQuery();
    }
}
