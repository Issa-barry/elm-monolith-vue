<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerte envoyée quand la génération de commission d'une vente n'a produit
 * AUCUNE enveloppe (barème manquant pour au moins une catégorie — cas
 * silencieux, statut "succès" côté commission_generation_attempts puisqu'aucune
 * erreur technique ne s'est produite) OU quand la génération a réellement
 * échoué (motif d'erreur renseigné). Dans les deux cas, une facture encaissée
 * ne doit jamais laisser une commission manquante passer inaperçue.
 */
class CommissionManquanteNotification extends Notification
{
    public function __construct(
        private readonly string $commandeId,
        private readonly string $reference,
        private readonly float $totalCommande,
        private readonly ?string $motifErreur = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function raison(): string
    {
        return $this->motifErreur
            ?? 'Aucun barème de commission actif ne couvre cette vente (catégorie non configurée dans Paramètres > Commissions).';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'commission_manquante',
            'titre' => 'Commission non générée',
            'message' => "Réf. {$this->reference} — facture encaissée sans commission générée.",
            'commande_id' => $this->commandeId,
            'reference' => $this->reference,
            'raison' => $this->raison(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $montantFormate = number_format($this->totalCommande, 0, ',', ' ');

        return (new MailMessage)
            ->subject("Commission non générée — {$this->reference}")
            ->greeting('Commission manquante')
            ->line("La facture de la commande {$this->reference} ({$montantFormate} GNF) a été encaissée, mais aucune commission n'a été générée.")
            ->line("Raison : {$this->raison()}")
            ->action('Voir la commande', url("/backoffice/ventes/{$this->commandeId}"))
            ->line('Vérifiez le barème de commission de la catégorie concernée dans Paramètres > Commissions, puis relancez la génération si nécessaire.');
    }
}
