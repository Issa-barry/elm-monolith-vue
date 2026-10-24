<?php

namespace App\Notifications;

use App\Enums\GraviteAnomalieEncaissement;
use App\Models\AnomalieEncaissement;
use App\Services\Ventes\ControleEncaissements\DetecteurAnomaliesEncaissement;
use Carbon\Carbon;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * E-mail du contrôle des encaissements : UN message par destinataire et par envoi, qui regroupe
 * toutes ses alertes en attente (un premier contrôle sur l'historique ne déclenche pas une rafale
 * d'e-mails, ce qui épargne aussi le quota SMTP).
 */
class AnomaliesEncaissementEmailNotification extends Notification
{
    /** @param  Collection<int, AnomalieEncaissement>  $anomalies */
    public function __construct(private readonly Collection $anomalies) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $anomalies = $this->anomalies->sortBy(fn (AnomalieEncaissement $a) => $a->gravite->rang())->values();
        $critiques = $anomalies->where('gravite', GraviteAnomalieEncaissement::CRITIQUE)->count();
        $premiere = $anomalies->first();

        $sujet = $anomalies->count() === 1
            ? '[Contrôle des encaissements] '.$premiere->gravite->label().' — '.$premiere->type->label().' — '.$this->reference($premiere)
            : '[Contrôle des encaissements] '.$anomalies->count().' anomalies'.($critiques > 0 ? " dont {$critiques} critique(s)" : '');

        $mail = (new MailMessage)
            ->subject($sujet)
            ->greeting('Contrôle des encaissements')
            ->line($anomalies->count() === 1
                ? 'Une anomalie d\'encaissement demande votre attention.'
                : $anomalies->count().' anomalies d\'encaissement demandent votre attention.');

        foreach ($anomalies as $a) {
            $mail->line('**'.$a->gravite->label().' — '.$a->type->label().'** · '.$this->reference($a)
                .($a->site?->nom ? ' · Agence '.$a->site->nom : ''));
            if ($a->montant_facture !== null) {
                $mail->line('Facturé : '.$this->gnf($a->montant_facture).' · Encaissé net : '.$this->gnf($a->montant_encaisse_net)
                    .((float) $a->surplus > 0 ? ' · Surplus : '.$this->gnf($a->surplus) : ''));
            }
            $mail->line($a->details['explication'] ?? '');
            foreach (array_slice($a->details['encaissements'] ?? [], 0, 6) as $e) {
                $mail->line('— '.$this->gnf($e['montant']).' · '.($e['mode_label'] ?? $e['mode_paiement'] ?? '')
                    .' · '.($e['cree_le'] ? Carbon::parse($e['cree_le'])->timezone(config('app.timezone'))->format('d/m/Y H:i:s') : '—')
                    .' · '.($e['agent_nom'] ?? '—')
                    .($e['reference_paiement'] ? ' · Réf. '.$e['reference_paiement'] : ''));
            }
            $mail->line('Démarche : '.$a->type->demarche());
            $mail->line('Fiche : '.url('/backoffice/comptabilite/tresorerie/controle-encaissements/'.$a->id));
        }

        return $mail
            ->action(
                $anomalies->count() === 1 ? 'Ouvrir la fiche' : 'Ouvrir le contrôle des encaissements',
                url($anomalies->count() === 1
                    ? '/backoffice/comptabilite/tresorerie/controle-encaissements/'.$premiere->id
                    : '/backoffice/comptabilite/tresorerie/controle-encaissements'),
            )
            ->line('Aucune correction n\'est faite automatiquement : toute régularisation se fait depuis la fiche, par une personne habilitée.');
    }

    private function reference(AnomalieEncaissement $a): string
    {
        return $a->details['commande_reference'] ?? ($a->details['reference'] ?? ($a->details['piece_numero'] ?? '—'));
    }

    private function gnf(mixed $montant): string
    {
        return DetecteurAnomaliesEncaissement::gnf((float) $montant);
    }
}
