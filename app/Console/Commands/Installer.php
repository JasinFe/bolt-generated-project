<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Installation ou mise à jour en une commande (SSH, ou tâche cron lancée une fois) :
 *   php artisan energie:installer
 *
 * Peut être relancée sans risque : une clé déjà présente n'est JAMAIS remplacée.
 */
class Installer extends Command
{
    protected $signature = 'energie:installer';

    protected $description = 'Génère les clés de .env, crée ou met à jour la base et affiche la tâche cron à créer';

    private const PHP_MINIMUM = '8.3.0';

    public function handle(): int
    {
        if (version_compare(PHP_VERSION, self::PHP_MINIMUM, '<')) {
            $this->error(sprintf('PHP %s est trop ancien : il faut PHP %s ou plus.', PHP_VERSION, self::PHP_MINIMUM));
            $this->line('Sur Hostinger, utilisez par exemple /opt/alt/php84/usr/bin/php à la place de « php ».');

            return self::FAILURE;
        }

        $env = base_path('.env');
        if (! is_file($env)) {
            $this->error('Le fichier .env est introuvable.');
            $this->line('Copiez .env.production.example sous le nom .env, remplissez-le, puis relancez cette commande.');

            return self::FAILURE;
        }

        $contenu = file_get_contents($env);
        $aRemplir = $this->valeursARemplir($contenu);
        if ($aRemplir) {
            $this->error('Ces réglages de .env sont encore à remplir : '.implode(', ', $aRemplir).'.');

            return self::FAILURE;
        }

        foreach ([storage_path(), base_path('bootstrap/cache')] as $dossier) {
            if (! is_writable($dossier)) {
                $this->error("Le dossier $dossier n'est pas accessible en écriture (droits 755 ou 775 attendus).");

                return self::FAILURE;
            }
        }

        $this->genererCles($env, $contenu);

        // Base SQLite (développement) : le fichier doit exister avant la migration.
        $contenu = file_get_contents($env);
        if (preg_match('/^DB_CONNECTION=sqlite\s*$/m', $contenu) && ! preg_match('/^DB_DATABASE=\S/m', $contenu)) {
            $fichier = database_path('database.sqlite');
            is_file($fichier) || touch($fichier);
        }

        // Chaque étape dans un nouveau processus, pour relire le .env qui vient d'être modifié.
        // config:clear d'abord : une ancienne configuration en cache masquerait les modifications de .env.
        foreach ([['config:clear'], ['migrate', '--force']] as $etape) {
            if (! $this->artisan($etape)) {
                $this->error('Échec de « php artisan '.implode(' ', $etape).' ».');
                if ($etape[0] === 'migrate') {
                    $this->line('Vérifiez DB_DATABASE, DB_USERNAME et DB_PASSWORD dans .env (hPanel → Bases de données).');
                }

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('Installation terminée.');
        $this->newLine();
        $this->line('Tâche cron à créer (toutes les minutes), commande à copier telle quelle :');
        $this->line('  '.$this->commandeCron());

        return self::SUCCESS;
    }

    /** Valeurs laissées vides ou à leur exemple dans .env (production uniquement). */
    private function valeursARemplir(string $contenu): array
    {
        $valeur = fn (string $nom) => preg_match('/^'.$nom.'=(.*)$/m', $contenu, $m) ? trim($m[1], " \t\r\"'") : null;

        if ($valeur('APP_ENV') !== 'production') {
            return [];
        }

        $manquants = [];
        if (in_array($valeur('APP_URL'), [null, '', 'https://votre-domaine.tld', 'http://localhost', 'http://localhost:8000'], true)) {
            $manquants[] = 'APP_URL';
        }
        if (($valeur('DB_CONNECTION') ?? 'sqlite') !== 'sqlite') {
            foreach (['DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $nom) {
                if (in_array($valeur($nom), [null, '', 'u000000000_energie'], true)) {
                    $manquants[] = $nom;
                }
            }
        }
        if ($valeur('MAIL_MAILER') === 'smtp' && in_array($valeur('MAIL_PASSWORD'), [null, ''], true)) {
            $manquants[] = 'MAIL_PASSWORD';
        }

        // Valeurs d'exemple laissées telles quelles (« METTRE_ICI_… », « À REMPLIR »…).
        preg_match_all('/^([A-Z0-9_]+)=.*(METTRE_ICI|A_REMPLIR|À REMPLIR).*$/mu', $contenu, $exemples);
        $manquants = array_merge($manquants, $exemples[1]);

        return array_values(array_unique($manquants));
    }

    private function genererCles(string $env, string $contenu): void
    {
        $cles = [
            'APP_KEY' => fn () => 'base64:'.base64_encode(random_bytes(32)),
            'ENERGIE_CODE_PEPPER' => fn () => bin2hex(random_bytes(32)),
        ];
        $creees = [];

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
    }

    private function artisan(array $arguments): bool
    {
        $processus = new Process(
            [PHP_BINARY, base_path('artisan'), ...$arguments, '--no-interaction'],
            base_path(),
            ['APP_KEY' => false, 'ENERGIE_CODE_PEPPER' => false],   // ne pas hériter des valeurs vides
            null,
            600
        );
        $processus->run(fn ($type, $sortie) => $this->output->write($sortie));

        return $processus->isSuccessful();
    }

    /** La ligne de cron exacte pour ce serveur : même PHP que celui qui exécute l'installation. */
    private function commandeCron(): string
    {
        return sprintf('%s %s schedule:run >/dev/null 2>&1', PHP_BINARY, base_path('artisan'));
    }
}
