<?php

namespace App\Services;

use App\Enums\AuditEvent;
use App\Enums\EvenementComptable;
use App\Enums\StatutCommission;
use App\Enums\StatutPeriodePaiement;
use App\Enums\StatutPieceComptable;
use App\Enums\TypePeriodePaiement;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionLogistiquePart;
use App\Models\PaiementFiche;
use App\Models\PaiementFicheLigne;
use App\Models\PaiementFichePaiement;
use App\Models\PaiementPeriode;
use App\Models\PieceComptable;
use App\Models\User;
use App\Services\Comptabilite\EcritureComptableService;
use App\Services\Comptabilite\FicheComptabilisationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Seul point de passage CALCULEE → VALIDEE d'une période de paiement (manuel ou automatique)
 * et de sa réouverture VALIDEE → CALCULEE. Le contrôleur n'a plus de logique propre : la
 * validation automatique applique ainsi exactement les mêmes contrôles et les mêmes effets
 * (activation des commissions, comptabilisation) que le bouton « Valider la période de paiement ».
 */
class PeriodeValidationService
{
    /** Types dont les commissions sont validées part par part (validated_at). */
    public const TYPES_AUTOMATIQUES = [
        TypePeriodePaiement::LIVREUR,
        TypePeriodePaiement::PROPRIETAIRE,
        TypePeriodePaiement::SITE,
        TypePeriodePaiement::CONSULTANT,
    ];

    private const EVENEMENTS_FICHE_VALIDEE = [
        EvenementComptable::FICHE_LIVREUR_VALIDEE,
        EvenementComptable::FICHE_PROPRIETAIRE_VALIDEE,
        EvenementComptable::FICHE_SITE_VALIDEE,
        EvenementComptable::FICHE_CONSULTANT_VALIDEE,
    ];

    public function __construct(
        private readonly FicheComptabilisationService $ficheComptabilisation,
        private readonly EcritureComptableService $ecritures,
    ) {}

    /**
     * Valide la période si toutes les règles métier sont satisfaites.
     *
     * @return ?string message d'erreur métier, null si la période a été validée
     */
    public function valider(PaiementPeriode $periode, ?User $user, bool $automatique = false): ?string
    {
        if (! $periode->peutEtreValidee()) {
            return 'Seule une période calculée peut être validée.';
        }

        // Combine toujours vente + logistique (jamais un choix) : une période
        // LIVREUR/PROPRIETAIRE peut porter les deux natures de commission, et aucune des
        // deux ne doit jamais être ignorée. Sans effet sur une période SALARIE
        // (partsPourPeriode y est structurellement toujours vide, aucune fiche salarié
        // ne référence CommissionEnveloppePart).
        $nonValidees = CommissionAdjustmentService::partsLogistiqueNonValidees($periode)
            ->merge(CommissionAdjustmentService::partsNonValidees($periode));
        if ($nonValidees->isNotEmpty()) {
            $n = $nonValidees->count();

            return "{$n} commission".($n > 1 ? 's' : '').' non validée'.($n > 1 ? 's' : '').". Passez par l'écran d'ajustement avant de valider la période.";
        }

        $resumeLogistique = CommissionAdjustmentService::resumeEcartsLogistique($periode);
        $resumeVente = CommissionAdjustmentService::resumeEcarts($periode);
        $parVehicule = [...$resumeLogistique['par_vehicule'], ...$resumeVente['par_vehicule']];
        if (! empty($parVehicule)) {
            $ecart = round($resumeLogistique['ecart'] + $resumeVente['ecart'], 2);
            $abs = number_format(abs($ecart), 0, ',', ' ');
            $n = count($parVehicule);
            $sens = $ecart < 0 ? "il reste {$abs} GNF à redistribuer" : "le montant ajusté dépasse de {$abs} GNF le montant théorique";

            return "Impossible de valider : {$sens} sur {$n} véhicule(s). La somme des montants ajustés doit toujours égaler la somme des montants théoriques, véhicule par véhicule sur l'ensemble de la période. Passez par l'écran d'ajustement.";
        }

        $periode->update([
            'statut' => StatutPeriodePaiement::VALIDEE->value,
            'validated_by' => $user?->id,
            'validated_at' => now(),
        ]);

        // Seul moment où les commissions encore CREEE de cette période deviennent
        // payables — cf. CommissionAdjustmentService::activerCommissionsCreees().
        $nbCommissionsActivees = CommissionAdjustmentService::activerCommissionsCreees($periode)
            + CommissionAdjustmentService::activerCommissionsLogistiqueCreees($periode);

        app(AuditLogService::class)->record($periode, AuditEvent::VALIDATED, $user, null, null, [
            'module' => 'periodes_paiement',
            'site_id' => $periode->site_id,
            'automatique' => $automatique,
            'description' => $automatique
                ? "Période {$periode->reference} validée automatiquement : toutes ses commissions sont validées ({$nbCommissionsActivees} commission(s) activée(s))"
                : "Période {$periode->reference} validée ({$nbCommissionsActivees} commission(s) de vente activée(s))",
        ]);

        // Comptabilité générale : engagement de la dette envers chaque bénéficiaire,
        // en aval — ne doit jamais faire échouer la validation métier (mode shadow,
        // cf. règle #26 de la spec). Une pièce déjà comptabilisée (idempotence) ou un
        // mapping non configuré ne bloque pas la validation de la période.
        foreach ($periode->fiches as $fiche) {
            try {
                $this->ficheComptabilisation->comptabiliserFicheValidee($fiche);
            } catch (\Throwable $e) {
                Log::error('Comptabilisation fiche validée échouée', [
                    'fiche_id' => $fiche->id,
                    'periode_id' => $periode->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Validation automatique dès que toutes les commissions de la période sont validées
     * (même contrôles que valider()). Ignorée pour une période sans aucune commission : ne
     * jamais valider « par défaut » une période qui ne porte que des dépenses. Attribuée à
     * l'utilisateur courant s'il a le droit de valider la période, sinon au système.
     */
    public function validerSiComplete(PaiementPeriode $periode): bool
    {
        $courant = auth()->user();
        $user = $courant instanceof User && $courant->can('gererValidation', $periode) ? $courant : null;

        if (! in_array($periode->type, self::TYPES_AUTOMATIQUES, true) || ! $periode->isCalculee()) {
            return false;
        }

        $aDesCommissions = CommissionAdjustmentService::partsPourPeriode($periode)->isNotEmpty()
            || CommissionAdjustmentService::partsLogistiquePourPeriode($periode)->isNotEmpty();
        if (! $aDesCommissions) {
            return false;
        }

        return $this->valider($periode, $user, automatique: true) === null;
    }

    /**
     * Réouverture automatique d'une période validée dont les fiches ne reflètent plus les
     * commissions : une commission datée dans ses bornes n'est sur aucune fiche (arrivée après
     * la validation), ou une fiche non figée porte une commission annulée/supprimée depuis
     * (retour de livraison, annulation de commande).
     * Possible même si la période a déjà reçu des paiements (ADR 0010, point 4) : le recalcul
     * ne touche jamais une fiche figée, et une commission arrivée ensuite va sur une fiche
     * complémentaire. Une ligne annulée sur une fiche figée ne justifie donc pas de réouverture,
     * puisque le recalcul ne pourrait pas la retirer.
     * Les pièces comptables des fiches recalculées (non figées) sont contrepassées avant le
     * recalcul, sinon la revalidation engagerait la dette une seconde fois.
     */
    public function rouvrirSiDesynchronisee(PaiementPeriode $periode): bool
    {
        if (! $periode->isValidee() || ! in_array($periode->type, self::TYPES_AUTOMATIQUES, true)) {
            return false;
        }

        $horsFiches = app(PeriodeCalculatorService::class)->commissionsHorsFiches($periode);
        $obsoletes = $this->lignesObsoletes($periode);
        if ($horsFiches['nombre'] === 0 && $obsoletes === 0) {
            return false;
        }

        $motif = $horsFiches['nombre'] > 0
            ? "{$horsFiches['nombre']} commission(s) arrivée(s) après la validation"
            : "{$obsoletes} commission(s) annulée(s) après la validation (retour ou annulation de commande)";

        try {
            DB::transaction(function () use ($periode, $motif) {
                foreach ($this->piecesARecalculer($periode) as $piece) {
                    $this->ecritures->contrepasser($piece, "Réouverture de la période {$periode->reference}");
                }

                $periode->update([
                    'statut' => StatutPeriodePaiement::CALCULEE->value,
                    'validated_by' => null,
                    'validated_at' => null,
                    // Force le recalcul (needsRecalcul) pour intégrer les nouvelles commissions.
                    'calcul_hash' => null,
                ]);

                app(AuditLogService::class)->record($periode, AuditEvent::STATUS_CHANGED, null, null, null, [
                    'module' => 'periodes_paiement',
                    'site_id' => $periode->site_id,
                    'statut_avant' => StatutPeriodePaiement::VALIDEE->value,
                    'statut_apres' => StatutPeriodePaiement::CALCULEE->value,
                    'description' => "Période {$periode->reference} rouverte automatiquement : {$motif}",
                ]);
            });
        } catch (\Throwable $e) {
            // Une contrepassation impossible laisse la période validée (et la commission
            // signalée) plutôt que de risquer une dette comptabilisée deux fois.
            Log::error('Réouverture automatique de période échouée', [
                'periode_id' => $periode->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Lignes de fiche non figée dont la commission source a été annulée ou supprimée depuis le
     * calcul (retour de livraison, annulation de commande) : la fiche porterait un montant périmé.
     */
    private function lignesObsoletes(PaiementPeriode $periode): int
    {
        $ficheIds = $periode->fiches()->get()
            ->reject(fn (PaiementFiche $f) => $f->estFigee())
            ->pluck('id');

        $lignes = PaiementFicheLigne::whereIn('fiche_id', $ficheIds)
            ->whereIn('source_type', [CommissionEnveloppePart::class, CommissionLogistiquePart::class])
            ->get(['source_type', 'source_id']);

        $obsoletes = 0;
        foreach ($lignes->groupBy('source_type') as $type => $groupe) {
            $actives = $type::whereIn('id', $groupe->pluck('source_id'))
                ->where('statut', '!=', StatutCommission::ANNULEE->value)
                ->pluck('id')
                ->flip();
            $obsoletes += $groupe->reject(fn (PaiementFicheLigne $l) => $actives->has($l->source_id))->count();
        }

        return $obsoletes;
    }

    public function aDesPaiements(PaiementPeriode $periode): bool
    {
        return (float) $periode->fiches()->sum('montant_paye') > 0.009
            || PaiementFichePaiement::whereIn('fiche_id', $periode->fiches()->select('id'))->exists();
    }

    /**
     * Pièces « fiche validée » encore actives des fiches que le recalcul va supprimer
     * (toutes sauf les fiches figées, cf. PaiementFiche::estFigee() et
     * PeriodeCalculatorService::calculer()).
     *
     * @return iterable<PieceComptable>
     */
    private function piecesARecalculer(PaiementPeriode $periode): iterable
    {
        $ficheIds = $periode->fiches()->get()
            ->reject(fn (PaiementFiche $f) => $f->estFigee())
            ->pluck('id');

        return PieceComptable::query()
            ->where('organization_id', $periode->organization_id)
            ->where('source_type', (new PaiementFiche)->getMorphClass())
            ->whereIn('source_id', $ficheIds)
            ->whereIn('type_evenement', array_map(fn (EvenementComptable $e) => $e->value, self::EVENEMENTS_FICHE_VALIDEE))
            ->where('statut', StatutPieceComptable::VALIDEE->value)
            ->whereNotExists(fn ($q) => $q->from('compta_pieces as extournes')
                ->whereColumn('extournes.piece_origine_id', 'compta_pieces.id'))
            ->get();
    }
}
