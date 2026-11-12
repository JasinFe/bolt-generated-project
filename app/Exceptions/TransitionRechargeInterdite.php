<?php

namespace App\Exceptions;

use App\Enums\RechargeStatut;
use RuntimeException;

class TransitionRechargeInterdite extends RuntimeException
{
    public function __construct(RechargeStatut $de, RechargeStatut $vers)
    {
        parent::__construct(sprintf(
            'Impossible de passer une recharge de « %s » à « %s ».',
            $de->libelle(),
            $vers->libelle()
        ));
    }
}
