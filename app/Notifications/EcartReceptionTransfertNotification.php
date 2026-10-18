<?php

namespace App\Notifications;

use Carbon\Carbon;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Écart constaté à la réception d'un transfert logistique (quantité réceptionnée différente de la
 * quantité chargée, ou type d'écart déclaré). Signalement seulement : l'écart reste traité
 * manuellement, aucun stock n'est réintégré ni régularisé (cf. TransfertEcartReceptionService,
 * ADR 0032).
 *
 * Le constat est figé à la réception (données de la notification) : une invalidation suivie d'une
 * nouvelle réception produit un nouveau constat, jamais une réécriture de l'ancien. Les canaux sont
 * choisis par l'appelant pour que l'email parte séparément de la notification dans l'application.
 */
class EcartReceptionTransfertNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $constat  cf. TransfertEcartReceptionService::constater()
     * @param  array<int, string>  $canaux
     */
    public function __construct(
        private readonly array $constat,
        private readonly array $canaux = ['database'],
    ) {}

    public function via(object $notifiable): array
    {
        return $this->canaux;
    }

    public function toArray(object $notifiable): array
    {
        $c = $this->constat;
        $nb = count($c['lignes']);

        return [
            'type' => 'transfert_ecart_reception',
            'titre' => 'Écart de réception',
            'message' => "Réf. {$c['reference']} — {$nb} produit(s) en écart à la réception ({$c['site_source']} → {$c['site_destination']}). Vérification manuelle nécessaire.",
            'resource' => [
                'type' => 'transfert_logistique',
                'id' => $c['transfert_id'],
            ],
            'reference' => $c['reference'],
            'site_source' => $c['site_source'],
            'site_destination' => $c['site_destination'],
            'receptionnaire' => $c['receptionnaire'],
            'receptionne_le' => $c['receptionne_le'],
            'nouvelle_reception' => $c['nouvelle_reception'],
            'lignes' => $c['lignes'],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $c = $this->constat;

        $mail = (new MailMessage)
            ->subject("Écart de réception détecté — Transfert {$c['reference']}")
            ->greeting('Écart de réception')
            ->line("Le transfert {$c['reference']} présente un écart lors de sa réception.".($c['nouvelle_reception'] ? ' Il s\'agit d\'une nouvelle réception, après invalidation de la précédente.' : ''));

        foreach ($c['lignes'] as $ligne) {
            $mail->line(self::ligneTexte($ligne));
        }

        return $mail
            ->line("Agence source : {$c['site_source']}.")
            ->line("Agence destinataire : {$c['site_destination']}.")
            ->line('Réception effectuée par : '.($c['receptionnaire'] ?? '—').'.')
            ->line('Date : '.Carbon::parse($c['receptionne_le'])->format('d/m/Y à H:i').'.')
            ->action('Voir le transfert', route('logistique.show', $c['transfert_id']))
            ->line('Une vérification et un traitement manuel de cet écart sont nécessaires.');
    }

    /** @param  array<string, mixed>  $ligne */
    public static function ligneTexte(array $ligne): string
    {
        $produit = $ligne['variante'] ? "{$ligne['produit']} ({$ligne['variante']})" : $ligne['produit'];

        $details = [
            "chargé : {$ligne['quantite_chargee']}",
            "réceptionné : {$ligne['quantite_recue']}",
        ];
        if ($ligne['quantite_retournee'] > 0) {
            $details[] = "revenu au site source : {$ligne['quantite_retournee']}";
        }
        if ($ligne['ecart'] < 0) {
            $details[] = 'non réceptionné : '.abs($ligne['ecart']);
        } elseif ($ligne['ecart'] > 0) {
            $details[] = "surplus : {$ligne['ecart']}";
        }

        $type = $ligne['ecart_type_label'] ?: '—';
        $motif = $ligne['ecart_motif'] ? " — {$ligne['ecart_motif']}" : '';

        return "{$produit} — ".implode(', ', $details).". Type d'écart : {$type}{$motif}.";
    }
}
