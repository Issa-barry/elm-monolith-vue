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
 * Une génération en échec (motif renseigné) est aussi suivie dans Commissions > Monitoring
 * (CommissionMonitoringService) : l'email n'en est plus la seule trace et y renvoie. Le cas
 * « aucun barème » n'y figure pas — aucune commission n'est due, ce n'est pas une anomalie.
 *
 * Les paramètres de libellé (libelleOperation/verbeEvenement/urlPath/actionLabel) ont des
 * défauts reproduisant le texte historique "vente" — seuls les appels transfert logistique les
 * surchargent.
 *
 * `parEmail` à false (relance manuelle, 03/10/2026) : la notification reste dans l'application,
 * sans email — l'auteur de la relance voit déjà le résultat à l'écran, et l'alerte initiale a
 * été envoyée au premier échec.
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
        private readonly bool $parEmail = true,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->parEmail ? ['database', 'mail'] : ['database'];
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
            ->line($this->motifErreur
                ? 'Corrigez la configuration indiquée, puis relancez la génération depuis Commissions > Monitoring.'
                : 'Vérifiez le barème de commission de la catégorie concernée dans Paramètres > Commissions, puis relancez la génération si nécessaire.')
            ->when($this->motifErreur !== null, fn (MailMessage $mail) => $mail->line(
                "Suivi de l'anomalie : ".url('/backoffice/comptabilite/commissions/monitoring?'.http_build_query(['reference' => $this->reference, 'statut' => 'toutes']))
            ));
    }
}
