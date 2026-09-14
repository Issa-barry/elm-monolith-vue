<?php

namespace App\Services\Communications;

use App\Enums\CommunicationEvent;
use App\Enums\CommunicationRecipientType;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageLogStatus;
use App\Enums\OtpPurpose;
use App\Models\MessageLog;
use App\Models\Personne;
use App\Models\UserAuthIdentity;
use App\Services\Otp\OtpDestinationMasker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Journalise le transport des messages SMS/WhatsApp (cf. rapport monitoring
 * Communications, 07/09/2026, et rapport notifications de commande,
 * 07/09/2026) — purement transversal, DEUX origines distinctes dont la
 * logique métier ne doit jamais se mélanger (cf. docblock App\Models\MessageLog) :
 *
 * - OTP (`logSmsOtpAttempt()`) : n'intervient jamais dans la génération/
 *   validation/expiration du code (App\Services\OtpService en reste l'unique
 *   source de vérité). Appelé uniquement depuis App\Jobs\SendSmsOtpJob, jamais
 *   depuis App\Services\Otp\Channels\SmsOtpChannel (pure plomberie de
 *   résolution de canal, sans connaissance du monitoring).
 * - Notifications transactionnelles (`logTransactionalAttempt()`) : n'intervient
 *   jamais dans la résolution des règles (App\Services\Communications\
 *   CommunicationRuleResolver en reste l'unique source de vérité) ni dans la
 *   construction du texte du message (App\Services\Communications\
 *   TransactionalMessageBuilder). Appelé uniquement depuis
 *   App\Jobs\SendTransactionalCommunicationJob.
 */
class MessageLogService
{
    public function __construct(private readonly OtpDestinationMasker $masker) {}

    /**
     * Crée l'entrée `pending` dès la tentative d'envoi (avant l'appel réseau
     * Nimba) — pour qu'aucune tentative n'échappe au monitoring, même en cas
     * d'échec total du transport.
     *
     * `organization_id` est résolu par recherche du numéro (même mécanisme
     * que App\Http\Controllers\Api\Auth\OtpLogin\RequestController), jamais
     * transmis par l'appelant : un OTP de vérification téléphone pendant une
     * inscription peut être tenté avant qu'un compte/organisation n'existe —
     * dans ce cas `organization_id` reste `null` plutôt que d'être forcé (cf.
     * rapport, point 6, même logique appliquée à `organization_id` qu'à
     * `messageable`).
     */
    public function logSmsOtpAttempt(string $phoneNumber, OtpPurpose $purpose): MessageLog
    {
        $organizationId = UserAuthIdentity::resoudre(
            UserAuthIdentity::TYPE_TELEPHONE,
            Personne::normaliserTelephone($phoneNumber),
        )?->organization_id;

        return MessageLog::create([
            'organization_id' => $organizationId,
            'channel' => MessageChannel::SMS,
            'direction' => MessageDirection::OUTBOUND,
            'purpose' => $purpose->value,
            'provider' => 'nimba',
            'masked_recipient' => $this->masker->maskPhone($phoneNumber),
            'status' => MessageLogStatus::PENDING,
        ]);
    }

    /**
     * Crée l'entrée `pending` d'une notification transactionnelle (commande
     * confirmée, chargement validé, transfert créé...) — `organization_id` et
     * `messageable` sont toujours connus (contrairement à l'OTP) : ils
     * viennent directement de la CommandeVente/TransfertLogistique à
     * l'origine de la notification, jamais résolus par recherche du numéro.
     */
    public function logTransactionalAttempt(
        string $organizationId,
        MessageChannel $channel,
        string $recipientPhone,
        CommunicationEvent $event,
        CommunicationRecipientType $recipientType,
        ?Model $messageable,
    ): MessageLog {
        return MessageLog::create([
            'organization_id' => $organizationId,
            'channel' => $channel,
            'direction' => MessageDirection::OUTBOUND,
            'purpose' => $event->value,
            'recipient_type' => $recipientType,
            'provider' => match ($channel) {
                MessageChannel::SMS => 'nimba',
                MessageChannel::WHATSAPP => 'whatsapp',
            },
            'masked_recipient' => $this->masker->maskPhone($recipientPhone),
            'status' => MessageLogStatus::PENDING,
            'messageable_type' => $messageable?->getMorphClass(),
            'messageable_id' => $messageable?->getKey(),
        ]);
    }

    /**
     * `$providerMessageId` : identifiant fournisseur de l'envoi, si le
     * fournisseur en a renvoyé un (cf. App\Contracts\SmsGateway::send() /
     * App\Contracts\WhatsAppGateway::send()) — `null` sinon, jamais une valeur
     * inventée.
     */
    public function markSent(MessageLog $log, ?string $providerMessageId): void
    {
        $log->update([
            'status' => MessageLogStatus::SENT,
            'provider_message_id' => $providerMessageId,
            'sent_at' => now(),
        ]);
    }

    /**
     * `$e` est déjà garanti sans secret ni code OTP en clair (cf. docblock
     * App\Exceptions\NimbaSmsException) — rédaction défensive supplémentaire
     * ici (troncature) uniquement en profondeur, jamais parce que le message
     * serait suspect.
     *
     * `error_code` distingue une réponse fournisseur en échec (`$e->getCode()`
     * porte alors le statut HTTP Nimba, propagé par NimbaSmsGateway) d'un
     * échec de transport sans réponse HTTP exploitable (connexion/timeout,
     * configuration manquante) — jamais déduit par correspondance de texte sur
     * le message, qui resterait fragile.
     */
    public function markFailed(MessageLog $log, Throwable $e): void
    {
        $providerStatus = $e->getCode() > 0 ? (string) $e->getCode() : null;

        $log->update([
            'status' => MessageLogStatus::FAILED,
            'provider_status' => $providerStatus,
            'error_code' => $providerStatus ?? 'transport_error',
            'error_message' => Str::limit($e->getMessage(), 500),
            'failed_at' => now(),
        ]);
    }
}
