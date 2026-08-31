<?php

namespace App\Jobs;

use App\Contracts\SmsGateway;
use App\Enums\OtpPurpose;
use App\Services\Otp\OtpChannelResolver;
use App\Services\Otp\OtpFallbackTarget;
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
 * `$tries = 1` — volontaire, AUCUN retry automatique SUR NIMBA : un OTP a une
 * durée de vie courte (cf. OtpService::ttlMinutes()) et retenter le MÊME appel
 * risquerait un second SMS facturé/reçu en double si le premier a en réalité
 * abouti côté Nimba malgré un timeout/erreur ambiguë côté client. Ceci ne
 * concerne QUE Nimba : le repli vers un AUTRE canal (`$fallback`, ci-dessous)
 * n'est jamais un retry du même envoi, donc jamais un risque de double SMS.
 *
 * N'échoue jamais vers l'appelant : au moment où ce job s'exécute, le
 * contrôleur a déjà répondu au client (le challenge OTP est généré, la
 * réponse HTTP annonçait déjà `channel: "sms"`) — un échec de transport ici
 * est journalisé (par NimbaSmsGateway, avec le détail utile), puis un repli
 * EXPLICITE est tenté si `$fallback` en fournit un (cf. audit du 31/08/2026,
 * point 2 : avant ce correctif, un échec Nimba réel après résolution du canal
 * ne déclenchait AUCUN repli — le code restait valide en cache mais n'était
 * jamais livré nulle part).
 */
class SendSmsOtpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly string $phoneNumber,
        private readonly string $message,
        private readonly string $code,
        private readonly OtpPurpose $purpose,
        private readonly ?OtpFallbackTarget $fallback = null,
    ) {}

    public function handle(SmsGateway $gateway, OtpChannelResolver $resolver): void
    {
        try {
            $gateway->send($this->phoneNumber, $this->message);

            return;
        } catch (\Throwable $e) {
            // NimbaSmsGateway a déjà journalisé le détail (statut HTTP, motif) —
            // ce catch existe pour garantir qu'un échec de transport ne remonte
            // JAMAIS en dehors de ce job (cf. docblock de classe), et pour
            // déclencher le repli explicite ci-dessous.
            Log::error('SendSmsOtpJob : envoi SMS OTP non abouti.', ['exception' => $e->getMessage()]);
        }

        if ($this->fallback === null) {
            Log::warning('SendSmsOtpJob : échec SMS sans canal de repli disponible pour ce compte.');

            return;
        }

        try {
            // MÊME code, jamais régénéré (cf. rapport du 27/08/2026, point 11,
            // et docblock App\Contracts\OtpDeliveryChannel::send()).
            $resolver->resolve($this->fallback->channel)->send($this->fallback->destination, $this->code, $this->purpose);

            Log::info('SendSmsOtpJob : repli déclenché après échec SMS.', ['fallback_channel' => $this->fallback->channel->value]);
        } catch (\Throwable $e) {
            Log::error('SendSmsOtpJob : le repli après échec SMS a lui aussi échoué.', [
                'fallback_channel' => $this->fallback->channel->value,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
