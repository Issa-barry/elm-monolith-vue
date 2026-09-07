<?php

namespace App\Services\Communications;

use App\Enums\MessageDirection;
use App\Enums\MessageLogStatus;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Models\MessageLog;
use App\Models\Personne;
use App\Models\UserAuthIdentity;
use App\Services\Otp\OtpDestinationMasker;
use Illuminate\Support\Str;
use Throwable;

/**
 * Journalise le transport des SMS OTP (cf. rapport monitoring Communications,
 * 07/09/2026) — purement transversal : n'intervient jamais dans la
 * génération/validation/expiration du code (App\Services\OtpService en reste
 * l'unique source de vérité). Appelé uniquement depuis App\Jobs\SendSmsOtpJob,
 * jamais depuis App\Services\Otp\Channels\SmsOtpChannel (qui reste de la pure
 * plomberie de résolution de canal, sans connaissance du monitoring).
 *
 * Ne journalise QUE le canal SMS/Nimba réellement câblé aujourd'hui — aucun
 * flux WhatsApp ni message entrant n'est simulé (cf. rapport, point 11).
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
            'channel' => OtpChannel::SMS,
            'direction' => MessageDirection::OUTBOUND,
            'purpose' => $purpose,
            'provider' => 'nimba',
            'masked_recipient' => $this->masker->mask(OtpChannel::SMS, $phoneNumber),
            'status' => MessageLogStatus::PENDING,
        ]);
    }

    /**
     * `$providerMessageId` : identifiant Nimba de l'envoi, si le fournisseur
     * en a renvoyé un (cf. App\Contracts\SmsGateway::send()) — `null` sinon,
     * jamais une valeur inventée.
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
