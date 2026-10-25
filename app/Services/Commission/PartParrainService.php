<?php

namespace App\Services\Commission;

use App\Models\CommissionProcessus;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\Vehicule;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Part du parrain « à définir » ou « définie » au niveau du véhicule (PARRAIN-021), déduite des
 * partages (PARRAIN-022) : à définir dès qu'un partage où il est rémunéré porte une part à 0 GNF
 * jamais confirmée par une personne (`part_parrain_confirmee_at`) — une ligne créée par le système
 * n'est jamais une décision. Le refus des ventes est porté par le juge commun
 * (CommissionPartageLivraisonCategorieChecker::nonConformites()), partage par partage, à partir de
 * la date d'activation de l'organisation.
 *
 * La saisie de sa part dans l'onglet Parrain (PARRAIN-024, definirParts()) enregistre en une
 * transaction chaque partage où il figure, avec les contrôles du changement de parrain
 * (PartageParrainService::validerGroupe()) — jamais plus que le barème, jamais un second moteur.
 */
class PartParrainService
{
    public const A_DEFINIR = 'a_definir';

    public const DEFINIE = 'definie';

    /** Statut d'un véhicule, null s'il n'a pas de parrain. */
    public static function statut(Vehicule $vehicule): ?string
    {
        return self::statuts(collect([$vehicule]))[$vehicule->id] ?? null;
    }

    /**
     * Statuts de plusieurs véhicules en une requête (liste des véhicules).
     *
     * @param  Collection<int, Vehicule>  $vehicules
     * @return array<string, string> vehicule_id => statut, véhicules parrainés seulement
     */
    public static function statuts(Collection $vehicules): array
    {
        $parraines = $vehicules->filter(fn (Vehicule $v) => $v->parrain_id !== null);
        if ($parraines->isEmpty()) {
            return [];
        }

        $processusIds = CommissionProcessus::whereIn('organization_id', $parraines->pluck('organization_id')->unique())
            ->whereIn('code', CommissionProcessusDefaults::PROCESSUS_REMUNERANT_LE_PARRAIN)
            ->pluck('id');

        $aRepartir = EquipeLivraisonPartageCategorie::query()
            ->join('equipes_livraison', 'equipes_livraison.id', '=', 'equipe_livraison_partages_categorie.equipe_id')
            ->join('vehicules', 'vehicules.id', '=', 'equipes_livraison.vehicule_id')
            ->whereIn('vehicules.id', $parraines->pluck('id'))
            ->whereNull('equipes_livraison.deleted_at')
            ->whereColumn('equipe_livraison_partages_categorie.parrain_id', 'vehicules.parrain_id')
            ->whereIn('equipe_livraison_partages_categorie.processus_id', $processusIds)
            ->where('equipe_livraison_partages_categorie.montant_unitaire', 0)
            ->whereNull('equipe_livraison_partages_categorie.part_parrain_confirmee_at')
            ->actifA(Carbon::today())
            ->distinct()
            ->pluck('vehicules.id')
            ->flip();

        return $parraines->mapWithKeys(fn (Vehicule $v) => [
            $v->id => $aRepartir->has($v->id) ? self::A_DEFINIR : self::DEFINIE,
        ])->all();
    }

    /**
     * Saisie en place de l'onglet Parrain (PARRAIN-024) : seule la part du parrain est saisie, par
     * partage. Un seul livreur : il reçoit le reste du barème, le partage reste conforme. Plusieurs
     * livreurs : leurs montants ne sont jamais modifiés d'office — le partage est enregistré tel
     * quel, déséquilibré, donc « À faire » et les ventes refusées (juge commun) jusqu'à la
     * nouvelle répartition dans l'onglet Équipe. Une date de début passée y reste en attente : la
     * répartition faite dans la modale Équipe s'appliquera depuis cette date, avec le recalcul de
     * PARRAIN-023 — jamais de recalcul sur un partage déséquilibré. Une date future exige un
     * partage complet.
     *
     * @param  array<int, array{processus_code: string, categorie_id: string, part_parrain: int}>  $parts
     * @return array{bilan: array<string, mixed>|null, a_repartir: list<string>} partages à refaire dans Équipe (libellés)
     *
     * @throws ValidationException
     */
    public static function definirParts(Vehicule $vehicule, string $parrainIdAffiche, array $parts, bool $confirmerPartNulle, ?Carbon $dateEffet = null, bool $remplacerPlanification = false): array
    {
        $aujourdhui = Carbon::today();
        $date = ($dateEffet ?? $aujourdhui)->copy()->startOfDay();
        $groupes = PartageParrainService::groupesDuParrain($vehicule, $aujourdhui)
            ->keyBy(fn (array $g) => $g['processus_code'].'|'.$g['categorie_id']);

        $repartitions = [];
        $aRepartir = [];
        foreach ($parts as $saisie) {
            $cle = $saisie['processus_code'].'|'.$saisie['categorie_id'];
            $groupe = $groupes->get($cle);
            if ($groupe === null) {
                // Partage inconnu : definir() refuse la saisie (« rafraîchissez la page »).
                $repartitions[] = ['processus_code' => $saisie['processus_code'], 'categorie_id' => $saisie['categorie_id'], 'parts' => [], 'part_parrain' => (int) $saisie['part_parrain']];

                continue;
            }

            $libelle = $groupe['processus_label'].' · '.$groupe['categorie_nom'];
            $part = (int) $saisie['part_parrain'];
            if ($part < 0 || $part > $groupe['bareme']) {
                throw ValidationException::withMessages([
                    'parts' => sprintf('%s : la part ne peut pas dépasser le barème de %s GNF.', $libelle, number_format($groupe['bareme'], 0, ',', ' ')),
                ]);
            }

            $membres = collect($groupe['membres']);
            $montants = $membres->count() === 1
                ? [$membres->first()['livreur_id'] => $groupe['bareme'] - $part]
                : $membres->mapWithKeys(fn (array $m) => [$m['livreur_id'] => (int) $m['montant_actuel']])->all();

            $repartitions[] = [
                'processus_code' => $groupe['processus_code'],
                'categorie_id' => $groupe['categorie_id'],
                'parts' => collect($montants)->map(fn (int $m, string $id) => ['livreur_id' => $id, 'montant_unitaire' => $m])->values()->all(),
                'part_parrain' => $part,
            ];

            if (array_sum($montants) + $part !== $groupe['bareme']) {
                if ($date->gt($aujourdhui)) {
                    throw ValidationException::withMessages([
                        'date_effet' => "{$libelle} : plusieurs livreurs se partagent cette enveloppe, leur répartition est à refaire dans l'onglet Équipe. Une date de début future n'est possible qu'avec une répartition complète : enregistrez la part à la date du jour (ou à une date passée), puis répartissez l'équipe.",
                    ]);
                }
                $aRepartir[$cle] = $libelle;
            }
        }

        $bilan = self::definir($vehicule, $parrainIdAffiche, $repartitions, $confirmerPartNulle, $dateEffet, $remplacerPlanification, array_keys($aRepartir));

        return ['bilan' => $bilan, 'a_repartir' => array_values($aRepartir)];
    }

    /**
     * Enregistre la part du parrain sur tous ses partages (une répartition par partage, chacune
     * conforme), en une transaction. Un partage où sa part reste à 0 exige la confirmation
     * explicite (« aucune part pour le moment »), enregistrée sur sa ligne.
     *
     * Date de début des droits (PARRAIN-023) : aujourd'hui par défaut ; future = la répartition
     * prend effet à cette date ; passée = répartition antidatée et commission Livreur des commandes
     * gagnées depuis recalculée (RecalculRetroactifParrainService), au plus tôt le lendemain de la
     * dernière période clôturée.
     *
     * `$aRefaireDansEquipe` : partages (processus_code|categorie_id) enregistrés déséquilibrés par la
     * saisie en place (definirParts(), plusieurs livreurs) — seuls leurs livreurs sont contrôlés.
     *
     * @param  array<int, array<string, mixed>>  $repartitions
     * @param  list<string>  $aRefaireDansEquipe
     * @return array{recalculees: list<string>, exclues: list<array{reference: string, motif: string}>}|null bilan du recalcul rétroactif
     *
     * @throws ValidationException
     */
    public static function definir(Vehicule $vehicule, string $parrainIdAffiche, array $repartitions, bool $confirmerPartNulle, ?Carbon $dateEffet = null, bool $remplacerPlanification = false, array $aRefaireDansEquipe = []): ?array
    {
        $aujourdhui = Carbon::today();
        $dateEffet = ($dateEffet ?? $aujourdhui)->copy()->startOfDay();

        if ($dateEffet->lt($aujourdhui)) {
            self::verifierDateDebutDroits($vehicule, $dateEffet);
        }

        $planification = self::planification($vehicule);
        if ($planification !== null && ! $remplacerPlanification) {
            throw ValidationException::withMessages([
                'remplacer_planification' => sprintf(
                    'Une répartition est planifiée à partir du %s : confirmez son remplacement pour enregistrer celle-ci.',
                    Carbon::parse($planification)->format('d/m/Y'),
                ),
            ]);
        }

        return DB::transaction(function () use ($vehicule, $parrainIdAffiche, $repartitions, $confirmerPartNulle, $dateEffet, $aujourdhui, $planification, $aRefaireDansEquipe) {
            $vehicule = Vehicule::whereKey($vehicule->id)->lockForUpdate()->firstOrFail();
            $parrainId = $vehicule->parrain_id;

            if ($parrainId === null || $parrainId !== $parrainIdAffiche) {
                throw ValidationException::withMessages([
                    'repartitions' => 'Le parrain de ce véhicule a changé entre-temps : rafraîchissez la page.',
                ]);
            }

            $equipe = EquipeLivraison::where('vehicule_id', $vehicule->id)->first();
            $attendus = PartageParrainService::groupesDuParrain($vehicule, $aujourdhui)
                ->keyBy(fn (array $g) => $g['processus_code'].'|'.$g['categorie_id']);

            if (! $equipe || $attendus->isEmpty()) {
                throw ValidationException::withMessages([
                    'repartitions' => "Ce véhicule n'a aucun partage où répartir la part du parrain : configurez d'abord son équipe.",
                ]);
            }

            $parCle = collect($repartitions)->keyBy(fn (array $r) => $r['processus_code'].'|'.$r['categorie_id']);
            if ($attendus->keys()->sort()->values()->all() !== $parCle->keys()->sort()->values()->all()) {
                throw ValidationException::withMessages([
                    'repartitions' => 'Les partages du véhicule ont changé entre-temps : rafraîchissez la page.',
                ]);
            }

            $requis = PartageParrainService::membresRequis($equipe);
            foreach ($parCle as $cle => $repartition) {
                if (! in_array($cle, $aRefaireDansEquipe, true)) {
                    PartageParrainService::validerGroupe($attendus[$cle], $repartition, $parrainId, $requis);

                    continue;
                }

                $livreurs = collect($repartition['parts'])->pluck('livreur_id')->sort()->values()->all();
                if ($livreurs !== $requis->sort()->values()->all()) {
                    throw ValidationException::withMessages([
                        'parts' => "L'équipe du véhicule a changé entre-temps : rafraîchissez la page.",
                    ]);
                }
            }

            if (! $confirmerPartNulle && $parCle->contains(fn (array $r) => (int) $r['part_parrain'] === 0)) {
                throw ValidationException::withMessages([
                    'confirmer_part_nulle' => 'Confirmez que le parrain ne reçoit aucune part pour le moment sur les partages laissés à 0 GNF.',
                ]);
            }

            $processus = CommissionProcessus::where('organization_id', $vehicule->organization_id)
                ->whereIn('code', $parCle->pluck('processus_code')->unique())
                ->pluck('id', 'code');

            $antidate = false;
            foreach ($parCle as $cle => $repartition) {
                $processusId = $processus[$repartition['processus_code']];
                $montants = collect($repartition['parts'])->mapWithKeys(fn (array $p) => [$p['livreur_id'] => (int) $p['montant_unitaire']])->all();

                if (in_array($cle, $aRefaireDansEquipe, true)) {
                    // Partage « À faire » (PARRAIN-024) : enregistré aujourd'hui ; une date passée
                    // reste en attente de la répartition de l'équipe — aucun recalcul d'ici là.
                    $enAttente = $dateEffet->lt($aujourdhui) ? $dateEffet : null;
                    if ($planification === null
                        && self::inchange($attendus[$cle], $repartition)
                        && $attendus[$cle]['droits_parrain_depuis'] === $enAttente?->toDateString()) {
                        self::confirmerPartNulleSansNouvelleVersion($equipe->id, $processusId, $repartition['categorie_id'], $parrainId);

                        continue;
                    }

                    PartageLivraisonVersionService::versionner(
                        $equipe->id,
                        $processusId,
                        $repartition['categorie_id'],
                        $montants,
                        $aujourdhui,
                        (int) $repartition['part_parrain'],
                        confirmerPartParrainNulle: $confirmerPartNulle,
                        remplacerPlanification: true,
                        droitsParrainDepuis: $enAttente,
                        droitsEnAttenteTraites: true,
                    );

                    continue;
                }

                if ($planification === null && $dateEffet->equalTo($aujourdhui) && self::inchange($attendus[$cle], $repartition)) {
                    self::confirmerPartNulleSansNouvelleVersion($equipe->id, $processusId, $repartition['categorie_id'], $parrainId);

                    continue;
                }

                PartageLivraisonVersionService::versionner(
                    $equipe->id,
                    $processusId,
                    $repartition['categorie_id'],
                    $montants,
                    $dateEffet,
                    (int) $repartition['part_parrain'],
                    confirmerPartParrainNulle: $confirmerPartNulle,
                    remplacerPlanification: true,
                    droitsEnAttenteTraites: true,
                );
                $antidate = $antidate || $dateEffet->lt($aujourdhui);
            }

            // Recalcul rétroactif sur les seuls partages complets antidatés.
            return $antidate
                ? RecalculRetroactifParrainService::recalculer($vehicule, $dateEffet)
                : null;
        });
    }

    /** Rien ne change sur ce partage : seule la décision sur une part nulle est enregistrée. */
    private static function confirmerPartNulleSansNouvelleVersion(string $equipeId, string $processusId, string $categorieId, string $parrainId): void
    {
        EquipeLivraisonPartageCategorie::where('equipe_id', $equipeId)
            ->where('processus_id', $processusId)
            ->where('categorie_id', $categorieId)
            ->where('parrain_id', $parrainId)
            ->where('montant_unitaire', 0)
            ->whereNull('part_parrain_confirmee_at')
            ->actifA(Carbon::today())
            ->update(['part_parrain_confirmee_at' => now()]);
    }

    /**
     * Date de début des droits au plus tôt (null : aucune limite) : le lendemain de la dernière
     * période Livreurs ou Parrains clôturée (PARRAIN-023) et, si le véhicule a déjà eu un autre
     * parrain, le jour de son remplacement — les commissions acquises par l'ancien parrain, payées
     * ou non, ne lui sont jamais reprises (PARRAIN-024).
     */
    public static function dateMinimaleDroits(Vehicule $vehicule): ?Carbon
    {
        return collect([
            RecalculRetroactifParrainService::dateMinimale($vehicule->organization_id),
            self::finDesDroitsDuParrainPrecedent($vehicule),
        ])->filter()->max();
    }

    /** @throws ValidationException date de début des droits trop ancienne */
    public static function verifierDateDebutDroits(Vehicule $vehicule, CarbonInterface $date): void
    {
        $periodes = RecalculRetroactifParrainService::dateMinimale($vehicule->organization_id);
        if ($periodes !== null && $date->lt($periodes)) {
            throw ValidationException::withMessages([
                'date_effet' => sprintf(
                    'La date de début des droits ne peut pas précéder une période de paiement clôturée : au plus tôt le %s.',
                    $periodes->format('d/m/Y'),
                ),
            ]);
        }

        $remplacement = self::finDesDroitsDuParrainPrecedent($vehicule);
        if ($remplacement !== null && $date->lt($remplacement)) {
            throw ValidationException::withMessages([
                'date_effet' => sprintf(
                    'Le parrain actuel a remplacé le précédent le %s : ses droits ne peuvent pas commencer avant (les commissions de l\'ancien parrain lui restent acquises).',
                    $remplacement->format('d/m/Y'),
                ),
            ]);
        }
    }

    /** Jour où les droits d'un parrain précédent du véhicule ont pris fin (null : aucun). */
    private static function finDesDroitsDuParrainPrecedent(Vehicule $vehicule): ?Carbon
    {
        $equipe = EquipeLivraison::where('vehicule_id', $vehicule->id)->first();
        if (! $equipe) {
            return null;
        }

        $fin = EquipeLivraisonPartageCategorie::where('equipe_id', $equipe->id)
            ->whereNotNull('parrain_id')
            ->when($vehicule->parrain_id, fn ($q, string $id) => $q->where('parrain_id', '!=', $id))
            ->whereNotNull('effective_to')
            ->max('effective_to');

        return $fin ? Carbon::parse($fin)->startOfDay() : null;
    }

    /**
     * Date (AAAA-MM-JJ) de la répartition planifiée la plus proche sur les partages où le parrain
     * est rémunéré (PARRAIN-023, date de début future), null s'il n'y en a pas.
     */
    public static function planification(Vehicule $vehicule): ?string
    {
        $equipe = EquipeLivraison::where('vehicule_id', $vehicule->id)->first();
        if (! $equipe) {
            return null;
        }

        $processusIds = CommissionProcessus::where('organization_id', $vehicule->organization_id)
            ->whereIn('code', CommissionProcessusDefaults::PROCESSUS_REMUNERANT_LE_PARRAIN)
            ->pluck('id');

        $date = EquipeLivraisonPartageCategorie::where('equipe_id', $equipe->id)
            ->whereIn('processus_id', $processusIds)
            ->whereDate('effective_from', '>', Carbon::today()->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereColumn('effective_to', '>', 'effective_from'))
            ->min('effective_from');

        return $date ? Carbon::parse($date)->toDateString() : null;
    }

    /**
     * Répartition identique à la version active : pas de nouvelle version.
     *
     * @param  array<string, mixed>  $groupe
     * @param  array<string, mixed>  $repartition
     */
    private static function inchange(array $groupe, array $repartition): bool
    {
        if ((int) $repartition['part_parrain'] !== (int) $groupe['part_parrain_actuelle']) {
            return false;
        }

        $actuels = collect($groupe['membres'])->mapWithKeys(fn (array $m) => [$m['livreur_id'] => (int) $m['montant_actuel']])->sortKeys()->all();
        $nouveaux = collect($repartition['parts'])->mapWithKeys(fn (array $p) => [$p['livreur_id'] => (int) $p['montant_unitaire']])->sortKeys()->all();

        return $actuels === $nouveaux;
    }
}
