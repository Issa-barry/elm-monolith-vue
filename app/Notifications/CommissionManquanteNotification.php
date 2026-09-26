<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerte envoyée quand la génération de commission d'une opération (vente ou transfert
 * logistique) n'a produit AUCUNE enveloppe (barème manquant pour au moins une catégorie — cas
 * silencieux, statut "succès" côté commission_generation_attempts puisqu'aucune erreur
 * technique ne s'est produite) OU quand la génération a réellement échoué (motif d'erreur
 * renseigné). Dans les deux cas, l'opération déclenchante ne doit jamais laisser une commission
 * manquante passer inaperçue — jamais bloquée pour autant (cf. CommissionEnveloppeGenerator).
 *
 * Les paramètres de libellé (libelleOperation/verbeEvenement/urlPath/actionLabel) ont des
 * défauts reproduisant le texte historique "vente" — seuls les appels transfert logistique les
 * surchargent.
 */
class CommissionManquanteNotification extends Notification
{
    public function __construct(
        private readonly string $sourceId,
        private readonly string $reference,
        private readonly float $montantReference,
        private readonly ?string $motifErreur = null,
        private readonly string $libelleOperation = 'La facture de la commande',
        private readonly string $verbeEvenement = 'encaissée',
        private readonly string $urlPath = '/backoffice/ventes/',
        private readonly string $actionLabel = 'Voir la commande',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function raison(): string
    {
        return $this->motifErreur
            ?? 'Aucun barème de commission actif ne couvre cette opération (catégorie non configurée dans Paramètres > Commissions).';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'commission_manquante',
            'titre' => 'Commission non générée',
            'message' => "Réf. {$this->reference} — opération {$this->verbeEvenement} sans commission générée.",
            'commande_id' => $this->sourceId,
            'reference' => $this->reference,
            'raison' => $this->raison(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $montantSuffix = $this->montantReference > 0
            ? ' ('.number_format($this->montantReference, 0, ',', ' ').' GNF)'
            : '';

        return (new MailMessage)
            ->subject("Commission non générée — {$this->reference}")
            ->greeting('Commission manquante')
            ->line("{$this->libelleOperation} {$this->reference}{$montantSuffix} a été {$this->verbeEvenement}, mais aucune commission n'a été générée.")
            ->line("Raison : {$this->raison()}")
            ->action($this->actionLabel, url("{$this->urlPath}{$this->sourceId}"))
            ->line('Vérifiez le barème de commission de la catégorie concernée dans Paramètres > Commissions, puis relancez la génération si nécessaire.');
    }
}
