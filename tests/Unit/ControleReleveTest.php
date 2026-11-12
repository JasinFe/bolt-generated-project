<?php

namespace Tests\Unit;

use App\Support\ControleReleve;
use PHPUnit\Framework\TestCase;

class ControleReleveTest extends TestCase
{
    public function test_index(): void
    {
        $this->assertNull(ControleReleve::index(1500.0, null, null));
        $this->assertNull(ControleReleve::index(1500.0, 1490.0, 1510.0));
        $this->assertNotNull(ControleReleve::index(1480.0, 1490.0, null));
        $this->assertNotNull(ControleReleve::index(1520.0, 1490.0, 1510.0));
    }

    public function test_credit(): void
    {
        $this->assertNull(ControleReleve::credit(40.0, null));
        $this->assertNull(ControleReleve::credit(40.0, 50.0));
        $this->assertNull(ControleReleve::credit(50.3, 50.0));                 // arrondi de l'afficheur
        $this->assertNotNull(ControleReleve::credit(80.0, 50.0));
        $this->assertNull(ControleReleve::credit(140.0, 50.0, 98.5));          // recharge saisie entre les deux
        $this->assertNull(ControleReleve::credit(140.0, 50.0, 0.0, true));     // recharge de montant inconnu
    }
}
