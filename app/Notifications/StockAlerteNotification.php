<?php

namespace App\Notifications;

use App\Enums\StockStatut;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerte envoyée aux administrateurs (super_admin/admin_entreprise) de l'organisation quand un
 * couple PRODUIT × SITE franchit un seuil d'alerte de stock — Stock faible (activation + seuil
 * configurés par site, cf. StockStatutService::alerteActivePourSite()/seuilEffectifPourSite())
 * ou Rupture/Stock négatif (fait de disponibilité, toujours calculé indépendamment de
 * l'activation de l'alerte, cf. docs/stock-alertes.md STOCK-ALERTE-004).
 *
 * Déclenchée UNE SEULE FOIS par franchissement (transition Disponible → Faible/Rupture), jamais
 * à chaque mouvement tant que le produit reste sous le seuil — cf.
 * MouvementStockService::appliquer(), seul point d'entrée qui l'instancie. Volontairement
 * envoyée à TOUS les administrateurs de l'organisation, jamais filtrée par agence : un admin
 * doit voir les ruptures de n'importe quelle agence, pas seulement la sienne (cf. décision
 * produit 30/08/2026).
 */
class StockAlerteNotification extends Notification
{
    public function __construct(
        private readonly string $produitId,
        private readonly string $produitNom,
        private readonly ?string $siteId,
        private readonly ?string $siteNom,
        private readonly int $qteDisponible,
        private readonly int $seuil,
        private readonly StockStatut $statut,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'stock_alerte',
            'titre' => $this->statut->label(),
            'message' => "{$this->produitNom} — {$this->statut->label()} sur {$this->siteLabel()} ({$this->qteDisponible} en stock, seuil {$this->seuil}).",
            'resource' => [
                'type' => 'produit',
                'id' => $this->produitId,
            ],
            'produit_id' => $this->produitId,
            'site_id' => $this->siteId,
            'statut' => $this->statut->value,
            'qte_disponible' => $this->qteDisponible,
            'seuil' => $this->seuil,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $urgent = $this->statut === StockStatut::RUPTURE || $this->statut === StockStatut::STOCK_NEGATIF;

        return (new MailMessage)
            ->subject(($urgent ? 'Rupture de stock' : 'Stock faible')." — {$this->produitNom}")
            ->greeting($this->statut->label())
            ->line("Le produit « {$this->produitNom} » est passé en « {$this->statut->label()} » sur l'agence {$this->siteLabel()}.")
            ->line("Quantité disponible : {$this->qteDisponible} (seuil configuré : {$this->seuil}).")
            ->action('Voir le produit', route('produits.show', $this->produitId))
            ->line('Vous recevez cet email en tant qu\'administrateur — cette alerte est envoyée pour toutes les agences, quelle que soit votre agence de rattachement.');
    }

    private function siteLabel(): string
    {
        return $this->siteNom ?? 'inconnue';
    }
}
