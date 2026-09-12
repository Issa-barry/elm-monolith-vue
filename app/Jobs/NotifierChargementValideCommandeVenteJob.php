<?php

namespace App\Jobs;

use App\Enums\CommunicationEvent;
use App\Enums\CommunicationModule;
use App\Models\CommandeVente;
use App\Services\Communications\TransactionalCommunicationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Déclenche les notifications transactionnelles SMS/WhatsApp (cf. rapport
 * notifications de commande, 07/09/2026) pour l'événement `chargement_valide`
 * d'une commande vente — transition `CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS`
 * dans `CommandeVenteStatutController::avancer()`. SEUL point qui notifie le
 * CLIENT (App\Models\TransfertLogistique n'en a aucun) : cf.
 * NotifierChargementValideTransfertJob pour l'équivalent Logistique
 * (livreur uniquement).
 *
 * Aucune notification in-app/push ici (contrairement à
 * NotifierLivreursCommandeVenteJob) : cet événement n'a jamais eu de
 * notification métier avant ce chantier, uniquement le SMS/WhatsApp
 * configurable — pas de système parallèle à faire converger.
 */
class NotifierChargementValideCommandeVenteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly string $commandeId,
        private readonly string $reference,
    ) {}

    public function handle(TransactionalCommunicationDispatcher $communications): void
    {
        $commande = CommandeVente::with(['vehicule.equipe.livreurs', 'client'])->find($this->commandeId);

        if (! $commande) {
            return;
        }

        $livreurs = $commande->vehicule?->equipe?->livreurs ?? collect();
        foreach ($livreurs as $livreur) {
            $communications->notifierLivreur(
                CommunicationModule::VENTES,
                CommunicationEvent::CHARGEMENT_VALIDE,
                $livreur,
                $commande,
                $this->reference,
            );
        }

        $communications->notifierClient(
            CommunicationModule::VENTES,
            CommunicationEvent::CHARGEMENT_VALIDE,
            $commande->client,
            $commande,
            $this->reference,
        );
    }
}
