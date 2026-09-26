<?php

namespace App\Services\Communications;

use App\Enums\ClientType;
use App\Enums\CommunicationEvent;
use App\Enums\CommunicationModule;
use App\Enums\CommunicationRecipientType;
use App\Enums\MessageChannel;
use App\Jobs\SendTransactionalCommunicationJob;
use App\Models\Client;
use App\Models\Livreur;
use Illuminate\Database\Eloquent\Model;

/**
 * Point d'entrée UNIQUE pour déclencher une notification transactionnelle
 * SMS/WhatsApp (cf. rapport notifications de commande, 07/09/2026) — décide
 * QUELS canaux partent (via App\Services\Communications\CommunicationRuleResolver)
 * et dispatche un App\Jobs\SendTransactionalCommunicationJob PAR canal activé,
 * chacun indépendant (aucun fallback SMS↔WhatsApp, contrairement à l'OTP).
 *
 * Résout le téléphone DIRECTEMENT sur le modèle métier (`Livreur::telephone`,
 * `Client::telephone`) — jamais via App\Services\Notification\
 * BeneficiaireUserResolver, qui exige un compte `User` connecté et ne
 * conviendrait pas ici : un SMS doit pouvoir joindre un livreur/client SANS
 * aucun compte applicatif.
 *
 * Absence de téléphone = aucune notification, jamais une exception (cf.
 * rapport, point 12) : appelé depuis des jobs déjà asynchrones
 * (NotifierLivreursCommandeVenteJob, NotifierLivreursTransfertJob,
 * NotifierChargementValideCommandeVenteJob, NotifierChargementValideTransfertJob),
 * une notification manquée ici ne doit jamais faire échouer le job appelant
 * ni l'action métier déjà terminée.
 */
class TransactionalCommunicationDispatcher
{
    public function __construct(private readonly CommunicationRuleResolver $rules) {}

    public function notifierLivreur(
        CommunicationModule $module,
        CommunicationEvent $event,
        Livreur $livreur,
        Model $messageable,
        string $reference,
    ): void {
        if (! $livreur->telephone) {
            return;
        }

        $this->dispatchChannels(
            $messageable->organization_id,
            $module,
            $event,
            CommunicationRecipientType::LIVREUR,
            null,
            $livreur->telephone,
            $messageable,
            $reference,
        );
    }

    public function notifierClient(
        CommunicationModule $module,
        CommunicationEvent $event,
        ?Client $client,
        Model $messageable,
        string $reference,
    ): void {
        if (! $client || ! $client->telephone) {
            return;
        }

        $this->dispatchChannels(
            $messageable->organization_id,
            $module,
            $event,
            CommunicationRecipientType::CLIENT,
            $client->type,
            $client->telephone,
            $messageable,
            $reference,
        );
    }

    private function dispatchChannels(
        string $organizationId,
        CommunicationModule $module,
        CommunicationEvent $event,
        CommunicationRecipientType $recipientType,
        ?ClientType $clientType,
        string $phone,
        Model $messageable,
        string $reference,
    ): void {
        foreach (MessageChannel::cases() as $channel) {
            if (! $this->rules->isEnabled($organizationId, $module, $event, $recipientType, $channel, $clientType)) {
                continue;
            }

            SendTransactionalCommunicationJob::dispatch(
                $channel,
                $phone,
                $organizationId,
                $event,
                $recipientType,
                $reference,
                $messageable->getMorphClass(),
                $messageable->getKey(),
            );
        }
    }
}
