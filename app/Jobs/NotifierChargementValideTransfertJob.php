<?php

namespace App\Jobs;

use App\Enums\CommunicationEvent;
use App\Enums\CommunicationModule;
use App\Models\TransfertLogistique;
use App\Services\Communications\TransactionalCommunicationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Déclenche les notifications transactionnelles SMS/WhatsApp (cf. rapport
 * notifications de commande, 07/09/2026) pour l'événement `chargement_valide`
 * d'un transfert logistique — transition `CHARGEMENT → TRANSIT` dans
 * `TransfertStatutController::avancer()`. Livreur UNIQUEMENT :
 * App\Models\TransfertLogistique n'a aucun `client_id` (mouvement inter-sites
 * pur) — ne crée jamais de règle/notification "client" ici, cf.
 * NotifierChargementValideCommandeVenteJob pour l'équivalent Ventes (livreur +
 * client).
 */
class NotifierChargementValideTransfertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly string $transfertId,
        private readonly string $reference,
    ) {}

    public function handle(TransactionalCommunicationDispatcher $communications): void
    {
        $transfert = TransfertLogistique::with('equipeLivraison.livreurs')->find($this->transfertId);

        if (! $transfert) {
            return;
        }

        $livreurs = $transfert->equipeLivraison?->livreurs ?? collect();
        foreach ($livreurs as $livreur) {
            $communications->notifierLivreur(
                CommunicationModule::LOGISTIQUE,
                CommunicationEvent::CHARGEMENT_VALIDE,
                $livreur,
                $transfert,
                $this->reference,
            );
        }
    }
}
