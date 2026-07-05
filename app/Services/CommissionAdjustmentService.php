<?php

namespace App\Services;

use App\Enums\MotifAjustementCommission;
use App\Enums\OrigineCommissionPart;
use App\Enums\StatutCommission;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionPart;
use App\Models\CommissionPartAdjustment;
use App\Models\CommissionVente;
use App\Models\PaiementFicheLigne;
use App\Models\PaiementPeriode;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ajustement et validation des commissions générées avant paiement.
 * Ne modifie jamais la configuration théorique de l'équipe (equipes_livraison / equipe_livreurs) :
 * les corrections vivent uniquement sur les commission_parts / commission_logistique_parts.
 */
class CommissionAdjustmentService
{
    /**
     * @return array{vente: Collection<int, CommissionPart>, logistique: Collection<int, CommissionLogistiquePart>}
     */
    public static function partsPourPeriode(PaiementPeriode $periode): array
    {
        $ficheIds = $periode->fiches()->pluck('id');

        $lignes = PaiementFicheLigne::whereIn('fiche_id', $ficheIds)
            ->whereIn('source_type', [CommissionPart::class, CommissionLogistiquePart::class])
            ->get(['source_type', 'source_id']);

        $venteIds = $lignes->where('source_type', CommissionPart::class)->pluck('source_id')->unique();
        $logistiqueIds = $lignes->where('source_type', CommissionLogistiquePart::class)->pluck('source_id')->unique();

        return [
            'vente' => CommissionPart::whereIn('id', $venteIds)
                ->with(['livreur', 'proprietaire', 'validateur', 'commission.commande'])
                ->get(),
            'logistique' => CommissionLogistiquePart::whereIn('id', $logistiqueIds)
                ->with(['livreur', 'proprietaire', 'validateur', 'commission.transfert'])
                ->get(),
        ];
    }

    /** Parts encore non validées et non payées/partiellement payées bloquant la validation de la période. */
    public static function partsNonValidees(PaiementPeriode $periode): Collection
    {
        $parts = self::partsPourPeriode($periode);

        return $parts['vente']->merge($parts['logistique'])
            ->filter(fn (CommissionPart|CommissionLogistiquePart $part) => ! $part->estValidee() && (float) $part->montant_verse <= 0.0)
            ->values();
    }

    /**
     * Regroupe les parts par commission (= véhicule + commande/transfert + équipe présente).
     * Le métier raisonne par véhicule/commande, pas par bénéficiaire isolé : c'est le
     * niveau auquel l'enveloppe théorique doit rester intacte après ajustement.
     *
     * @return list<array{type: string, commission_id: string, reference: string, vehicule_id: ?string, vehicule_nom: ?string, vehicule_immat: ?string, theorique: float, ajuste: float, ecart: float, parts: Collection<int, CommissionPart|CommissionLogistiquePart>}>
     */
    public static function groupesParCommission(PaiementPeriode $periode): array
    {
        $parts = self::partsPourPeriode($periode);

        $groupesVente = $parts['vente']->groupBy('commission_vente_id')
            ->map(function (Collection $groupe) {
                $commission = $groupe->first()->commission;

                return [
                    'type' => 'vente',
                    'commission_id' => $commission->id,
                    'reference' => $commission->commande->reference ?? $commission->id,
                    'vehicule_id' => $commission->vehicule_id,
                    'vehicule_nom' => $commission->vehicule?->nom_vehicule,
                    'vehicule_immat' => $commission->vehicule?->immatriculation,
                    'theorique' => round((float) $groupe->sum('montant_net'), 2),
                    'ajuste' => round((float) $groupe->sum('montant_a_payer'), 2),
                    'parts' => $groupe,
                ];
            });

        $groupesLogistique = $parts['logistique']->groupBy('commission_logistique_id')
            ->map(function (Collection $groupe) {
                $commission = $groupe->first()->commission;

                return [
                    'type' => 'logistique',
                    'commission_id' => $commission->id,
                    'reference' => $commission->transfert->reference ?? $commission->id,
                    'vehicule_id' => $commission->vehicule_id,
                    'vehicule_nom' => $commission->vehicule?->nom_vehicule,
                    'vehicule_immat' => $commission->vehicule?->immatriculation,
                    'theorique' => round((float) $groupe->sum('montant_net'), 2),
                    'ajuste' => round((float) $groupe->sum('montant_a_payer'), 2),
                    'parts' => $groupe,
                ];
            });

        return $groupesVente->concat($groupesLogistique)
            ->map(fn (array $g) => [...$g, 'ecart' => round($g['ajuste'] - $g['theorique'], 2)])
            ->values()
            ->all();
    }

    /**
     * Regroupe encore au-dessus par véhicule : c'est le niveau auquel le métier raisonne
     * ("je traite le véhicule X"), un véhicule pouvant avoir plusieurs commandes/transferts
     * sur la période. Les commissions sans véhicule rattaché sont regroupées à part.
     *
     * L'enveloppe théorique à respecter est celle du véhicule sur toute la période, pas
     * commande par commande : un membre absent sur une commande peut être compensé sur
     * une autre commande du même véhicule (le métier raisonne "je traite le véhicule X
     * pour la quinzaine", pas commande par commande).
     *
     * @return list<array{vehicule_id: ?string, vehicule_nom: string, vehicule_immat: ?string, nb_membres: int, nb_commandes: int, theorique: float, ajuste: float, ecart: float, equilibre: bool, statut_validation: string}>
     */
    public static function vehiculesParPeriode(PaiementPeriode $periode): array
    {
        return collect(self::groupesParCommission($periode))
            ->groupBy(fn (array $g) => $g['vehicule_id'] ?? '__sans_vehicule__')
            ->map(function (\Illuminate\Support\Collection $groupesDuVehicule) {
                $premier = $groupesDuVehicule->first();
                $parts = $groupesDuVehicule->flatMap(fn (array $g) => $g['parts']);
                $beneficiaires = $parts
                    ->map(fn (CommissionPart|CommissionLogistiquePart $p) => $p->livreur_id ?? $p->proprietaire_id)
                    ->filter()
                    ->unique();

                $theorique = round((float) $groupesDuVehicule->sum('theorique'), 2);
                $ajuste = round((float) $groupesDuVehicule->sum('ajuste'), 2);
                $ecart = round($ajuste - $theorique, 2);

                return [
                    'vehicule_id' => $premier['vehicule_id'],
                    'vehicule_nom' => $premier['vehicule_nom'] ?? 'Sans véhicule',
                    'vehicule_immat' => $premier['vehicule_immat'],
                    'nb_membres' => $beneficiaires->count(),
                    'nb_commandes' => $groupesDuVehicule->count(),
                    'theorique' => $theorique,
                    'ajuste' => $ajuste,
                    'ecart' => $ecart,
                    'equilibre' => abs($ecart) <= 0.01,
                    'statut_validation' => self::statutValidationPourParts($parts),
                ];
            })
            ->sortBy('vehicule_nom')
            ->values()
            ->all();
    }

    /**
     * Dérive le statut de contrôle d'un véhicule uniquement à partir de `validated_at`/`statut`
     * déjà présents sur ses commission_parts — aucun snapshot séparé à maintenir. Une nouvelle
     * commission arrive toujours non validée (cf. avecMontantsPayes côté controller) : dès
     * qu'elle rejoint des parts déjà validées du même véhicule, le mélange fait automatiquement
     * retomber le véhicule en "à_revérifier", sans action explicite à coder ailleurs.
     *
     * @param  \Illuminate\Support\Collection<int, CommissionPart|CommissionLogistiquePart>  $parts
     */
    private static function statutValidationPourParts(\Illuminate\Support\Collection $parts): string
    {
        if ($parts->isEmpty()) {
            return 'a_verifier';
        }

        $total = $parts->count();
        $payees = $parts->filter(fn (CommissionPart|CommissionLogistiquePart $p) => $p->isPaye())->count();
        $validees = $parts->filter(fn (CommissionPart|CommissionLogistiquePart $p) => ! $p->isPaye() && $p->estValidee())->count();
        $enAttente = $total - $payees - $validees;

        return match (true) {
            $payees === $total => 'payee',
            $enAttente === 0 => 'validee',
            $enAttente === $total => 'a_verifier',
            default => 'a_reverifier',
        };
    }

    /** Commandes/transferts d'un véhicule donné (ou "sans véhicule" si $vehiculeId est null). */
    public static function groupesParVehicule(PaiementPeriode $periode, ?string $vehiculeId): array
    {
        return collect(self::groupesParCommission($periode))
            ->filter(fn (array $g) => ($g['vehicule_id'] ?? null) === $vehiculeId)
            ->values()
            ->all();
    }

    /**
     * Regroupe les parts d'un véhicule par bénéficiaire sur l'ensemble de la période :
     * c'est l'unité de travail du métier ("qui a réellement travaillé sur ce véhicule
     * pendant la quinzaine"), pas la commande. Un même bénéficiaire peut avoir une part
     * sur plusieurs commandes/transferts du véhicule ; elles sont cumulées ici. Le détail
     * par commande (parts) reste disponible pour l'audit et l'ajustement fin.
     *
     * @return list<array{
     *   cle: string,
     *   type_beneficiaire: string,
     *   beneficiaire_nom: string,
     *   theorique: float,
     *   ajuste: float,
     *   ecart: float,
     *   est_validee: bool,
     *   peut_etre_ajustee: bool,
     *   parts: \Illuminate\Support\Collection<int, array{part: CommissionPart|CommissionLogistiquePart, type: string, reference: string}>
     * }>
     */
    public static function beneficiairesParVehicule(PaiementPeriode $periode, ?string $vehiculeId): array
    {
        $lignes = collect(self::groupesParVehicule($periode, $vehiculeId))
            ->flatMap(fn (array $g) => $g['parts']->map(fn ($part) => [
                'part' => $part,
                'type' => $g['type'],
                'reference' => $g['reference'],
            ]));

        return $lignes
            ->groupBy(fn (array $l) => $l['part']->type_beneficiaire.':'.($l['part']->livreur_id ?? $l['part']->proprietaire_id ?? $l['part']->beneficiaire_nom))
            ->map(function (\Illuminate\Support\Collection $lignesDuBeneficiaire, string $cle) {
                $premierPart = $lignesDuBeneficiaire->first()['part'];
                $theorique = round((float) $lignesDuBeneficiaire->sum(fn (array $l) => (float) $l['part']->montant_net), 2);
                $ajuste = round((float) $lignesDuBeneficiaire->sum(fn (array $l) => $l['part']->montant_a_payer), 2);

                return [
                    'cle' => $cle,
                    'type_beneficiaire' => $premierPart->type_beneficiaire,
                    'beneficiaire_nom' => $premierPart->beneficiaire_nom,
                    'theorique' => $theorique,
                    'ajuste' => $ajuste,
                    'ecart' => round($ajuste - $theorique, 2),
                    'est_validee' => $lignesDuBeneficiaire->every(fn (array $l) => $l['part']->estValidee()),
                    'peut_etre_ajustee' => $lignesDuBeneficiaire->contains(fn (array $l) => $l['part']->peutEtreAjustee()),
                    'parts' => $lignesDuBeneficiaire->values(),
                ];
            })
            ->sortBy('beneficiaire_nom')
            ->values()
            ->all();
    }

    /**
     * L'enveloppe théorique d'un véhicule sur la période ne doit jamais changer : ce qui
     * est retiré à un bénéficiaire doit être redistribué à un autre du même véhicule,
     * quelle que soit la commande (le métier traite "le véhicule X pour la quinzaine",
     * pas commande par commande — cf. vehiculesParPeriode()).
     *
     * @return array{theorique: float, ajuste: float, ecart: float, par_vehicule: list<array{vehicule_id: ?string, vehicule_nom: string, vehicule_immat: ?string, theorique: float, ajuste: float, ecart: float}>}
     */
    public static function resumeEcarts(PaiementPeriode $periode): array
    {
        $vehicules = collect(self::vehiculesParPeriode($periode));

        return [
            'theorique' => round((float) $vehicules->sum('theorique'), 2),
            'ajuste' => round((float) $vehicules->sum('ajuste'), 2),
            'ecart' => round((float) $vehicules->sum('ecart'), 2),
            'par_vehicule' => $vehicules
                ->reject(fn (array $v) => $v['equilibre'])
                ->map(fn (array $v) => collect($v)->only(['vehicule_id', 'vehicule_nom', 'vehicule_immat', 'theorique', 'ajuste', 'ecart'])->all())
                ->values()
                ->all(),
        ];
    }

    public static function ajusterMontant(
        CommissionPart|CommissionLogistiquePart $part,
        float $nouveauMontant,
        MotifAjustementCommission $motif,
        ?string $commentaire,
        User $user,
    ): void {
        if (! $part->peutEtreAjustee()) {
            throw new \LogicException('Cette commission est déjà entièrement versée, elle ne peut plus être ajustée.');
        }

        $ancienMontant = $part->montant_a_payer;
        $nouveauMontant = max(0.0, round($nouveauMontant, 2));

        DB::transaction(function () use ($part, $ancienMontant, $nouveauMontant, $motif, $commentaire, $user) {
            CommissionPartAdjustment::create([
                'commission_part_type' => $part::class,
                'commission_part_id' => $part->id,
                'ancien_montant' => $ancienMontant,
                'nouveau_montant' => $nouveauMontant,
                'motif' => $motif,
                'commentaire' => $commentaire,
                'created_by' => $user->id,
            ]);

            $part->montant_actuel = $nouveauMontant;
            // Un montant qui bouge après validation invalide cette validation : le contrôleur
            // avait approuvé un montant précis, pas "ce véhicule" en général (cf. vehiculesParPeriode
            // qui remonte alors ce véhicule en "à_revérifier").
            if ($nouveauMontant !== $ancienMontant) {
                $part->validated_at = null;
            }
            $part->save();
        });
    }

    /**
     * Ajuste le montant total d'un bénéficiaire sur un véhicule/période en une seule saisie :
     * le responsable métier ne raisonne jamais commande par commande, il corrige "ce que
     * Untel doit toucher sur la quinzaine". Le nouveau total est réparti au prorata du
     * montant théorique de chaque commande sous-jacente (parts déjà entièrement versées
     * exclues de la répartition, leur montant reste figé) ; cette répartition n'est jamais
     * exposée à l'utilisateur, elle sert uniquement à l'audit interne.
     *
     * @param  iterable<CommissionPart|CommissionLogistiquePart>  $parts
     *
     * @throws \LogicException si $nouveauTotal est inférieur à ce qui est déjà figé sur des parts non ajustables
     */
    public static function ajusterMontantGroupe(
        iterable $parts,
        float $nouveauTotal,
        MotifAjustementCommission $motif,
        ?string $commentaire,
        User $user,
    ): void {
        $parts = collect($parts);
        $nouveauTotal = max(0.0, round($nouveauTotal, 2));

        $ajustables = $parts->filter(fn (CommissionPart|CommissionLogistiquePart $p) => $p->peutEtreAjustee())->values();
        $montantFige = round((float) $parts->reject(fn (CommissionPart|CommissionLogistiquePart $p) => $p->peutEtreAjustee())->sum(fn (CommissionPart|CommissionLogistiquePart $p) => $p->montant_a_payer), 2);

        if ($nouveauTotal < $montantFige) {
            throw new \LogicException("Le montant ne peut pas être inférieur à {$montantFige} GNF déjà versé sur ce bénéficiaire.");
        }

        if ($ajustables->isEmpty()) {
            return;
        }

        $aRepartir = round($nouveauTotal - $montantFige, 2);
        $poidsTotal = round((float) $ajustables->sum(fn (CommissionPart|CommissionLogistiquePart $p) => (float) $p->montant_net), 2);
        $dernierIndex = $ajustables->count() - 1;
        $reparti = 0.0;

        DB::transaction(function () use ($ajustables, $aRepartir, $poidsTotal, $dernierIndex, $motif, $commentaire, $user, &$reparti) {
            foreach ($ajustables as $index => $part) {
                if ($index === $dernierIndex) {
                    $montant = round($aRepartir - $reparti, 2);
                } else {
                    $poids = $poidsTotal > 0 ? (float) $part->montant_net / $poidsTotal : 1 / $ajustables->count();
                    $montant = round($aRepartir * $poids, 2);
                }

                $montant = max(0.0, $montant);
                $reparti += $montant;

                self::ajusterMontant($part, $montant, $motif, $commentaire, $user);
            }
        });
    }

    public static function declarerAbsence(
        CommissionPart|CommissionLogistiquePart $part,
        ?string $commentaire,
        User $user,
    ): void {
        self::ajusterMontant($part, 0.0, MotifAjustementCommission::ABSENCE, $commentaire, $user);
    }

    /**
     * @param  array{type_beneficiaire: string, livreur_id: ?string, proprietaire_id: ?string, beneficiaire_nom: string, montant: float, commentaire: ?string}  $data
     */
    public static function ajouterRemplacantVente(CommissionVente $commission, array $data, User $user): CommissionPart
    {
        return DB::transaction(function () use ($commission, $data, $user) {
            $montant = max(0.0, round((float) $data['montant'], 2));

            // montant_net = 0 : un remplaçant n'a aucune allocation théorique — tout son
            // montant_actuel est donc de l'écart, à compenser par une baisse équivalente
            // ailleurs sur la même commission (cf. resumeEcarts()).
            $part = CommissionPart::create([
                'commission_vente_id' => $commission->id,
                'type_beneficiaire' => $data['type_beneficiaire'],
                'livreur_id' => $data['livreur_id'] ?? null,
                'proprietaire_id' => $data['proprietaire_id'] ?? null,
                'beneficiaire_nom' => $data['beneficiaire_nom'],
                'taux_commission' => 0,
                'montant_brut' => $montant,
                'montant_net' => 0,
                'montant_actuel' => $montant,
                'origine' => OrigineCommissionPart::REMPLACEMENT,
                'statut' => StatutCommission::IMPAYE,
            ]);

            self::logCreationRemplacant($part, $montant, $data['commentaire'] ?? null, $user);

            return $part;
        });
    }

    /**
     * @param  array{type_beneficiaire: string, livreur_id: ?string, proprietaire_id: ?string, beneficiaire_nom: string, montant: float, commentaire: ?string}  $data
     */
    public static function ajouterRemplacantLogistique(CommissionLogistique $commission, array $data, User $user): CommissionLogistiquePart
    {
        return DB::transaction(function () use ($commission, $data, $user) {
            $montant = max(0.0, round((float) $data['montant'], 2));

            $part = CommissionLogistiquePart::create([
                'commission_logistique_id' => $commission->id,
                'type_beneficiaire' => $data['type_beneficiaire'],
                'livreur_id' => $data['livreur_id'] ?? null,
                'proprietaire_id' => $data['proprietaire_id'] ?? null,
                'beneficiaire_nom' => $data['beneficiaire_nom'],
                'taux_commission' => 0,
                'montant_brut' => $montant,
                'montant_net' => 0,
                'montant_actuel' => $montant,
                'origine' => OrigineCommissionPart::REMPLACEMENT,
                'statut' => StatutCommission::IMPAYE,
                'earned_at' => $commission->transfert?->date_arrivee_reelle ?? now(),
            ]);

            self::logCreationRemplacant($part, $montant, $data['commentaire'] ?? null, $user);

            return $part;
        });
    }

    private static function logCreationRemplacant(CommissionPart|CommissionLogistiquePart $part, float $montant, ?string $commentaire, User $user): void
    {
        CommissionPartAdjustment::create([
            'commission_part_type' => $part::class,
            'commission_part_id' => $part->id,
            'ancien_montant' => 0,
            'nouveau_montant' => $montant,
            'motif' => MotifAjustementCommission::REMPLACEMENT,
            'commentaire' => $commentaire,
            'created_by' => $user->id,
        ]);
    }

    public static function validerPart(CommissionPart|CommissionLogistiquePart $part, User $user): void
    {
        if ($part->estValidee()) {
            return;
        }

        $part->validated_by = $user->id;
        $part->validated_at = now();
        $part->save();
    }

    /** @param  iterable<CommissionPart|CommissionLogistiquePart>  $parts */
    public static function validerLot(iterable $parts, User $user): int
    {
        $count = 0;

        DB::transaction(function () use ($parts, $user, &$count) {
            foreach ($parts as $part) {
                if (! $part->estValidee()) {
                    self::validerPart($part, $user);
                    $count++;
                }
            }
        });

        return $count;
    }
}
