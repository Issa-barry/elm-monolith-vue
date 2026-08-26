<?php

namespace App\Services;

use App\Enums\MotifAjustementCommission;
use App\Enums\OrigineCommissionPart;
use App\Enums\StatutCommission;
use App\Enums\StatutValidationEquipe;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionPartAdjustment;
use App\Models\EquipeLivraison;
use App\Models\PaiementFicheLigne;
use App\Models\PaiementPeriode;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ajustement et validation des commissions générées avant paiement — moteur
 * Commission (vente) unique, sur CommissionEnveloppePart. La logistique
 * (CommissionLogistiquePart) reste un mécanisme distinct, non unifié avec la
 * vente (jamais fusionnés dans les mêmes méthodes : formes de données trop
 * différentes — CommissionEnveloppePart n'a ni livreur_id/proprietaire_id, ni
 * vehicule_id direct, résolu via enveloppe->source), mais partage la même
 * infrastructure période/ajustement/paiement. Toutes les méthodes logistique
 * portent le suffixe `Logistique` pour ne jamais laisser croire qu'elles
 * couvrent aussi la vente.
 *
 * Ne modifie jamais la configuration théorique de l'équipe (equipes_livraison /
 * equipe_livreurs) : les corrections vivent uniquement sur les parts.
 */
class CommissionAdjustmentService
{
    // ═════════════════════════════════════════════════════════════════════════
    // ── Vente (CommissionEnveloppePart) ──────────────────────────────────────
    // ═════════════════════════════════════════════════════════════════════════

    /** @return Collection<int, CommissionEnveloppePart> */
    public static function partsPourPeriode(PaiementPeriode $periode): Collection
    {
        $ficheIds = $periode->fiches()->pluck('id');

        $partIds = PaiementFicheLigne::whereIn('fiche_id', $ficheIds)
            ->where('source_type', CommissionEnveloppePart::class)
            ->pluck('source_id')
            ->unique();

        return CommissionEnveloppePart::whereIn('id', $partIds)
            ->with(['enveloppe.source', 'validateur'])
            ->get();
    }

    /** Parts encore non validées et non payées/partiellement payées bloquant la validation de la période. */
    public static function partsNonValidees(PaiementPeriode $periode): Collection
    {
        return self::partsPourPeriode($periode)
            ->filter(fn (CommissionEnveloppePart $part) => ! $part->estValidee() && (float) $part->montant_verse <= 0.0)
            ->values();
    }

    /**
     * Bascule CREEE→IMPAYE (ou PAYE si montant nul) toutes les
     * CommissionEnveloppePart encore CREEE de cette période, ainsi que leur
     * CommissionEnveloppe parente — seul moment où une commission sort de
     * CREEE (cf. CommissionEnveloppeGenerator, qui la crée toujours en CREEE).
     * Appelée depuis PaiementPeriodeController::valider(), juste après le
     * passage CALCULEE→VALIDEE. Idempotente : sans effet sur une part déjà
     * sortie de CREEE.
     */
    public static function activerCommissionsCreees(PaiementPeriode $periode): int
    {
        $parts = self::partsPourPeriode($periode)
            ->filter(fn (CommissionEnveloppePart $part) => $part->statut === StatutCommission::CREEE);

        $count = 0;

        foreach ($parts->groupBy('enveloppe_id') as $groupe) {
            /** @var CommissionEnveloppe $enveloppe */
            $enveloppe = $groupe->first()->enveloppe;
            if (! $enveloppe || $enveloppe->statut !== StatutCommission::CREEE) {
                continue;
            }

            $enveloppe->update([
                'statut' => ((float) $enveloppe->montant_total > 0
                    ? StatutCommission::IMPAYE
                    : StatutCommission::PAYE)->value,
            ]);

            foreach ($groupe as $part) {
                $part->update([
                    'statut' => ((float) $part->montant_net > 0
                        ? StatutCommission::IMPAYE
                        : StatutCommission::PAYE)->value,
                ]);
            }

            $count++;
        }

        return $count;
    }

    /**
     * Regroupe par enveloppe (véhicule résolu via enveloppe->source, la
     * CommandeVente à l'origine du gain).
     *
     * @return list<array{type: string, commission_id: string, reference: string, vehicule_id: ?string, vehicule_nom: ?string, vehicule_immat: ?string, theorique: float, ajuste: float, ecart: float, parts: Collection<int, CommissionEnveloppePart>}>
     */
    public static function groupesParCommission(PaiementPeriode $periode): array
    {
        return self::partsPourPeriode($periode)
            ->groupBy('enveloppe_id')
            ->map(function (Collection $groupe) {
                /** @var CommissionEnveloppe $enveloppe */
                $enveloppe = $groupe->first()->enveloppe;
                $vehicule = $enveloppe?->source?->vehicule;

                return [
                    // 'vente' et non 'logistique' : le contrôleur d'ajustement route
                    // resolvePart() vers CommissionEnveloppePart.
                    'type' => 'vente',
                    'commission_id' => $enveloppe->id,
                    'reference' => $enveloppe->source?->reference ?? $enveloppe->id,
                    'vehicule_id' => $vehicule?->id,
                    'vehicule_nom' => $vehicule?->nom_vehicule,
                    'vehicule_immat' => $vehicule?->immatriculation,
                    'theorique' => round((float) $groupe->sum('montant_net'), 2),
                    'ajuste' => round((float) $groupe->sum(fn (CommissionEnveloppePart $p) => $p->montant_a_payer), 2),
                    'parts' => $groupe,
                ];
            })
            ->map(fn (array $g) => [...$g, 'ecart' => round($g['ajuste'] - $g['theorique'], 2)])
            ->values()
            ->all();
    }

    /**
     * @return list<array{vehicule_id: ?string, vehicule_nom: string, vehicule_immat: ?string, nb_membres: int, nb_commandes: int, theorique: float, ajuste: float, ecart: float, equilibre: bool, statut_validation: string}>
     */
    public static function vehiculesParPeriode(PaiementPeriode $periode): array
    {
        return collect(self::groupesParCommission($periode))
            ->groupBy(fn (array $g) => $g['vehicule_id'] ?? '__sans_vehicule__')
            ->map(function (\Illuminate\Support\Collection $groupesDuVehicule) {
                $premier = $groupesDuVehicule->first();
                $parts = $groupesDuVehicule->flatMap(fn (array $g) => $g['parts']);
                $beneficiaires = $parts->pluck('beneficiaire_id')->filter()->unique();

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
                    'statut_validation' => self::statutValidationPourParts($parts)->value,
                ];
            })
            ->sortBy('vehicule_nom')
            ->values()
            ->all();
    }

    /** @param  \Illuminate\Support\Collection<int, CommissionEnveloppePart>  $parts */
    public static function statutValidationPourParts(\Illuminate\Support\Collection $parts): StatutValidationEquipe
    {
        if ($parts->isEmpty()) {
            return StatutValidationEquipe::A_VERIFIER;
        }

        $total = $parts->count();
        $payees = $parts->filter(fn (CommissionEnveloppePart $p) => $p->isPaye())->count();
        $validees = $parts->filter(fn (CommissionEnveloppePart $p) => ! $p->isPaye() && $p->estValidee())->count();
        $enAttente = $total - $payees - $validees;

        return match (true) {
            $payees === $total => StatutValidationEquipe::PAYEE,
            $enAttente === 0 => StatutValidationEquipe::VALIDEE,
            $enAttente === $total => StatutValidationEquipe::A_VERIFIER,
            default => StatutValidationEquipe::A_REVERIFIER,
        };
    }

    /**
     * @return array<string, StatutValidationEquipe> indexé par "{type}:{id}"
     */
    public static function statutValidationParBeneficiaire(PaiementPeriode $periode): array
    {
        $groupesParVehicule = collect(self::groupesParCommission($periode))
            ->groupBy(fn (array $g) => $g['vehicule_id'] ?? '__sans_vehicule__');

        $result = [];

        foreach ($groupesParVehicule as $groupesDuVehicule) {
            $parts = $groupesDuVehicule->flatMap(fn (array $g) => $g['parts']);
            $statutVehicule = self::statutValidationPourParts($parts);

            foreach ($parts as $part) {
                $cle = "{$part->beneficiaire_type}:{$part->beneficiaire_id}";
                if (! isset($result[$cle]) || self::rangValidation($statutVehicule) < self::rangValidation($result[$cle])) {
                    $result[$cle] = $statutVehicule;
                }
            }
        }

        return $result;
    }

    /** Commandes d'un véhicule donné (ou "sans véhicule" si $vehiculeId est null). */
    public static function groupesParVehicule(PaiementPeriode $periode, ?string $vehiculeId): array
    {
        return collect(self::groupesParCommission($periode))
            ->filter(fn (array $g) => ($g['vehicule_id'] ?? null) === $vehiculeId)
            ->values()
            ->all();
    }

    /**
     * @return list<array{cle: string, type_beneficiaire: string, beneficiaire_nom: string, beneficiaire_telephone: ?string, theorique: float, ajuste: float, ecart: float, est_validee: bool, peut_etre_ajustee: bool, parts: Collection<int, array{part: CommissionEnveloppePart, type: string, reference: string}>}>
     */
    public static function beneficiairesParVehicule(PaiementPeriode $periode, ?string $vehiculeId): array
    {
        $lignes = collect(self::groupesParVehicule($periode, $vehiculeId))
            ->flatMap(fn (array $g) => $g['parts']->map(fn (CommissionEnveloppePart $part) => [
                'part' => $part,
                'type' => $g['type'],
                'reference' => $g['reference'],
            ]));

        $designationsParLivreur = self::designationsEquipeParVehicule($vehiculeId);

        return $lignes
            ->groupBy(fn (array $l) => $l['part']->beneficiaire_type.':'.$l['part']->beneficiaire_id)
            ->map(function (\Illuminate\Support\Collection $lignesDuBeneficiaire) use ($designationsParLivreur) {
                $premierPart = $lignesDuBeneficiaire->first()['part'];
                $beneficiaire = $premierPart->resoudreBeneficiaire();
                $theorique = round((float) $lignesDuBeneficiaire->sum(fn (array $l) => (float) $l['part']->montant_net), 2);
                $ajuste = round((float) $lignesDuBeneficiaire->sum(fn (array $l) => $l['part']->montant_a_payer), 2);

                return [
                    'cle' => $premierPart->beneficiaire_type.':'.$premierPart->beneficiaire_id,
                    'type_beneficiaire' => $premierPart->beneficiaire_type,
                    'beneficiaire_nom' => self::beneficiaireNomAffiche($premierPart, $beneficiaire, $designationsParLivreur),
                    'beneficiaire_telephone' => $beneficiaire?->telephone ?? null,
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
     * Libellé "Bénéficiaire" affiché sur l'écran d'ajustement. CommissionEnveloppePart
     * n'a pas de beneficiaire_nom propre : résolu depuis le bénéficiaire réel, jamais
     * le numéro de téléphone brut — quand le livreur n'a ni nom_complet ni prenom/nom,
     * on substitue la désignation d'équipe courante (Chauffeur-1, Convoyeur-2…).
     */
    private static function beneficiaireNomAffiche(CommissionEnveloppePart $part, ?Model $beneficiaire, array $designationsParLivreur): string
    {
        if ($beneficiaire === null) {
            return '—';
        }

        if ($part->beneficiaire_type !== CommissionEnveloppePart::TYPE_LIVREUR) {
            return $beneficiaire->nom_complet ?? trim(($beneficiaire->prenom ?? '').' '.($beneficiaire->nom ?? ''));
        }

        $aUnNomSaisi = $beneficiaire->nom_complet || trim("{$beneficiaire->prenom} {$beneficiaire->nom}") !== '';

        return $aUnNomSaisi
            ? ($beneficiaire->nom_complet ?? trim("{$beneficiaire->prenom} {$beneficiaire->nom}"))
            : ($designationsParLivreur[$part->beneficiaire_id] ?? '—');
    }

    /**
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
        CommissionEnveloppePart $part,
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
            // avait approuvé un montant précis, pas "ce véhicule" en général.
            if ($nouveauMontant !== $ancienMontant) {
                $part->validated_at = null;
            }
            $part->save();

            if ($nouveauMontant !== $ancienMontant) {
                self::invaliderPeriodes($part);
            }
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
     * @param  iterable<CommissionEnveloppePart>  $parts
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

        $ajustables = $parts->filter(fn (CommissionEnveloppePart $p) => $p->peutEtreAjustee())->values();
        $montantFige = round((float) $parts->reject(fn (CommissionEnveloppePart $p) => $p->peutEtreAjustee())->sum(fn (CommissionEnveloppePart $p) => $p->montant_a_payer), 2);

        if ($nouveauTotal < $montantFige) {
            throw new \LogicException("Le montant ne peut pas être inférieur à {$montantFige} GNF déjà versé sur ce bénéficiaire.");
        }

        if ($ajustables->isEmpty()) {
            return;
        }

        $aRepartir = round($nouveauTotal - $montantFige, 2);
        $poidsTotal = round((float) $ajustables->sum(fn (CommissionEnveloppePart $p) => (float) $p->montant_net), 2);
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
        CommissionEnveloppePart $part,
        ?string $commentaire,
        User $user,
    ): void {
        self::ajusterMontant($part, 0.0, MotifAjustementCommission::ABSENCE, $commentaire, $user);
    }

    /**
     * Le bénéficiaire doit obligatoirement référencer un Livreur/Proprietaire existant
     * (imposé par la validation du contrôleur, cf. CommissionAjustementController::
     * remplacant()) : contrairement à un modèle "part" avec beneficiaire_nom propre, le
     * nom affiché est toujours résolu depuis le bénéficiaire réel (resoudreBeneficiaire()).
     *
     * @param  array{type_beneficiaire: string, livreur_id: ?string, proprietaire_id: ?string, beneficiaire_nom: string, montant: float, commentaire: ?string}  $data
     */
    public static function ajouterRemplacantVente(CommissionEnveloppe $enveloppe, array $data, User $user): CommissionEnveloppePart
    {
        return DB::transaction(function () use ($enveloppe, $data, $user) {
            $montant = max(0.0, round((float) $data['montant'], 2));
            $beneficiaireId = $data['type_beneficiaire'] === CommissionEnveloppePart::TYPE_PROPRIETAIRE
                ? $data['proprietaire_id']
                : $data['livreur_id'];

            // montant_net = 0 : un remplaçant n'a aucune allocation théorique — tout son
            // montant_actuel est donc de l'écart, à compenser par une baisse équivalente
            // ailleurs sur la même enveloppe (cf. resumeEcarts()).
            $part = CommissionEnveloppePart::create([
                'enveloppe_id' => $enveloppe->id,
                'beneficiaire_type' => $data['type_beneficiaire'],
                'beneficiaire_id' => $beneficiaireId,
                'taux_repartition_snapshot' => null,
                'montant_brut' => $montant,
                'montant_net' => 0,
                'montant_actuel' => $montant,
                'origine' => OrigineCommissionPart::REMPLACEMENT,
                'statut' => StatutCommission::IMPAYE,
            ]);

            self::logCreationRemplacant($part, $montant, $data['commentaire'] ?? null, $user);
            self::invaliderPeriodes($part);

            return $part;
        });
    }

    /** Date résolue via enveloppe->earned_at. */
    private static function invaliderPeriodes(CommissionEnveloppePart $part): void
    {
        $part->loadMissing('enveloppe');
        $date = $part->enveloppe?->earned_at;
        $orgId = $part->enveloppe?->organization_id;

        if (! $date || ! $orgId) {
            return;
        }

        app(PeriodeCalculatorService::class)->recalculerPeriodesConcernees($orgId, Carbon::parse($date));
    }

    public static function validerPart(CommissionEnveloppePart $part, User $user): void
    {
        if ($part->estValidee()) {
            return;
        }

        $part->validated_by = $user->id;
        $part->validated_at = now();
        $part->save();
    }

    /** @param  iterable<CommissionEnveloppePart>  $parts */
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

    // ═════════════════════════════════════════════════════════════════════════
    // ── Logistique (CommissionLogistiquePart) — mécanisme distinct, non fusionné
    // avec la vente (formes de données différentes). Toutes les méthodes ci-dessous
    // portent le suffixe `Logistique` pour ne jamais laisser croire qu'elles couvrent
    // aussi la vente.
    // ═════════════════════════════════════════════════════════════════════════

    /** @return Collection<int, CommissionLogistiquePart> */
    public static function partsLogistiquePourPeriode(PaiementPeriode $periode): Collection
    {
        $ficheIds = $periode->fiches()->pluck('id');

        $partIds = PaiementFicheLigne::whereIn('fiche_id', $ficheIds)
            ->where('source_type', CommissionLogistiquePart::class)
            ->pluck('source_id')
            ->unique();

        return CommissionLogistiquePart::whereIn('id', $partIds)
            ->with(['livreur', 'proprietaire', 'validateur', 'commission.transfert'])
            ->get();
    }

    /** Parts encore non validées et non payées/partiellement payées bloquant la validation de la période. */
    public static function partsLogistiqueNonValidees(PaiementPeriode $periode): Collection
    {
        return self::partsLogistiquePourPeriode($periode)
            ->filter(fn (CommissionLogistiquePart $part) => ! $part->estValidee() && (float) $part->montant_verse <= 0.0)
            ->values();
    }

    /**
     * Bascule CREEE→IMPAYE (ou PAYE direct si montant nul) toutes les commissions
     * logistique encore CREEE rattachées à cette période — seul moment où une commission
     * sort de CREEE (cf. CommissionLogistiqueService, qui la crée toujours en CREEE).
     * Idempotente : sans effet sur une part déjà sortie de CREEE.
     */
    public static function activerCommissionsLogistiqueCreees(PaiementPeriode $periode): int
    {
        $creees = self::partsLogistiquePourPeriode($periode)
            ->filter(fn (CommissionLogistiquePart $part) => $part->statut === StatutCommission::CREEE);

        $count = 0;

        foreach ($creees->groupBy('commission_logistique_id') as $groupe) {
            $commission = $groupe->first()->commission;
            if (! $commission || $commission->statut !== StatutCommission::CREEE) {
                continue;
            }

            $commission->update([
                'statut' => ((float) $commission->montant_total > 0
                    ? StatutCommission::IMPAYE
                    : StatutCommission::PAYE)->value,
            ]);

            foreach ($groupe as $part) {
                $part->update([
                    'statut' => ((float) $part->montant_net > 0
                        ? StatutCommission::IMPAYE
                        : StatutCommission::PAYE)->value,
                ]);
            }

            $count++;
        }

        return $count;
    }

    /**
     * Regroupe les parts par commission logistique (= véhicule + transfert + équipe présente).
     *
     * @return list<array{type: string, commission_id: string, reference: string, vehicule_id: ?string, vehicule_nom: ?string, vehicule_immat: ?string, theorique: float, ajuste: float, ecart: float, parts: Collection<int, CommissionLogistiquePart>}>
     */
    public static function groupesLogistiqueParCommission(PaiementPeriode $periode): array
    {
        return self::partsLogistiquePourPeriode($periode)
            ->groupBy('commission_logistique_id')
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
            })
            ->map(fn (array $g) => [...$g, 'ecart' => round($g['ajuste'] - $g['theorique'], 2)])
            ->values()
            ->all();
    }

    /**
     * Regroupe par véhicule : c'est le niveau auquel le métier raisonne ("je traite le
     * véhicule X"), un véhicule pouvant avoir plusieurs transferts sur la période.
     *
     * @return list<array{vehicule_id: ?string, vehicule_nom: string, vehicule_immat: ?string, nb_membres: int, nb_commandes: int, theorique: float, ajuste: float, ecart: float, equilibre: bool, statut_validation: string}>
     */
    public static function vehiculesLogistiqueParPeriode(PaiementPeriode $periode): array
    {
        return collect(self::groupesLogistiqueParCommission($periode))
            ->groupBy(fn (array $g) => $g['vehicule_id'] ?? '__sans_vehicule__')
            ->map(function (\Illuminate\Support\Collection $groupesDuVehicule) {
                $premier = $groupesDuVehicule->first();
                $parts = $groupesDuVehicule->flatMap(fn (array $g) => $g['parts']);
                $beneficiaires = $parts
                    ->map(fn (CommissionLogistiquePart $p) => $p->livreur_id ?? $p->proprietaire_id)
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
                    'statut_validation' => self::statutValidationLogistiquePourParts($parts)->value,
                ];
            })
            ->sortBy('vehicule_nom')
            ->values()
            ->all();
    }

    /** @param  \Illuminate\Support\Collection<int, CommissionLogistiquePart>  $parts */
    public static function statutValidationLogistiquePourParts(\Illuminate\Support\Collection $parts): StatutValidationEquipe
    {
        if ($parts->isEmpty()) {
            return StatutValidationEquipe::A_VERIFIER;
        }

        $total = $parts->count();
        $payees = $parts->filter(fn (CommissionLogistiquePart $p) => $p->isPaye())->count();
        $validees = $parts->filter(fn (CommissionLogistiquePart $p) => ! $p->isPaye() && $p->estValidee())->count();
        $enAttente = $total - $payees - $validees;

        return match (true) {
            $payees === $total => StatutValidationEquipe::PAYEE,
            $enAttente === 0 => StatutValidationEquipe::VALIDEE,
            $enAttente === $total => StatutValidationEquipe::A_VERIFIER,
            default => StatutValidationEquipe::A_REVERIFIER,
        };
    }

    /**
     * Rang de "sévérité" d'un statut de validation équipe — plus bas = plus bloquant.
     * Sert à retenir le pire statut quand un bénéficiaire est réparti sur plusieurs véhicules.
     * Partagé vente/logistique : fonction pure sur l'enum, aucune ambiguïté possible.
     */
    private static function rangValidation(StatutValidationEquipe $statut): int
    {
        return match ($statut) {
            StatutValidationEquipe::A_VERIFIER => 0,
            StatutValidationEquipe::A_REVERIFIER => 1,
            StatutValidationEquipe::VALIDEE => 2,
            StatutValidationEquipe::PAYEE => 3,
        };
    }

    /**
     * @return array<string, StatutValidationEquipe> indexé par "{type}:{id}" (ex: "livreur:01...")
     */
    public static function statutValidationLogistiqueParBeneficiaire(PaiementPeriode $periode): array
    {
        $groupesParVehicule = collect(self::groupesLogistiqueParCommission($periode))
            ->groupBy(fn (array $g) => $g['vehicule_id'] ?? '__sans_vehicule__');

        $result = [];

        foreach ($groupesParVehicule as $groupesDuVehicule) {
            $parts = $groupesDuVehicule->flatMap(fn (array $g) => $g['parts']);
            $statutVehicule = self::statutValidationLogistiquePourParts($parts);

            foreach ($parts as $part) {
                $type = $part->livreur_id ? 'livreur' : ($part->proprietaire_id ? 'proprietaire' : null);
                $id = $part->livreur_id ?? $part->proprietaire_id;
                if ($type === null || $id === null) {
                    continue;
                }

                $cle = "{$type}:{$id}";
                if (! isset($result[$cle]) || self::rangValidation($statutVehicule) < self::rangValidation($result[$cle])) {
                    $result[$cle] = $statutVehicule;
                }
            }
        }

        return $result;
    }

    /** Transferts d'un véhicule donné (ou "sans véhicule" si $vehiculeId est null). */
    public static function groupesLogistiqueParVehicule(PaiementPeriode $periode, ?string $vehiculeId): array
    {
        return collect(self::groupesLogistiqueParCommission($periode))
            ->filter(fn (array $g) => ($g['vehicule_id'] ?? null) === $vehiculeId)
            ->values()
            ->all();
    }

    /**
     * Regroupe les parts d'un véhicule par bénéficiaire sur l'ensemble de la période.
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
     *   parts: Collection<int, array{part: CommissionLogistiquePart, type: string, reference: string}>
     * }>
     */
    public static function beneficiairesLogistiqueParVehicule(PaiementPeriode $periode, ?string $vehiculeId): array
    {
        $lignes = collect(self::groupesLogistiqueParVehicule($periode, $vehiculeId))
            ->flatMap(fn (array $g) => $g['parts']->map(fn ($part) => [
                'part' => $part,
                'type' => $g['type'],
                'reference' => $g['reference'],
            ]));

        $designationsParLivreur = self::designationsEquipeParVehicule($vehiculeId);

        return $lignes
            ->groupBy(fn (array $l) => $l['part']->type_beneficiaire.':'.($l['part']->livreur_id ?? $l['part']->proprietaire_id ?? $l['part']->beneficiaire_nom))
            ->map(function (\Illuminate\Support\Collection $lignesDuBeneficiaire) use ($designationsParLivreur) {
                $premierPart = $lignesDuBeneficiaire->first()['part'];
                $theorique = round((float) $lignesDuBeneficiaire->sum(fn (array $l) => (float) $l['part']->montant_net), 2);
                $ajuste = round((float) $lignesDuBeneficiaire->sum(fn (array $l) => $l['part']->montant_a_payer), 2);

                return [
                    'cle' => $premierPart->type_beneficiaire.':'.($premierPart->livreur_id ?? $premierPart->proprietaire_id ?? $premierPart->beneficiaire_nom),
                    'type_beneficiaire' => $premierPart->type_beneficiaire,
                    'beneficiaire_nom' => self::beneficiaireNomAfficheLogistique($premierPart, $designationsParLivreur),
                    'beneficiaire_telephone' => $premierPart->livreur?->telephone ?? $premierPart->proprietaire?->telephone,
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
     * Libellé "Bénéficiaire" affiché sur l'écran d'ajustement — jamais le
     * numéro de téléphone brut : quand le livreur n'a ni nom_complet ni
     * prenom/nom, Livreur::libelleAffichage() retombe sur le téléphone, donc
     * on substitue ici la désignation d'équipe courante (Chauffeur-1,
     * Convoyeur-2…), cohérente avec EquipeStepperModal::membreLabel(). Le
     * téléphone reste affiché séparément côté Vue (Ajustements/Vehicule.vue).
     *
     * @param  array<string, string>  $designationsParLivreur  indexé par livreur_id
     */
    private static function beneficiaireNomAfficheLogistique(CommissionLogistiquePart $part, array $designationsParLivreur): string
    {
        if ($part->type_beneficiaire !== 'livreur' || $part->livreur_id === null) {
            return $part->beneficiaire_nom;
        }

        $livreur = $part->livreur;
        $aUnNomSaisi = $livreur && ($livreur->nom_complet || trim("{$livreur->prenom} {$livreur->nom}") !== '');

        return $aUnNomSaisi ? $part->beneficiaire_nom : ($designationsParLivreur[$part->livreur_id] ?? $part->beneficiaire_nom);
    }

    /**
     * Désignations "Chauffeur-1"/"Convoyeur-2" de l'équipe courante d'un véhicule,
     * indexées par livreur_id — reflète la composition ACTUELLE de l'équipe (pas celle
     * au moment où la commission a été générée). Partagé vente/logistique : lit
     * uniquement equipes_livraison, sans ambiguïté possible entre les deux moteurs.
     *
     * @return array<string, string>
     */
    private static function designationsEquipeParVehicule(?string $vehiculeId): array
    {
        if ($vehiculeId === null) {
            return [];
        }

        $equipe = EquipeLivraison::where('vehicule_id', $vehiculeId)->first();
        if (! $equipe) {
            return [];
        }

        $roleCounts = [];
        $designations = [];
        foreach ($equipe->membres as $membre) {
            if ($membre->livreur_id === null) {
                continue;
            }
            $roleCounts[$membre->role] = ($roleCounts[$membre->role] ?? 0) + 1;
            $label = $membre->role === 'chauffeur' ? 'Chauffeur' : 'Convoyeur';
            $designations[$membre->livreur_id] = "{$label}-{$roleCounts[$membre->role]}";
        }

        return $designations;
    }

    /**
     * @return array{theorique: float, ajuste: float, ecart: float, par_vehicule: list<array{vehicule_id: ?string, vehicule_nom: string, vehicule_immat: ?string, theorique: float, ajuste: float, ecart: float}>}
     */
    public static function resumeEcartsLogistique(PaiementPeriode $periode): array
    {
        $vehicules = collect(self::vehiculesLogistiqueParPeriode($periode));

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

    public static function ajusterMontantLogistique(
        CommissionLogistiquePart $part,
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
            if ($nouveauMontant !== $ancienMontant) {
                $part->validated_at = null;
            }
            $part->save();

            if ($nouveauMontant !== $ancienMontant) {
                self::invaliderPeriodesLogistique($part);
            }
        });
    }

    /**
     * @param  iterable<CommissionLogistiquePart>  $parts
     *
     * @throws \LogicException si $nouveauTotal est inférieur à ce qui est déjà figé sur des parts non ajustables
     */
    public static function ajusterMontantGroupeLogistique(
        iterable $parts,
        float $nouveauTotal,
        MotifAjustementCommission $motif,
        ?string $commentaire,
        User $user,
    ): void {
        $parts = collect($parts);
        $nouveauTotal = max(0.0, round($nouveauTotal, 2));

        $ajustables = $parts->filter(fn (CommissionLogistiquePart $p) => $p->peutEtreAjustee())->values();
        $montantFige = round((float) $parts->reject(fn (CommissionLogistiquePart $p) => $p->peutEtreAjustee())->sum(fn (CommissionLogistiquePart $p) => $p->montant_a_payer), 2);

        if ($nouveauTotal < $montantFige) {
            throw new \LogicException("Le montant ne peut pas être inférieur à {$montantFige} GNF déjà versé sur ce bénéficiaire.");
        }

        if ($ajustables->isEmpty()) {
            return;
        }

        $aRepartir = round($nouveauTotal - $montantFige, 2);
        $poidsTotal = round((float) $ajustables->sum(fn (CommissionLogistiquePart $p) => (float) $p->montant_net), 2);
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

                self::ajusterMontantLogistique($part, $montant, $motif, $commentaire, $user);
            }
        });
    }

    public static function declarerAbsenceLogistique(
        CommissionLogistiquePart $part,
        ?string $commentaire,
        User $user,
    ): void {
        self::ajusterMontantLogistique($part, 0.0, MotifAjustementCommission::ABSENCE, $commentaire, $user);
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
            self::invaliderPeriodesLogistique($part);

            return $part;
        });
    }

    /**
     * Recalcule immédiatement la ou les périodes de l'org concernées par cette commission,
     * pour ne jamais laisser une période déjà "Calculée" afficher des montants obsolètes après
     * une création/un ajustement. Sans effet sur une période déjà validée/clôturée, ou si
     * aucune période n'existe encore.
     */
    private static function invaliderPeriodesLogistique(CommissionLogistiquePart $part): void
    {
        $date = $part->earned_at;
        $orgId = $part->commission?->organization_id;

        if (! $date || ! $orgId) {
            return;
        }

        app(PeriodeCalculatorService::class)->recalculerPeriodesConcernees($orgId, $date);
    }

    /** Partagé vente/logistique : crée l'entrée d'audit de création d'un remplaçant. */
    private static function logCreationRemplacant(CommissionLogistiquePart|CommissionEnveloppePart $part, float $montant, ?string $commentaire, User $user): void
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

    public static function validerPartLogistique(CommissionLogistiquePart $part, User $user): void
    {
        if ($part->estValidee()) {
            return;
        }

        $part->validated_by = $user->id;
        $part->validated_at = now();
        $part->save();
    }

    /** @param  iterable<CommissionLogistiquePart>  $parts */
    public static function validerLotLogistique(iterable $parts, User $user): int
    {
        $count = 0;

        DB::transaction(function () use ($parts, $user, &$count) {
            foreach ($parts as $part) {
                if (! $part->estValidee()) {
                    self::validerPartLogistique($part, $user);
                    $count++;
                }
            }
        });

        return $count;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // ── Combiné : vente + logistique (jamais un choix, toujours une union) ────
    //
    // Un même véhicule peut porter les deux natures de commission sur la même
    // période : ces vues d'ensemble unissent toujours les deux, jamais un choix
    // conditionnel — un véhicule purement logistique ne doit jamais devenir
    // invisible sur un écran de période/ajustement, et réciproquement.
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Miroir combiné de vehiculesParPeriode()/vehiculesLogistiqueParPeriode() : une seule
     * ligne par véhicule, montants sommés, pire statut de validation retenu.
     *
     * @return list<array{vehicule_id: ?string, vehicule_nom: string, vehicule_immat: ?string, nb_membres: int, nb_commandes: int, theorique: float, ajuste: float, ecart: float, equilibre: bool, statut_validation: string}>
     */
    public static function vehiculesParPeriodeCombine(PaiementPeriode $periode): array
    {
        $groupes = [
            ...self::groupesParCommission($periode),
            ...self::groupesLogistiqueParCommission($periode),
        ];

        return collect($groupes)
            ->groupBy(fn (array $g) => $g['vehicule_id'] ?? '__sans_vehicule__')
            ->map(function (\Illuminate\Support\Collection $groupesDuVehicule) {
                $premier = $groupesDuVehicule->first();
                $parts = $groupesDuVehicule->flatMap(fn (array $g) => $g['parts']);
                $beneficiaires = $parts
                    ->map(fn ($p) => $p instanceof CommissionEnveloppePart
                        ? $p->beneficiaire_id
                        : ($p->livreur_id ?? $p->proprietaire_id))
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
                    'statut_validation' => self::statutValidationMixte($parts)->value,
                ];
            })
            ->sortBy('vehicule_nom')
            ->values()
            ->all();
    }

    /**
     * Miroir combiné de statutValidationParBeneficiaire()/statutValidationLogistiqueParBeneficiaire() :
     * un bénéficiaire présent sur les deux natures de commission pour la même période retient le
     * pire des deux statuts (même logique de rang que vehiculesParPeriodeCombine(), au niveau
     * bénéficiaire plutôt que véhicule).
     *
     * @return array<string, StatutValidationEquipe> indexé par "{type}:{id}"
     */
    public static function statutValidationCombineParBeneficiaire(PaiementPeriode $periode): array
    {
        $groupes = [
            ...self::groupesParCommission($periode),
            ...self::groupesLogistiqueParCommission($periode),
        ];

        $groupesParVehicule = collect($groupes)->groupBy(fn (array $g) => $g['vehicule_id'] ?? '__sans_vehicule__');

        $result = [];

        foreach ($groupesParVehicule as $groupesDuVehicule) {
            $parts = $groupesDuVehicule->flatMap(fn (array $g) => $g['parts']);
            $statutVehicule = self::statutValidationMixte($parts);

            foreach ($parts as $part) {
                $cle = $part instanceof CommissionEnveloppePart
                    ? "{$part->beneficiaire_type}:{$part->beneficiaire_id}"
                    : ($part->livreur_id ? "livreur:{$part->livreur_id}" : ($part->proprietaire_id ? "proprietaire:{$part->proprietaire_id}" : null));

                if ($cle === null) {
                    continue;
                }

                if (! isset($result[$cle]) || self::rangValidation($statutVehicule) < self::rangValidation($result[$cle])) {
                    $result[$cle] = $statutVehicule;
                }
            }
        }

        return $result;
    }

    /**
     * isPaye()/estValidee() existent avec la même signature sur CommissionEnveloppePart ET
     * CommissionLogistiquePart — cette agrégation fonctionne donc indifféremment sur un
     * mélange des deux.
     *
     * @param  \Illuminate\Support\Collection<int, CommissionEnveloppePart|CommissionLogistiquePart>  $parts
     */
    private static function statutValidationMixte(\Illuminate\Support\Collection $parts): StatutValidationEquipe
    {
        if ($parts->isEmpty()) {
            return StatutValidationEquipe::A_VERIFIER;
        }

        $total = $parts->count();
        $payees = $parts->filter(fn ($p) => $p->isPaye())->count();
        $validees = $parts->filter(fn ($p) => ! $p->isPaye() && $p->estValidee())->count();
        $enAttente = $total - $payees - $validees;

        return match (true) {
            $payees === $total => StatutValidationEquipe::PAYEE,
            $enAttente === 0 => StatutValidationEquipe::VALIDEE,
            $enAttente === $total => StatutValidationEquipe::A_VERIFIER,
            default => StatutValidationEquipe::A_REVERIFIER,
        };
    }
}
