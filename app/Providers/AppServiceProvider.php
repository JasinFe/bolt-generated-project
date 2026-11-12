<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Le lien de réinitialisation ouvre l'interface web, qui affiche le formulaire « nouveau mot de passe ».
        ResetPassword::createUrlUsing(fn (object $user, string $token) => url('/').'?'.http_build_query([
            'reinitialiser' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]));

        ResetPassword::toMailUsing(function (object $user, string $token) {
            $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

            return (new MailMessage)
                ->subject('EnerTrack — réinitialisation de votre mot de passe')
                ->greeting('Bonjour,')
                ->line('Vous recevez cet e-mail parce qu\'une réinitialisation du mot de passe a été demandée pour votre compte.')
                ->action('Choisir un nouveau mot de passe', (ResetPassword::$createUrlCallback)($user, $token))
                ->line("Ce lien expire dans $minutes minutes.")
                ->line('Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet e-mail : votre mot de passe reste inchangé.')
                ->salutation('EnerTrack');
        });
    }
}
