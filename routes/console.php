<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// Une seule tâche cron, toutes les minutes :
//   * * * * * /chemin/vers/php /chemin/vers/artisan schedule:run
//
// Tout s'exécute DANS le processus de schedule:run (Schedule::call), jamais dans un sous-processus :
// les hébergements mutualisés comme Hostinger désactivent proc_open, dont Schedule::command a besoin,
// ainsi que les fonctions pcntl_* (signaux).

/** Lance une commande artisan dans ce processus ; un échec est écrit dans storage/logs. */
$lancer = fn (string $commande) => function () use ($commande) {
    $code = Artisan::call($commande);
    if ($code !== 0) {
        Log::error("Tâche planifiée « $commande » en échec (code $code)", ['sortie' => Artisan::output()]);
    }
};

// releaseOnTerminationSignals: false → pas d'appel à pcntl_* (désactivées chez Hostinger).
// Durées des verrous courtes : après un plantage, la tâche repart d'elle-même.
Schedule::call($lancer('energie:file-attente'))
    ->name('energie:file-attente')
    ->everyMinute()
    ->withoutOverlapping(5, releaseOnTerminationSignals: false);

Schedule::call($lancer('energie:capteurs'))
    ->name('energie:capteurs')
    ->hourly()
    ->withoutOverlapping(30, releaseOnTerminationSignals: false);

Schedule::call($lancer('energie:quotidien'))
    ->name('energie:quotidien')
    ->dailyAt('01:15')
    ->timezone('Africa/Abidjan')
    ->withoutOverlapping(120, releaseOnTerminationSignals: false);
