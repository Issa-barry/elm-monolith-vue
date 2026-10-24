<?php

namespace App\Services\Commission;

use App\Models\CommissionProcessus;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\Vehicule;
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
 * La grille « Définir la part du parrain » enregistre en une transaction la nouvelle répartition de
 * chaque partage où il figure, avec les contrôles du changement de parrain
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
     * Enregistre la part du parrain sur tous ses partages (une répartition par partage, chacune
     * conforme), en une transaction. Un partage où sa part reste à 0 exige la confirmation
     * explicite (« aucune part pour le moment »), enregistrée sur sa ligne.
     *
     * Date de début des droits (PARRAIN-023) : aujourd'hui par défaut ; future = la répartition
     * prend effet à cette date ; passée = répartition antidatée et commission Livreur des commandes
     * gagnées depuis recalculée (RecalculRetroactifParrainService), au plus tôt le lendemain de la
     * dernière période clôturée.
     *
     * @param  array<int, array<string, mixed>>  $repartitions
     * @return array{recalculees: list<string>, exclues: list<array{reference: string, motif: string}>}|null bilan du recalcul rétroactif
     *
     * @throws ValidationException
     */
    public static function definir(Vehicule $vehicule, string $parrainIdAffiche, array $repartitions, bool $confirmerPartNulle, ?Carbon $dateEffet = null, bool $remplacerPlanification = false): ?array
    {
        $aujourdhui = Carbon::today();
        $dateEffet = ($dateEffet ?? $aujourdhui)->copy()->startOfDay();

        if ($dateEffet->lt($aujourdhui)) {
            $minimum = RecalculRetroactifParrainService::dateMinimale($vehicule->organization_id);
            if ($minimum !== null && $dateEffet->lt($minimum)) {
                throw ValidationException::withMessages([
                    'date_effet' => sprintf(
                        'La date de début des droits ne peut pas précéder une période de paiement clôturée : au plus tôt le %s.',
                        $minimum->format('d/m/Y'),
                    ),
                ]);
            }
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

        return DB::transaction(function () use ($vehicule, $parrainIdAffiche, $repartitions, $confirmerPartNulle, $dateEffet, $aujourdhui, $planification) {
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
                PartageParrainService::validerGroupe($attendus[$cle], $repartition, $parrainId, $requis);
            }

            if (! $confirmerPartNulle && $parCle->contains(fn (array $r) => (int) $r['part_parrain'] === 0)) {
                throw ValidationException::withMessages([
                    'confirmer_part_nulle' => 'Confirmez que le parrain ne reçoit aucune part pour le moment sur les partages laissés à 0 GNF.',
                ]);
            }

            $processus = CommissionProcessus::where('organization_id', $vehicule->organization_id)
                ->whereIn('code', $parCle->pluck('processus_code')->unique())
                ->pluck('id', 'code');

            foreach ($parCle as $cle => $repartition) {
                $processusId = $processus[$repartition['processus_code']];

                if ($planification === null && $dateEffet->equalTo($aujourdhui) && self::inchange($attendus[$cle], $repartition)) {
                    // Rien ne change : seule la décision sur une part nulle est enregistrée.
                    EquipeLivraisonPartageCategorie::where('equipe_id', $equipe->id)
                        ->where('processus_id', $processusId)
                        ->where('categorie_id', $repartition['categorie_id'])
                        ->where('parrain_id', $parrainId)
                        ->where('montant_unitaire', 0)
                        ->whereNull('part_parrain_confirmee_at')
                        ->actifA($aujourdhui)
                        ->update(['part_parrain_confirmee_at' => now()]);

                    continue;
                }

                PartageLivraisonVersionService::versionner(
                    $equipe->id,
                    $processusId,
                    $repartition['categorie_id'],
                    collect($repartition['parts'])->mapWithKeys(fn (array $p) => [$p['livreur_id'] => (int) $p['montant_unitaire']])->all(),
                    $dateEffet,
                    (int) $repartition['part_parrain'],
                    confirmerPartParrainNulle: $confirmerPartNulle,
                    remplacerPlanification: true,
                );
            }

            return $dateEffet->lt($aujourdhui)
                ? RecalculRetroactifParrainService::recalculer($vehicule, $dateEffet)
                : null;
        });
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
