<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Installation ou mise à jour en une commande (SSH ou tâche cron ponctuelle) :
 *   php artisan energie:installer
 *
 * Peut être relancée sans risque : une clé déjà présente n'est JAMAIS remplacée.
 */
class Installer extends Command
{
    protected $signature = 'energie:installer';

    protected $description = 'Prépare .env (clés), crée ou met à jour la base, met la configuration en cache';

    public function handle(): int
    {
        $env = base_path('.env');
        if (! is_file($env)) {
            copy(base_path('.env.example'), $env);
            $this->info('.env créé à partir de .env.example : pensez à y régler la base de données et l\'adresse du site.');
        }

        $contenu = file_get_contents($env);
        $creees = [];

        $cles = [
            'APP_KEY' => fn () => 'base64:'.base64_encode(random_bytes(32)),
            'ENERGIE_CODE_PEPPER' => fn () => bin2hex(random_bytes(32)),
        ];

        foreach ($cles as $nom => $generer) {
            if (preg_match('/^'.$nom.'=(.*)$/m', $contenu, $m)) {
                if (trim($m[1], " \t\r\"'") !== '') {
                    continue;   // déjà définie : on n'y touche pas
                }
                $contenu = preg_replace('/^'.$nom.'=.*$/m', $nom.'='.$generer(), $contenu, 1);
            } else {
                $contenu = rtrim($contenu)."\n".$nom.'='.$generer()."\n";
            }
            $creees[] = $nom;
        }

        if ($creees) {
            file_put_contents($env, $contenu);
            $this->info('Clé(s) générée(s) dans .env : '.implode(', ', $creees).'.');
            $this->warn('Sauvegardez ces lignes de .env hors du serveur : sans elles, les codes enregistrés sont illisibles.');
        }

        // Base SQLite : le fichier doit exister avant la migration.
        if (preg_match('/^DB_CONNECTION=sqlite\s*$/m', $contenu) && ! preg_match('/^DB_DATABASE=\S/m', $contenu)) {
            $fichier = database_path('database.sqlite');
            is_file($fichier) || touch($fichier);
        }

        // Chaque étape dans un nouveau processus, pour relire le .env qui vient d'être modifié.
        foreach ([['config:clear'], ['migrate', '--force'], ['config:cache']] as $etape) {
            $processus = new Process(
                [PHP_BINARY, base_path('artisan'), ...$etape, '--no-interaction'],
                base_path(),
                ['APP_KEY' => false, 'ENERGIE_CODE_PEPPER' => false],   // ne pas hériter des valeurs vides
                null,
                600
            );
            $processus->run(fn ($type, $sortie) => $this->output->write($sortie));

            if (! $processus->isSuccessful()) {
                $this->error('Échec de « php artisan '.implode(' ', $etape).' ». Vérifiez les réglages DB_* de .env.');

                return self::FAILURE;
            }
        }

        $this->info('Installation terminée.');

        return self::SUCCESS;
    }
}
