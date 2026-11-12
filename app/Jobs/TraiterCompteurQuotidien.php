<?php

namespace App\Jobs;

use App\Models\Compteur;
use App\Services\AlerteService;
use App\Services\ConsommationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Pour un compteur : recalcul des consommations puis alertes quotidiennes. */
class TraiterCompteurQuotidien implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $compteurId, public readonly int $jours = 35) {}

    public function uniqueId(): string
    {
        return (string) $this->compteurId;
    }

    public function handle(ConsommationService $consommations, AlerteService $alertes): void
    {
        $compteur = Compteur::find($this->compteurId);
        if (! $compteur || ! $compteur->actif) {
            return;
        }

        $consommations->recalculer($compteur, $this->jours);
        $alertes->verifierQuotidien($compteur);
    }
}
