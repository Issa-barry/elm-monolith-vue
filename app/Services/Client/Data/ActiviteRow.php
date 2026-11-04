<?php

namespace App\Services\Client\Data;

use JsonSerializable;

/**
 * Une ligne de `Api\Client\ActiviteController` (vente ou transfert logistique
 * — même forme pour les deux, cf. `ActiviteController::ventes()`/`transferts()`).
 * `dateSort` sert uniquement au tri interne (fusion + tri des deux sources
 * avant pagination) — volontairement absent de `jsonSerialize()`, jamais
 * exposé (remplace l'ancien `unset($row['date_sort'])` fait à la main après
 * pagination).
 *
 * Vente : `statutLabel` est le libellé affiché au back-office
 * (`CommandeVente::statutAffichage()`), `statutPaiement` celui de la facture.
 * Logistique : aucun paiement, les deux champs de paiement valent null.
 */
final class ActiviteRow implements JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $reference,
        public readonly ?string $statut,
        public readonly ?string $statutLabel,
        public readonly string $siteSource,
        public readonly string $siteDestination,
        public readonly ?VehiculeSummary $vehicule,
        public readonly ?string $date,
        public readonly int $dateSort,
        public readonly int $nbPacks,
        public readonly ?string $statutPaiement = null,
        public readonly ?string $statutPaiementLabel = null,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'reference' => $this->reference,
            'statut' => $this->statut,
            'statut_label' => $this->statutLabel,
            'statut_paiement' => $this->statutPaiement,
            'statut_paiement_label' => $this->statutPaiementLabel,
            'site_source' => $this->siteSource,
            'site_destination' => $this->siteDestination,
            'vehicule' => $this->vehicule,
            'date' => $this->date,
            'nb_packs' => $this->nbPacks,
        ];
    }
}
