<?php

namespace App\Console\Commands;

use App\Jobs\TraiterCompteurQuotidien;
use App\Models\Compteur;
use Illuminate\Console\Command;

class RecalculerConsommations extends Command
{
    protected $signature = 'energie:quotidien
                            {compteur? : ID d\'un seul compteur (sinon tous les compteurs actifs)}
                            {--jours=35 : fenêtre recalculée}
                            {--sync : exécuter tout de suite, sans file d\'attente}';

    protected $description = 'Recalcule les consommations journalières et lance les alertes quotidiennes';

    public function handle(): int
    {
        $requete = Compteur::query()->where('actif', true);
        if ($id = $this->argument('compteur')) {
            $requete->whereKey($id);
        }

        $total = 0;
        $requete->select('id')->chunkById(200, function ($compteurs) use (&$total) {
            foreach ($compteurs as $c) {
                $job = new TraiterCompteurQuotidien($c->id, (int) $this->option('jours'));
                $this->option('sync') ? dispatch_sync($job) : dispatch($job);
                $total++;
            }
        });

        $this->info("$total compteur(s) traité(s).");

        return self::SUCCESS;
    }
}
