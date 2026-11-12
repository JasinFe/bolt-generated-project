<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/** Vérifie les réglages MAIL_* de .env : php artisan energie:tester-email vous@exemple.com */
class TesterEmail extends Command
{
    protected $signature = 'energie:tester-email {destinataire : adresse qui doit recevoir l\'e-mail de test}';

    protected $description = 'Envoie un e-mail de test pour vérifier les réglages MAIL_* de .env';

    public function handle(): int
    {
        $destinataire = (string) $this->argument('destinataire');
        if (! filter_var($destinataire, FILTER_VALIDATE_EMAIL)) {
            $this->error('Adresse e-mail invalide.');

            return self::FAILURE;
        }

        $this->line(sprintf('Envoi via %s:%s (%s)…',
            config('mail.mailers.smtp.host'), config('mail.mailers.smtp.port'), config('mail.mailers.smtp.username')));

        try {
            Mail::raw(
                "Cet e-mail confirme que l'application EnerTrack peut envoyer des messages.\n\n"
                .'Les liens « mot de passe oublié » et les alertes par e-mail fonctionneront.',
                fn ($m) => $m->to($destinataire)->subject('EnerTrack — e-mail de test')
            );
        } catch (\Throwable $e) {
            $this->error('Échec de l\'envoi : '.$e->getMessage());
            $this->line('Vérifiez MAIL_HOST, MAIL_PORT, MAIL_USERNAME et MAIL_PASSWORD dans .env (hPanel → E-mails),');
            $this->line('puis relancez « php artisan energie:installer » pour que la modification soit prise en compte.');

            return self::FAILURE;
        }

        $this->info("E-mail envoyé à $destinataire. Vérifiez la boîte de réception (et les indésirables).");

        return self::SUCCESS;
    }
}
