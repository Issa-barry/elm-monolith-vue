<?php

namespace App\Services;

use App\Models\CommandeVente;
use App\Models\EquipeLivraison;
use App\Models\Vehicule;
use InvalidArgumentException;

/**
 * Calcul centralisé des commissions multi-bénéficiaires.
 *
 * Règles métier :
 *  - commission_totale = max(prix_vente - prix_usine, 0) sur toutes les lignes
 *  - Chaque membre de l'équipe a son propre taux (snapshot depuis equipe_livreurs)
 *  - Le propriétaire a son taux depuis vehicule.taux_commission_proprietaire
 *  - Validation : taux_proprietaire + SUM(taux membres) = 100 (± 0.01)
 *  - Frais supplémentaires déductibles uniquement de la part propriétaire
 */
class CommissionCalculator
{
    /**
     * Calcule depuis une commande réelle.
     *
     * @return array{
     *   commission_totale: float,
     *   parts: array<int, array{
     *     type_beneficiaire: string,
     *     livreur_id: int|null,
     *     proprietaire_id: int|null,
     *     beneficiaire_nom: string,
     *     taux_commission: float,
     *     montant_brut: float,
     *     frais_supplementaires: float,
     *     montant_net: float,
     *   }>
     * }
     *
     * @throws InvalidArgumentException
     */
    public static function fromCommande(CommandeVente $commande): array
    {
        $vehicule = $commande->relationLoaded('vehicule')
            ? $commande->vehicule
            : $commande->load('vehicule.equipe.membres.livreur', 'vehicule.proprietaire')->vehicule;

        if (! $vehicule) {
            throw new InvalidArgumentException('La commande ne possède pas de véhicule lié.');
        }

        if (! $vehicule->equipe) {
            throw new InvalidArgumentException(
                "Le véhicule « {$vehicule->nom_vehicule} » n'a pas d'équipe de livraison assignée."
            );
        }

        $lignes = $commande->relationLoaded('lignes')
            ? $commande->lignes
            : $commande->load('lignes')->lignes;

        $prixVente = $lignes->sum(fn ($l) => $l->qte * (float) $l->prix_vente_snapshot);
        $prixUsine = $lignes->sum(fn ($l) => $l->qte * (float) $l->prix_usine_snapshot);

        return self::fromVehiculeEtMontants($vehicule, $prixVente, $prixUsine);
    }

    /**
     * Calcule depuis un véhicule et des montants bruts (pour aperçu ou tests).
     *
     * @throws InvalidArgumentException
     */
    public static function fromVehiculeEtMontants(Vehicule $vehicule, float $prixVente, float $prixUsine): array
    {
        $equipe = $vehicule->relationLoaded('equipe')
            ? $vehicule->equipe
            : $vehicule->load('equipe.membres.livreur')->equipe;

        if (! $equipe) {
            throw new InvalidArgumentException("Aucune équipe assignée au véhicule.");
        }

        $tauxProprietaire = (float) $vehicule->taux_commission_proprietaire;
        $proprietaire     = $vehicule->relationLoaded('proprietaire')
            ? $vehicule->proprietaire
            : $vehicule->load('proprietaire')->proprietaire;

        self::validateTauxTotal($equipe, $tauxProprietaire);

        $commissionTotale = max(0.0, round($prixVente - $prixUsine, 2));

        $parts = [];

        // Parts livreurs (membres de l'équipe)
        $membres = $equipe->relationLoaded('membres') ? $equipe->membres : $equipe->load('membres.livreur')->membres;

        foreach ($membres as $membre) {
            $livreur = $membre->livreur;
            $taux    = (float) $membre->taux_commission;
            $brut    = round($commissionTotale * $taux / 100, 2);

            $parts[] = [
                'type_beneficiaire'  => 'livreur',
                'livreur_id'         => $livreur?->id,
                'proprietaire_id'    => null,
                'beneficiaire_nom'   => $livreur ? trim($livreur->prenom.' '.$livreur->nom) : "Livreur #{$membre->livreur_id}",
                'taux_commission'    => $taux,
                'montant_brut'       => $brut,
                'frais_supplementaires' => 0.0,
                'montant_net'        => $brut,
            ];
        }

        // Part propriétaire
        $brutProp = round($commissionTotale * $tauxProprietaire / 100, 2);
        $parts[] = [
            'type_beneficiaire'  => 'proprietaire',
            'livreur_id'         => null,
            'proprietaire_id'    => $proprietaire?->id,
            'beneficiaire_nom'   => $proprietaire
                ? trim($proprietaire->prenom.' '.$proprietaire->nom)
                : 'Propriétaire',
            'taux_commission'    => $tauxProprietaire,
            'montant_brut'       => $brutProp,
            'frais_supplementaires' => 0.0,
            'montant_net'        => $brutProp,
        ];

        return [
            'commission_totale' => $commissionTotale,
            'parts'             => $parts,
        ];
    }

    /**
     * Valide que la somme des taux de l'équipe + propriétaire = 100 (± 0.01).
     *
     * @throws InvalidArgumentException
     */
    public static function validateTauxTotal(EquipeLivraison $equipe, float $tauxProprietaire): void
    {
        if ($tauxProprietaire < 0 || $tauxProprietaire > 100) {
            throw new InvalidArgumentException(
                "Le taux propriétaire ({$tauxProprietaire} %) doit être compris entre 0 et 100."
            );
        }

        $membres = $equipe->relationLoaded('membres') ? $equipe->membres : $equipe->load('membres')->membres;

        foreach ($membres as $m) {
            $t = (float) $m->taux_commission;
            if ($t < 0 || $t > 100) {
                throw new InvalidArgumentException(
                    "Le taux du membre (livreur #{$m->livreur_id}) ({$t} %) doit être compris entre 0 et 100."
                );
            }
        }

        $sommeMembres = (float) $membres->sum('taux_commission');
        $total = round($tauxProprietaire + $sommeMembres, 10);

        if (abs($total - 100.0) > 0.01) {
            throw new InvalidArgumentException(
                "La somme des taux ({$total} %) doit être égale à 100 %. "
                ."Vérifiez la configuration du véhicule et de l'équipe."
            );
        }
    }
}
