<?php

namespace App\Services\Client\Data;

use JsonSerializable;

/**
 * Une ligne de la liste « Commandes » (`Api\Client\ActiviteController`), vente ou transfert logistique
 * (ADR 0058). `nature` dit ce qu'est l'opération, `liens` pourquoi ce compte la voit, `avancement` où
 * elle en est. `statutPaiement` reste à part, et vaut null pour un transfert, une vente sans facture
 * ou un compte seulement livreur.
 *
 * `statut` et `statut_label` répètent `avancement` le temps du déploiement : le front déjà en ligne les
 * lit encore. Ils ne portent jamais le statut brut, qui révélerait le paiement à un livreur.
 *
 * `dateSort` sert uniquement au tri interne, jamais exposé.
 */
final class ActiviteRow implements JsonSerializable
{
    /**
     * @param  array{code: string, label: string}  $nature
     * @param  list<string>  $liens
     * @param  array{code: string, label: string}  $avancement
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $reference,
        public readonly array $nature,
        public readonly array $liens,
        public readonly array $avancement,
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
            'nature' => $this->nature,
            'liens' => $this->liens,
            'avancement' => $this->avancement,
            'statut' => $this->avancement['code'],
            'statut_label' => $this->avancement['label'],
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
