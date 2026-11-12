<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

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

        // Une configuration en cache masquerait les réglages de .env : on l'efface, puis on repart.
        if (app()->configurationIsCached()) {
            @unlink(app()->getCachedConfigPath());
            $this->warn('Une ancienne configuration en cache a été effacée : relancez cette même commande.');

            return self::FAILURE;
        }

        $this->genererCles($env, $contenu);

        // Base SQLite (développement) : le fichier doit exister avant la migration.
        $contenu = file_get_contents($env);
        if (preg_match('/^DB_CONNECTION=sqlite\s*$/m', $contenu) && ! preg_match('/^DB_DATABASE=\S/m', $contenu)) {
            $fichier = database_path('database.sqlite');
            is_file($fichier) || touch($fichier);
        }

        // Tout s'exécute dans ce même processus : Hostinger désactive proc_open (pas de sous-processus).
        // Les réglages de base de données ont été lus au démarrage, depuis le .env rempli à la main.
        try {
            $resultat = $this->call('migrate', ['--force' => true]);
        } catch (\Throwable $e) {
            $this->error('Échec de la création des tables : '.$e->getMessage());
            $this->diagnostiquerBase($e, $contenu);

            return self::FAILURE;
        }

        if ($resultat !== self::SUCCESS) {
            $this->line('Vérifiez DB_DATABASE, DB_USERNAME et DB_PASSWORD dans .env (hPanel → Bases de données).');

            return self::FAILURE;
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

    /** Explique en clair un refus de connexion à la base, sans jamais afficher le mot de passe. */
    private function diagnostiquerBase(\Throwable $e, string $contenu): void
    {
        $connexion = (string) config('database.default');
        $reglages = (array) config("database.connections.$connexion");
        $message = $e->getMessage();

        $this->newLine();
        $this->line('Réglages lus dans .env :');
        $this->line('  base         : '.($reglages['database'] ?? '?'));
        $this->line('  utilisateur  : '.($reglages['username'] ?? '?'));
        $this->line('  hôte         : '.($reglages['host'] ?? '?'));
        $this->line('  mot de passe : '.mb_strlen((string) ($reglages['password'] ?? '')).' caractère(s) lu(s)');
        $this->newLine();

        if (str_contains($message, '[1045]') || str_contains($message, 'Access denied')) {
            $this->line('La base refuse cet utilisateur avec ce mot de passe.');

            // Piège fréquent : un « # » non protégé coupe le mot de passe, la suite est ignorée.
            if (preg_match('/^DB_PASSWORD=(.*)$/m', $contenu, $m)) {
                $brut = trim($m[1]);
                $protege = $brut !== '' && in_array($brut[0], ['"', "'"], true);
                if (! $protege && str_contains($brut, '#')) {
                    $this->warn('Votre mot de passe contient « # » sans apostrophes : tout ce qui suit « # » est ignoré.');
                    $this->warn("Écrivez-le entre apostrophes dans .env, par exemple : DB_PASSWORD='votre#mot-de-passe'");

                    return;
                }
            }

            $this->line('1. Comparez le nombre de caractères ci-dessus avec votre mot de passe.');
            $this->line("2. Par précaution, mettez-le entre apostrophes dans .env : DB_PASSWORD='votre-mot-de-passe'");
            $this->line('3. Vérifiez la base et l\'utilisateur dans hPanel → Bases de données → Gestion.');
            $this->line('4. En cas de doute, changez le mot de passe de la base dans hPanel, reportez-le dans .env,');
            $this->line('   attendez une minute, puis relancez cette commande.');
        } elseif (str_contains($message, '[1049]') || str_contains($message, 'Unknown database')) {
            $this->line('Cette base n\'existe pas : recopiez son nom exact depuis hPanel → Bases de données (préfixe compris).');
        } elseif (str_contains($message, '[2002]') || str_contains($message, 'Connection refused')) {
            $this->line('Serveur de base injoignable : sur Hostinger, DB_HOST doit être « localhost ».');
        } else {
            $this->line('Vérifiez DB_DATABASE, DB_USERNAME et DB_PASSWORD dans .env (hPanel → Bases de données).');
        }
    }

    /** La ligne de cron exacte pour ce serveur : même PHP que celui qui exécute l'installation. */
    private function commandeCron(): string
    {
        return sprintf('%s %s schedule:run >/dev/null 2>&1', PHP_BINARY, base_path('artisan'));
    }
}
