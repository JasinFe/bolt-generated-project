<?php

namespace App\Exceptions;

use RuntimeException;

/** Relevé qui contredit les données déjà connues. `bloquant` = impossible même en forçant. */
class ReleveIncoherent extends RuntimeException
{
    public function __construct(string $message, public readonly bool $bloquant = false)
    {
        parent::__construct($message);
    }
}
