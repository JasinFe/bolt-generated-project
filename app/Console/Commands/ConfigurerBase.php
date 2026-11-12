<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PDO;
use PDOException;
use Symfony\Component\Console\Terminal;

/**
 * Règle la connexion MySQL de .env sans éditer le fichier à la main :
 *   php artisan energie:base
 *
 * Teste la connexion AVANT d'écrire ; écrit le mot de passe entre apostrophes,
 * pour que « # », « $ » ou les espaces soient lus correctement.
 */
class ConfigurerBase extends Command
{
    protected $signature = 'energie:base';

    protected $description = 'Saisit et teste les identifiants de la base de données, puis les enregistre dans .env';

    public function handle(): int
    {
        $env = base_path('.env');
        if (! is_file($env) || ! is_writable($env)) {
            $this->error('Le fichier .env est introuvable ou non modifiable.');

            return self::FAILURE;
        }

        $contenu = file_get_contents($env);
        $actuel = fn (string $nom, string $defaut = '') => preg_match('/^'.$nom.'=(.*)$/m', $contenu, $m)
            ? (trim(trim($m[1]), "\"'") ?: $defaut) : $defaut;

        if ($actuel('DB_CONNECTION', 'mysql') !== 'mysql') {
            $this->error('Cette commande ne concerne que MySQL (DB_CONNECTION=mysql).');

            return self::FAILURE;
        }

        $this->line('Valeurs à recopier depuis hPanel → Bases de données → Gestion (préfixe u…_ compris).');
        $this->line('Appuyez sur Entrée pour garder la valeur proposée entre crochets.');
        $this->newLine();

        $base = trim((string) $this->ask('Nom de la base', $actuel('DB_DATABASE')));
        $utilisateur = trim((string) $this->ask('Nom d\'utilisateur', $actuel('DB_USERNAME', $base)));
        // Masquer la saisie demande « stty », souvent indisponible sur les hébergements mutualisés.
        $motDePasse = (string) $this->secret(Terminal::hasSttyAvailable()
            ? 'Mot de passe de la base (rien ne s\'affiche pendant la saisie, c\'est normal)'
            : 'Mot de passe de la base (il s\'affichera pendant la saisie sur ce serveur)');

        if ($base === '' || $utilisateur === '' || $motDePasse === '') {
            $this->error('La base, l\'utilisateur et le mot de passe sont obligatoires.');

            return self::FAILURE;
        }
        if (str_contains($motDePasse, "'") || preg_match('/[\r\n]/', $motDePasse)) {
            $this->error('Le mot de passe ne doit pas contenir d\'apostrophe (\'). Choisissez-en un autre dans hPanel.');

            return self::FAILURE;
        }

        $hote = $actuel('DB_HOST', 'localhost');
        $port = $actuel('DB_PORT', '3306');

        try {
            new PDO("mysql:host=$hote;port=$port;dbname=$base", $utilisateur, $motDePasse, [PDO::ATTR_TIMEOUT => 10]);
        } catch (PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            $this->error(match ($code) {
                1045 => 'Connexion refusée : nom d\'utilisateur ou mot de passe incorrect.',
                1044 => 'Cet utilisateur n\'a pas accès à cette base : associez-les dans hPanel → Bases de données.',
                1049 => "La base « $base » n'existe pas : vérifiez son nom exact dans hPanel.",
                2002 => "Serveur de base injoignable ($hote) : sur Hostinger, DB_HOST doit être « localhost ».",
                default => 'Connexion impossible : '.$e->getMessage(),
            });
            $this->line('.env n\'a pas été modifié. Corrigez la valeur, puis relancez cette commande.');

            return self::FAILURE;
        }

        foreach (['DB_DATABASE' => $base, 'DB_USERNAME' => $utilisateur, 'DB_PASSWORD' => "'$motDePasse'"] as $nom => $valeur) {
            $ligne = $nom.'='.$valeur;
            $contenu = preg_match('/^'.$nom.'=.*$/m', $contenu)
                ? preg_replace_callback('/^'.$nom.'=.*$/m', fn () => $ligne, $contenu, 1)
                : rtrim($contenu)."\n".$ligne."\n";
        }
        file_put_contents($env, $contenu);

        // Une configuration en cache ignorerait ces nouvelles valeurs.
        if (is_file(app()->getCachedConfigPath())) {
            @unlink(app()->getCachedConfigPath());
        }

        $this->info('Connexion réussie : identifiants enregistrés dans .env.');
        $this->line('Lancez maintenant : '.PHP_BINARY.' artisan energie:installer');

        return self::SUCCESS;
    }
}
