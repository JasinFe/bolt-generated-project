<?php

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use RuntimeException;

/** Tâche de test qui échoue toujours (simule un serveur d'e-mail injoignable). */
class TacheQuiEchoue implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public function handle(): void
    {
        throw new RuntimeException('Serveur d\'e-mail injoignable');
    }
}
