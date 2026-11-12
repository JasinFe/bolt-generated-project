<?php

namespace App\Exceptions;

use App\Models\Recharge;
use RuntimeException;

class CodeDejaEnregistre extends RuntimeException
{
    public function __construct(public readonly ?Recharge $existante)
    {
        parent::__construct('Ce code de recharge est déjà enregistré.');
    }
}
