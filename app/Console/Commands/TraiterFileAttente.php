<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;

/**
 * Vide la file d'attente (notifications, calculs nocturnes), lancé chaque minute par la tâche planifiée.
 *
 * Remplace « queue:work » sur les hébergements mutualisés (Hostinger…) où proc_open et les fonctions
 * pcntl_* sont désactivées : les tâches sont traitées une à une, dans ce même processus, sans signal.
 */
class TraiterFileAttente extends Command
{
    protected $signature = 'energie:file-attente
                            {--max-time=50 : durée maximale en secondes (moins d\'une minute, avant le passage suivant)}';

    protected $description = 'Traite les tâches en attente (notifications, calculs) sans worker permanent';

    public function handle(): int
    {
        /** @var Worker $worker */
        $worker = app('queue.worker');   // enregistré sous ce nom par Laravel, pas par sa classe
        $connexion = (string) config('queue.default');
        if ($connexion === 'sync') {
            return self::SUCCESS;   // rien n'est mis en attente : tout s'exécute immédiatement
        }

        $traitees = 0;
        Event::listen(JobProcessing::class, function () use (&$traitees) {
            $traitees++;
        });

        // Comme « queue:work » : une tâche définitivement en échec est conservée dans failed_jobs
        // (consultable avec « php artisan queue:failed », relançable avec « queue:retry all »).
        Event::listen(JobFailed::class, function (JobFailed $e) {
            app('queue.failer')->log($e->connectionName, $e->job->getQueue(), $e->job->getRawBody(), $e->exception);
        });

        $options = new WorkerOptions(name: 'default', memory: 128, timeout: 60, sleep: 0, maxTries: 3);
        $file = (string) (config("queue.connections.$connexion.queue") ?: 'default');
        $limite = microtime(true) + max(1, (int) $this->option('max-time'));

        while (microtime(true) < $limite) {
            $avant = $traitees;
            $worker->runNextJob($connexion, $file, $options);   // sans signal ni sous-processus
            if ($traitees === $avant) {
                break;   // file vide (ou seulement des tâches différées)
            }
        }

        if ($traitees > 0) {
            $this->info("$traitees tâche(s) traitée(s).");
        }

        return self::SUCCESS;
    }
}
