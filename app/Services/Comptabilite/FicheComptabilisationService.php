<?php

namespace App\Services\Comptabilite;

use App\Enums\EvenementComptable;
use App\Enums\StatutPieceComptable;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Models\PieceComptable;
use Illuminate\Support\Carbon;

/**
 * Traduit les événements du workflow Période/Fiche (déjà existant, inchangé)
 * en écritures comptables SYSCOHADA pour propriétaires et livreurs.
 *
 * Déclencheur d'engagement volontairement à la VALIDATION de la fiche, pas à
 * chaque CommissionPart générée : avant validation les montants peuvent
 * encore bouger (ajustements, dépenses véhicule imputées) — comptabiliser à
 * chaque commande produirait des contrepassations en cascade à chaque
 * recalcul. Le rattachement à la bonne période comptable en cas de retard de
 * validation est couvert par RegularisationClotureService, pas ici.
 */
class FicheComptabilisationService
{
    private const TYPES_SUPPORTES = ['proprietaire', 'livreur'];

    public function __construct(
        private readonly EcritureComptableService $ecritures,
    ) {}

    public function comptabiliserFicheValidee(PaiementFiche $fiche): ?PieceComptable
    {
        if (! in_array($fiche->beneficiaire_type, self::TYPES_SUPPORTES, true)) {
            return null;
        }

        $beneficiaire = $fiche->getBeneficiaireModel();
        if (! $beneficiaire) {
            return null;
        }

        $futRegularisee = $this->reprendreRegularisationSiExistante($fiche);

        $evenement = $fiche->beneficiaire_type === 'livreur'
            ? EvenementComptable::FICHE_LIVREUR_VALIDEE
            : EvenementComptable::FICHE_PROPRIETAIRE_VALIDEE;

        $lignes = [
            [
                'role' => 'charge_commission',
                'sens' => 'debit',
                'montant' => (float) $fiche->montant_brut,
            ],
            [
                'role' => 'dette_tiers',
                'sens' => 'credit',
                'montant' => (float) $fiche->montant_net,
                'tiers_type' => $fiche->beneficiaire_type,
                'tiers_model' => $beneficiaire,
            ],
        ];

        if ((float) $fiche->total_deductions > 0) {
            $lignes[] = [
                'role' => 'avance_tiers_'.$fiche->beneficiaire_type,
                'sens' => 'credit',
                'montant' => (float) $fiche->total_deductions,
                'tiers_type' => $fiche->beneficiaire_type,
                'tiers_model' => $beneficiaire,
            ];
        }

        // Cas normal : la fiche est validée avant que sa période comptable ne soit
        // clôturée → on rattache l'écriture à la date de fin de la période métier
        // (bon mois, cf. rattachement à l'exercice). Cas retard : une régularisation
        // de clôture existait déjà (période comptable désormais clôturée) → on ne
        // peut plus/ne doit plus reposter dans cette période fermée, l'écriture
        // définitive part sur la date réelle de validation (aujourd'hui). "Aujourd'hui"
        // peut très bien retomber dans la même période comptable déjà clôturée (ex:
        // clôture d'août le 20, validation réelle le 25 — toujours "en août") : on
        // autorise donc explicitement le verrou à sauter UNIQUEMENT dans ce cas précis
        // (reprise d'une régularisation déjà comptabilisée), jamais pour un événement
        // ordinaire.
        $dateComptable = $futRegularisee ? now() : Carbon::parse($fiche->periode?->date_fin ?? now());

        return $this->ecritures->comptabiliser(
            evenement: $evenement,
            source: $fiche,
            organizationId: $fiche->organization_id,
            dateComptable: $dateComptable,
            libelle: "Fiche {$fiche->reference} — {$fiche->beneficiaire_nom}",
            lignes: $lignes,
            siteId: $fiche->site_id,
            ignorerVerrouPeriode: $futRegularisee,
        );
    }

    public function comptabiliserPaiementFiche(PaiementFichePaiement $paiement): ?PieceComptable
    {
        $fiche = $paiement->fiche;
        if (! $fiche || ! in_array($fiche->beneficiaire_type, self::TYPES_SUPPORTES, true)) {
            return null;
        }

        $beneficiaire = $fiche->getBeneficiaireModel();
        if (! $beneficiaire) {
            return null;
        }

        $evenement = $fiche->beneficiaire_type === 'livreur'
            ? EvenementComptable::PAIEMENT_LIVREUR
            : EvenementComptable::PAIEMENT_PROPRIETAIRE;

        $lignes = [
            [
                'role' => 'dette_tiers',
                'sens' => 'debit',
                'montant' => (float) $paiement->montant,
                'tiers_type' => $fiche->beneficiaire_type,
                'tiers_model' => $beneficiaire,
            ],
            [
                'role' => 'tresorerie',
                'sens' => 'credit',
                'montant' => (float) $paiement->montant,
                'moyen_paiement' => $paiement->mode_paiement,
            ],
        ];

        return $this->ecritures->comptabiliser(
            evenement: $evenement,
            source: $paiement,
            organizationId: $paiement->organization_id,
            dateComptable: Carbon::parse($paiement->date_paiement ?? now()),
            libelle: "Paiement fiche {$fiche->reference} — {$fiche->beneficiaire_nom}",
            lignes: $lignes,
            siteId: $paiement->site_id,
            createdBy: $paiement->created_by,
        );
    }

    private function reprendreRegularisationSiExistante(PaiementFiche $fiche): bool
    {
        $regularisation = PieceComptable::query()
            ->where('organization_id', $fiche->organization_id)
            ->where('source_type', $fiche->getMorphClass())
            ->where('source_id', $fiche->getKey())
            ->where('type_evenement', EvenementComptable::REGULARISATION_CLOTURE_FICHE->value)
            ->where('statut', StatutPieceComptable::VALIDEE->value)
            ->first();

        if (! $regularisation) {
            return false;
        }

        $this->ecritures->contrepasser($regularisation, 'Reprise — fiche validée définitivement');

        return true;
    }
}
