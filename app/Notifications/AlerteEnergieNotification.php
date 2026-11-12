<?php

namespace App\Notifications;

use App\Models\Alerte;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AlerteEnergieNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Alerte $alerte) {}

    /** Canaux réglés dans config/energie.php (database par défaut ; ajoutez mail, puis push / SMS). */
    public function via(object $notifiable): array
    {
        return config('energie.canaux_alertes', ['database']);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'alerte_id' => $this->alerte->id,
            'compteur_id' => $this->alerte->compteur_id,
            'type' => $this->alerte->type,
            'niveau' => $this->alerte->niveau,
            'message' => $this->alerte->message,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(match ($this->alerte->niveau) {
                'critique' => 'Crédit électrique faible',
                'attention' => 'Alerte consommation',
                default => 'Information compteur',
            })
            ->line($this->alerte->message);
    }
}
