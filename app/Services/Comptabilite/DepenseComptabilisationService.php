<?php

namespace App\Services\Comptabilite;

use App\Enums\CategorieDepense;
use App\Enums\EvenementComptable;
use App\Models\Depense;
use App\Models\Livreur;
use App\Models\PieceComptable;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Traduit la validation d'une Depense en écriture comptable.
 *
 * Point d'attention (règle #22 de la spec) : une dépense catégorie VEHICULE/
 * PROPRIETAIRE/LIVREUR n'est PAS une charge d'ELM — DepenseImputationService
 * la traite déjà aujourd'hui comme une simple retenue sur la commission du
 * bénéficiaire. Elle est donc comptabilisée
 * comme une AVANCE récupérable sur le tiers (créance), jamais comme une
 * charge — la retenue sur la fiche mensuelle (FicheComptabilisationService,
 * rôle "avance_tiers") vient ensuite simplement SOLDER cette créance, sans
 * jamais créer une seconde charge pour le même montant.
 *
 * Seule la catégorie INTERNE est une vraie charge ELM payée en trésorerie.
 */
class DepenseComptabilisationService
{
    public function __construct(
        private readonly EcritureComptableService $ecritures,
    ) {}

    /**
     * Événement applicable à cette dépense, sans rien comptabiliser — utilisé par le
     * rattrapage historique (ComptabiliteRattrapageCommand) pour vérifier l'idempotence
     * (compta_pieces_idempotency_unique) avant de rejouer comptabiliserDepenseValidee(),
     * sans dupliquer la règle de catégorie ci-dessous à deux endroits. Null = catégorie
     * hors périmètre (EMPLOYE) ou DepenseType manquant.
     */
    public function evenementPour(Depense $depense): ?EvenementComptable
    {
        $depense->loadMissing('depenseType');

        return match ($depense->depenseType?->categorie) {
            // Une dépense Client constitue une partie du cashback réglée directement pour le
            // client. Elle est donc une charge décaissée, puis déduite du net encore versable,
            // jamais une avance tiers à solder une seconde fois.
            CategorieDepense::INTERNE, CategorieDepense::CLIENT => EvenementComptable::DEPENSE_INTERNE_VALIDEE,
            CategorieDepense::VEHICULE, CategorieDepense::PROPRIETAIRE, CategorieDepense::LIVREUR => EvenementComptable::DEPENSE_AVANCE_TIERS_VALIDEE,
            default => null,
        };
    }

    public function comptabiliserDepenseValidee(Depense $depense): ?PieceComptable
    {
        $depense->loadMissing('depenseType');
        $categorie = $depense->depenseType?->categorie;

        return match ($categorie) {
            CategorieDepense::INTERNE, CategorieDepense::CLIENT => $this->comptabiliserInterne($depense),
            CategorieDepense::VEHICULE, CategorieDepense::PROPRIETAIRE, CategorieDepense::LIVREUR => $this->comptabiliserAvanceTiers($depense, $categorie),
            // EMPLOYE (salaire) : géré par le module Paie existant, hors périmètre V1
            // de la comptabilité générale — laissé volontairement non branché.
            default => null,
        };
    }

    private function comptabiliserInterne(Depense $depense): PieceComptable
    {
        $compteChargeId = $depense->depenseType->compte_comptable_id;

        $ligneCharge = $compteChargeId
            ? ['role' => 'charge', 'sens' => 'debit', 'montant' => (float) $depense->montant, 'compte_comptable_id' => $compteChargeId]
            // Aucun compte de charge configuré sur ce DepenseType : repli sur un
            // compte de charges diverses générique (à affiner par l'expert-comptable
            // type de dépense par type de dépense).
            : ['role' => 'charge_defaut', 'sens' => 'debit', 'montant' => (float) $depense->montant];

        return $this->ecritures->comptabiliser(
            evenement: EvenementComptable::DEPENSE_INTERNE_VALIDEE,
            source: $depense,
            organizationId: $depense->organization_id,
            dateComptable: Carbon::parse($depense->date_depense),
            libelle: $depense->depenseType->libelle.' — '.$depense->id,
            lignes: [
                $ligneCharge,
                // Pas de moyen_paiement structuré sur Depense aujourd'hui : mapping
                // "tresorerie" par défaut (moyen_paiement NULL) utilisé.
                ['role' => 'tresorerie', 'sens' => 'credit', 'montant' => (float) $depense->montant],
            ],
            siteId: $depense->site_id,
            createdBy: $depense->user_id,
        );
    }

    private function comptabiliserAvanceTiers(Depense $depense, CategorieDepense $categorie): ?PieceComptable
    {
        [$tiersType, $beneficiaire] = $this->resoudreBeneficiaire($depense, $categorie);
        if (! $beneficiaire) {
            return null;
        }

        return $this->ecritures->comptabiliser(
            evenement: EvenementComptable::DEPENSE_AVANCE_TIERS_VALIDEE,
            source: $depense,
            organizationId: $depense->organization_id,
            dateComptable: Carbon::parse($depense->date_depense),
            libelle: ($depense->depenseType->libelle ?? 'Dépense').' imputée — '.$beneficiaire->getKey(),
            lignes: [
                [
                    // Même nom de rôle que côté fiche (avance_tiers_{type}) : c'est ce
                    // qui garantit que le débit ici et le crédit posté à la validation
                    // de la fiche mensuelle tombent sur EXACTEMENT le même compte —
                    // condition nécessaire pour que la créance se solde correctement.
                    'role' => 'avance_tiers_'.$tiersType,
                    'sens' => 'debit',
                    'montant' => (float) $depense->montant,
                    'tiers_type' => $tiersType,
                    'tiers_model' => $beneficiaire,
                ],
                ['role' => 'tresorerie', 'sens' => 'credit', 'montant' => (float) $depense->montant],
            ],
            siteId: $depense->site_id,
            createdBy: $depense->user_id,
        );
    }

    /**
     * @return array{0: string, 1: ?Model}
     */
    private function resoudreBeneficiaire(Depense $depense, CategorieDepense $categorie): array
    {
        if ($categorie === CategorieDepense::VEHICULE) {
            $vehicule = Vehicule::find($depense->beneficiaire_id);

            return $vehicule?->proprietaire_id
                ? ['proprietaire', Proprietaire::find($vehicule->proprietaire_id)]
                : ['proprietaire', null];
        }

        if ($categorie === CategorieDepense::PROPRIETAIRE) {
            return ['proprietaire', Proprietaire::find($depense->beneficiaire_id)];
        }

        return ['livreur', Livreur::find($depense->beneficiaire_id)];
    }
}
