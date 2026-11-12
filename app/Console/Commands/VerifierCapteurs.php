<?php

namespace App\Console\Commands;

use App\Models\Compteur;
use App\Services\AlerteService;
use Illuminate\Console\Command;

class VerifierCapteurs extends Command
{
    protected $signature = 'energie:capteurs';

    protected $description = 'Signale les capteurs IoT silencieux';

    public function handle(AlerteService $alertes): int
    {
        Compteur::query()->where('actif', true)->whereHas('capteur', fn ($q) => $q->where('actif', true))
            ->with('capteur')
            ->chunkById(200, fn ($compteurs) => $compteurs->each(fn ($c) => $alertes->verifierCapteur($c)));

        return self::SUCCESS;
    }
}
