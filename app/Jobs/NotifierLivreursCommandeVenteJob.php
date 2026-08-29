<?php

namespace App\Jobs;

use App\Models\CommandeVente;
use App\Models\Livreur;
use App\Notifications\CommandeValideeNotification;
use App\Services\Notification\BeneficiaireUserResolver;
use App\Services\Notification\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Notifie les livreurs de l'équipe affectée à la commande — SEULS
 * destinataires depuis la phase 1 de l'architecture notifications
 * (2026-08-27, cf. rapport) : le propriétaire du véhicule ne reçoit plus
 * cette notification d'affectation logistique ("vous avez une livraison à
 * faire"), qui ne le concernait pas. Il reste informé via
 * CommissionGenereeNotification — financièrement pertinente pour lui,
 * déclenchée séparément par CommissionEnveloppeGenerator une fois la
 * commission calculée pour cette même commande.
 *
 * Collecte destinataires/préférences/push centralisée dans
 * NotificationDispatcher — avant ce correctif, cette logique était dupliquée
 * ici (et absente de NotifierLivreursTransfertJob).
 */
class NotifierLivreursCommandeVenteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly string $commandeId,
        private readonly string $reference,
    ) {}

    public function handle(): void
    {
        $commande = CommandeVente::with(['site:id,nom', 'vehicule.equipe.livreurs'])
            ->find($this->commandeId);

        if (! $commande?->vehicule) {
            return;
        }

        $siteNom = $commande->site?->nom ?? '—';
        $notif = new CommandeValideeNotification($this->commandeId, $this->reference, $siteNom);

        $livreurs = $commande->vehicule->equipe?->livreurs ?? collect();
        $users = $livreurs->map(fn (Livreur $livreur) => BeneficiaireUserResolver::resolve('livreur', $livreur->id));

        NotificationDispatcher::send(
            $notif,
            $users,
            'livraisons',
            fn () => [
                'title' => 'Nouvelle commande assignée',
                'body' => "Réf. {$this->reference} — Vous avez une livraison à effectuer.",
                'data' => ['type' => 'commande_vente_validee', 'commande_id' => $this->commandeId],
            ],
        );
    }
}
