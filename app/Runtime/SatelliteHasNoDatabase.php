<?php

namespace App\Runtime;

use LogicException;

/**
 * A satellite keeps no state of its own; everything it needs comes from the
 * central instance. Reaching for a database there is a programming error.
 */
final class SatelliteHasNoDatabase extends LogicException
{
    public function __construct(string $connection)
    {
        parent::__construct("a satellite has no database (connection \"{$connection}\" requested)");
    }
}
