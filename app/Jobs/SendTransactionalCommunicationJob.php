<?php

namespace App\Jobs;

use App\Contracts\SmsGateway;
use App\Contracts\WhatsAppGateway;
use App\Enums\CommunicationEvent;
use App\Enums\CommunicationRecipientType;
use App\Enums\MessageChannel;
use App\Services\Communications\MessageLogService;
use App\Services\Communications\TransactionalMessageBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Envoi d'UNE notification transactionnelle (commande confirmée, chargement
 * validé, transfert créé...) sur UN canal précis — cf. rapport notifications
 * de commande, 07/09/2026. Dispatché uniquement par
 * App\Services\Communications\TransactionalCommunicationDispatcher, jamais
 * directement par un contrôleur : la résolution des règles/destinataires a
 * déjà eu lieu avant ce point, ce job ne fait plus que transporter+journaliser.
 *
 * Distinct de App\Jobs\SendSmsOtpJob : aucune notion de fallback ici (SMS et
 * WhatsApp sont deux canaux INDÉPENDANTS pour les notifications métier — si
 * les deux sont activés pour une règle, deux jobs sont dispatchés séparément,
 * jamais l'un en repli de l'autre). Le fallback multi-canal reste une
 * particularité du système OTP, jamais réutilisée ici.
 *
 * `$tries = 1` — même raison que SendSmsOtpJob : éviter un double SMS/WhatsApp
 * si le premier appel a en réalité abouti côté fournisseur malgré un timeout/
 * erreur ambiguë côté client.
 *
 * Un échec ici est journalisé (`message_logs`) puis avalé — ne doit jamais
 * remonter vers l'action métier (confirmation de commande, validation de
 * chargement, création de transfert), déjà terminée au moment où ce job
 * s'exécute.
 */
class SendTransactionalCommunicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly MessageChannel $channel,
        private readonly string $phoneNumber,
        private readonly string $organizationId,
        private readonly CommunicationEvent $event,
        private readonly CommunicationRecipientType $recipientType,
        private readonly string $reference,
        private readonly ?string $messageableType = null,
        private readonly ?string $messageableId = null,
    ) {}

    public function handle(MessageLogService $messageLogs, SmsGateway $smsGateway, WhatsAppGateway $whatsAppGateway): void
    {
        $messageable = ($this->messageableType && $this->messageableId)
            ? $this->messageableType::find($this->messageableId)
            : null;

        $log = $messageLogs->logTransactionalAttempt(
            $this->organizationId,
            $this->channel,
            $this->phoneNumber,
            $this->event,
            $this->recipientType,
            $messageable,
        );

        $message = TransactionalMessageBuilder::build($this->event, $this->recipientType, $this->reference);

        try {
            $providerMessageId = match ($this->channel) {
                MessageChannel::SMS => $smsGateway->send($this->phoneNumber, $message),
                MessageChannel::WHATSAPP => $whatsAppGateway->send($this->phoneNumber, $message),
            };

            $messageLogs->markSent($log, $providerMessageId);
        } catch (\Throwable $e) {
            Log::error('SendTransactionalCommunicationJob : envoi non abouti.', [
                'channel' => $this->channel->value,
                'event' => $this->event->value,
                'exception' => $e->getMessage(),
            ]);
            $messageLogs->markFailed($log, $e);
        }
    }
}
