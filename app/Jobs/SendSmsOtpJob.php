<?php

namespace App\Jobs;

use App\Contracts\SmsGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Envoi SMS OTP asynchrone — jamais bloquer la requête HTTP utilisateur sur
 * l'appel réseau vers le fournisseur SMS (cf. docblock App\Contracts\OtpDeliveryChannel :
 * même pattern que NotifierLivreursCommandeVenteJob/ExpoPushNotificationService,
 * ce dernier lui-même appelé depuis DispatchPushNotificationsJob).
 * Dispatché par App\Services\Otp\Channels\SmsOtpChannel::send(), jamais
 * directement par un contrôleur.
 *
 * `$tries = 1` — volontaire, AUCUN retry automatique : un OTP a une durée de
 * vie courte (cf. OtpService::ttlMinutes()) et l'utilisateur peut déjà
 * redemander un code une fois le cooldown anti-spam écoulé s'il n'arrive pas ;
 * retenter automatiquement risquerait un second SMS facturé/reçu en double si
 * le premier a en réalité abouti côté Nimba malgré un timeout/erreur ambiguë
 * côté client.
 *
 * N'échoue jamais vers l'appelant : au moment où ce job s'exécute, le
 * contrôleur a déjà répondu au client (le challenge OTP est généré) — un
 * échec de transport ici est journalisé (par NimbaSmsGateway, avec le détail
 * utile), jamais renvoyé nulle part d'autre.
 */
class SendSmsOtpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly string $phoneNumber,
        private readonly string $message,
    ) {}

    public function handle(SmsGateway $gateway): void
    {
        try {
            $gateway->send($this->phoneNumber, $this->message);
        } catch (\Throwable $e) {
            // NimbaSmsGateway a déjà journalisé le détail (statut HTTP, motif) —
            // ce catch existe uniquement pour garantir qu'un échec de transport
            // ne remonte JAMAIS en dehors de ce job (cf. docblock de classe).
            Log::error('SendSmsOtpJob : envoi SMS OTP non abouti.', ['exception' => $e->getMessage()]);
        }
    }
}
