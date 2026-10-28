<?php

namespace App\Services\Commission;

use App\Http\Controllers\Settings\CommissionRegleController;
use App\Models\Categorie;
use App\Models\CommissionProcessus;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Vehicule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Répercute sur le partage de l'équipe un parrain ajouté, remplacé ou retiré du véhicule (cf.
 * docs/parrainage-vehicule.md) — effet le jour même, jamais rétroactif. Les processus concernés sont
 * ceux qui rémunèrent le parrain (Vente, Transfert logistique, Transfert grossiste, Distribution client) :
 *
 * - ajout : une ligne parrain à 0 GNF dans chaque partage existant, et un partage Distribution
 *   propre (copie du partage Logistique + parrain à 0) si le véhicule a l'usage Logistique — aucun
 *   montant ne change, aucune opération n'est bloquée ;
 * - remplacement : le nouveau parrain démarre à 0 GNF (à définir) à partir d'aujourd'hui ;
 * - retrait : la ligne disparaît, le partage Distribution est clos (la distribution reprend le
 *   partage Logistique).
 *
 * Part POSITIVE de l'ancien parrain (PARRAIN-024, décision du 24/10/2026) : jamais transférée au
 * nouveau parrain. Un livreur seul la récupère (le partage reste complet) ; avec plusieurs
 * livreurs, leurs montants ne changent pas : le partage passe « À faire » jusqu'à la répartition
 * dans l'onglet Équipe. L'ancien parrain conserve toutes les commissions acquises jusque-là, payées
 * ou restant à payer — rien n'est réattribué.
 */
class PartageParrainService
{
    /**
     * À appeler dans la transaction qui vient d'enregistrer `vehicules.parrain_id`.
     *
     * @return list<string> partages laissés « À faire » (plusieurs livreurs), libellés
     */
    public static function appliquerChangementDeParrain(Vehicule $vehicule, ?string $ancienParrainId): array
    {
        $nouveauParrainId = $vehicule->parrain_id;
        if ($nouveauParrainId === $ancienParrainId) {
            return [];
        }

        $equipe = EquipeLivraison::where('vehicule_id', $vehicule->id)->first();
        if (! $equipe) {
            return [];
        }

        $aujourdhui = Carbon::today();
        $aRepartir = [];

        foreach ([CommissionProcessus::CODE_VENTE, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT, CommissionProcessus::CODE_TRANSFERT_GROSSISTE] as $code) {
            $processus = CommissionProcessus::where('organization_id', $vehicule->organization_id)->where('code', $code)->first();
            if ($processus) {
                self::reporterSurLePartage($equipe, $processus, $nouveauParrainId, $aujourdhui, $aRepartir);
            }
        }

        self::reporterSurLaDistribution($vehicule, $equipe, $nouveauParrainId, $aujourdhui, $aRepartir);

        ActivationEquipesBrouillonService::activerSiPartageConforme($vehicule->organization_id, [$equipe->id]);

        return array_values($aRepartir);
    }

    /**
     * Le parrain actuel reçoit une part positive : le changer ou le retirer modifie la répartition
     * de l'équipe — même droit que la modale Équipe (`equipes-livraison.update`, PARRAIN-016).
     */
    public static function aUnePartPositive(Vehicule $vehicule): bool
    {
        return $vehicule->parrain_id !== null && self::groupesARepartir($vehicule)->isNotEmpty();
    }

    /**
     * Partages dont la part du parrain actuel est positive, touchés par son remplacement ou son
     * retrait (PARRAIN-024) — avec ce qu'il faut pour en annoncer les conséquences (barème, membres
     * et montants actuels).
     * `au_retrait` : faux pour une distribution qui reprend le partage Logistique au retrait (son
     * partage propre est alors clos, rien à répartir).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function groupesARepartir(Vehicule $vehicule, ?Carbon $date = null): Collection
    {
        return self::groupes($vehicule, $date ?? Carbon::today(), positivesSeulement: true);
    }

    /**
     * Tous les partages où le parrain actuel figure (part nulle comprise), même format que
     * groupesARepartir() — saisie de la part du parrain dans l'onglet Parrain (PARRAIN-024).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function groupesDuParrain(Vehicule $vehicule, ?Carbon $date = null): Collection
    {
        if (! $vehicule->parrain_id) {
            return collect();
        }

        return self::groupes($vehicule, $date ?? Carbon::today(), positivesSeulement: false);
    }

    /**
     * Livreurs actifs de l'équipe : chacun doit figurer dans une répartition.
     *
     * @return Collection<int, string>
     */
    public static function membresRequis(EquipeLivraison $equipe): Collection
    {
        return $equipe->membres()->with('livreur')->get()
            ->filter(fn (EquipeLivreur $m) => $m->livreur && $m->livreur->is_active)
            ->pluck('livreur_id');
    }

    /**
     * Contrôle d'une répartition d'un partage (somme exacte du barème, livreurs actifs seulement,
     * part du parrain présente s'il y en a un, absente sinon) — commun au changement de parrain et
     * à la saisie de sa part.
     *
     * @param  array<string, mixed>  $groupe  élément de groupes()
     * @param  array<string, mixed>  $repartition  parts (livreur_id, montant_unitaire), part_parrain
     * @param  Collection<int, string>  $requis
     *
     * @throws ValidationException
     */
    public static function validerGroupe(array $groupe, array $repartition, ?string $parrainId, Collection $requis): void
    {
        $partParrain = $repartition['part_parrain'] ?? null;
        $libelle = $groupe['processus_label'].' · '.$groupe['categorie_nom'];

        if (($parrainId === null) !== ($partParrain === null)) {
            throw ValidationException::withMessages([
                'repartitions' => $parrainId === null
                    ? "{$libelle} : aucune part de parrain après son retrait."
                    : "{$libelle} : la part du parrain doit être renseignée (0 GNF accepté).",
            ]);
        }

        $membres = collect($repartition['parts'])->map(fn (array $p) => (object) [
            'beneficiaire_id' => $p['livreur_id'],
            'montant_unitaire' => $p['montant_unitaire'],
        ]);
        if ($membres->pluck('beneficiaire_id')->diff($requis)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'repartitions' => "{$libelle} : un livreur ne fait plus partie de l'équipe (rafraîchissez la page).",
            ]);
        }
        if ($partParrain !== null) {
            $membres->push((object) ['beneficiaire_id' => $parrainId, 'montant_unitaire' => $partParrain]);
        }

        try {
            CommissionPartageLivraisonValidator::valider(
                $membres,
                $groupe['bareme'],
                $parrainId === null ? $requis : $requis->concat([$parrainId]),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['repartitions' => "{$libelle} : {$e->getMessage()}"]);
        }
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function groupes(Vehicule $vehicule, Carbon $date, bool $positivesSeulement): Collection
    {
        $equipe = EquipeLivraison::where('vehicule_id', $vehicule->id)->first();
        if (! $equipe) {
            return collect();
        }

        $orgId = $vehicule->organization_id;
        $processus = CommissionProcessus::where('organization_id', $orgId)
            ->whereIn('code', CommissionProcessusDefaults::PROCESSUS_REMUNERANT_LE_PARRAIN)
            ->get()
            ->keyBy('id');

        $partsPositives = EquipeLivraisonPartageCategorie::where('equipe_id', $equipe->id)
            ->whereIn('processus_id', $processus->keys())
            ->when(
                $positivesSeulement,
                fn ($q) => $q->whereNotNull('parrain_id')->where('montant_unitaire', '>', 0),
                fn ($q) => $q->where('parrain_id', $vehicule->parrain_id),
            )
            ->actifA($date)
            ->get();

        if ($partsPositives->isEmpty()) {
            return collect();
        }

        $membres = $equipe->membres()->with('livreur')->orderBy('ordre')->get()
            ->filter(fn (EquipeLivreur $m) => $m->livreur && $m->livreur->is_active)
            ->values();
        $categories = Categorie::whereIn('id', $partsPositives->pluck('categorie_id'))->pluck('nom', 'id');

        return $partsPositives->map(function (EquipeLivraisonPartageCategorie $ligneParrain) use ($processus, $equipe, $membres, $categories, $vehicule, $date) {
            $proc = $processus->get($ligneParrain->processus_id);
            $bareme = CommissionProcessusDefaults::processusResolutionBareme($proc);
            $lignes = CommissionPartageLivraisonCategorieChecker::partagesActifs($proc->id, $equipe->id, $ligneParrain->categorie_id, $date);
            $montants = $lignes->reject(fn (EquipeLivraisonPartageCategorie $p) => $p->estParrain())->pluck('montant_unitaire', 'livreur_id');

            return [
                'processus_code' => $proc->code,
                'processus_label' => CommissionRegleController::processusLabel($proc->code),
                'categorie_id' => $ligneParrain->categorie_id,
                'categorie_nom' => $categories->get($ligneParrain->categorie_id, $ligneParrain->categorie_id),
                'bareme' => CommissionPartageLivraisonCategorieChecker::resoudreEnveloppe(
                    $vehicule->organization_id, $bareme->id, $ligneParrain->categorie_id, $vehicule->type_vehicule_id, $date,
                ),
                'part_parrain_actuelle' => (int) $ligneParrain->montant_unitaire,
                // PARRAIN-024 : date de début des droits en attente de la répartition de l'équipe.
                'droits_parrain_depuis' => $ligneParrain->droits_parrain_depuis?->toDateString(),
                'au_retrait' => $bareme->id === $proc->id,
                'membres' => $membres->map(fn (EquipeLivreur $m) => [
                    'livreur_id' => $m->livreur_id,
                    'nom_complet' => $m->livreur->nom_complet,
                    'role' => $m->role,
                    'montant_actuel' => (int) ($montants->get($m->livreur_id) ?? 0),
                ])->all(),
            ];
        })
            // Ordre métier des onglets Équipe, puis catégorie.
            ->sortBy(fn (array $g) => [array_search($g['processus_code'], CommissionProcessusDefaults::codesPartageEquipe(), true), $g['categorie_nom']])
            ->values();
    }

    /** @param  array<string, string>  $aRepartir  processus_code|categorie_id => libellé, complété ici */
    private static function reporterSurLePartage(EquipeLivraison $equipe, CommissionProcessus $processus, ?string $nouveauParrainId, Carbon $date, array &$aRepartir): void
    {
        foreach (self::versionsActives($equipe, $processus, $date) as $categorieId => $lignes) {
            $ligneParrain = $lignes->first(fn (EquipeLivraisonPartageCategorie $p) => $p->estParrain());
            if ($ligneParrain?->parrain_id === $nouveauParrainId) {
                continue;
            }

            $montants = self::montantsLivreurs($lignes);
            $partAncien = (int) ($ligneParrain?->montant_unitaire ?? 0);
            if ($partAncien > 0 && count($montants) === 1) {
                $montants[array_key_first($montants)] += $partAncien;
            } elseif ($partAncien > 0) {
                // Plusieurs livreurs : à refaire seulement si leur total ne tombe plus sur le barème
                // (la part de l'ancien pouvait déjà attendre la répartition de l'équipe).
                $vehicule = $equipe->vehicule;
                $bareme = CommissionPartageLivraisonCategorieChecker::resoudreEnveloppe(
                    $equipe->organization_id,
                    CommissionProcessusDefaults::processusResolutionBareme($processus)->id,
                    $categorieId,
                    $vehicule?->type_vehicule_id,
                    $date,
                );
                if (array_sum($montants) !== $bareme) {
                    $aRepartir[$processus->code.'|'.$categorieId] = CommissionRegleController::processusLabel($processus->code)
                        .' · '.(Categorie::whereKey($categorieId)->value('nom') ?? $categorieId);
                }
            }

            // Une date de début des droits en attente concernait l'ancien parrain : abandonnée.
            PartageLivraisonVersionService::versionner(
                $equipe->id,
                $processus->id,
                $categorieId,
                $montants,
                $date,
                $nouveauParrainId !== null ? 0 : null,
                droitsEnAttenteTraites: true,
            );
        }
    }

    /**
     * Distribution : partage propre seulement pour un véhicule parrainé (le parrain y est rémunéré,
     * jamais sur un transfert entre sites, dont la distribution garde pourtant le barème). Une
     * organisation qui aurait configuré un barème Distribution propre a déjà son propre partage :
     * traité comme Vente.
     *
     * @param  array<string, string>  $aRepartir
     */
    private static function reporterSurLaDistribution(Vehicule $vehicule, EquipeLivraison $equipe, ?string $nouveauParrainId, Carbon $date, array &$aRepartir): void
    {
        $orgId = $vehicule->organization_id;
        $distribution = CommissionProcessus::where('organization_id', $orgId)
            ->where('code', CommissionProcessus::CODE_DISTRIBUTION_CLIENT)
            ->first();

        if ($distribution && CommissionProcessusDefaults::processusResolutionBareme($distribution)->id === $distribution->id) {
            self::reporterSurLePartage($equipe, $distribution, $nouveauParrainId, $date, $aRepartir);

            return;
        }

        if ($nouveauParrainId === null) {
            if ($distribution) {
                EquipeLivraisonPartageCategorie::where('equipe_id', $equipe->id)
                    ->where('processus_id', $distribution->id)
                    ->whereNull('effective_to')
                    ->update(['effective_to' => $date->toDateString()]);
            }

            return;
        }

        if (! $vehicule->livraison_logistique) {
            return;
        }

        $distribution ??= CommissionProcessusDefaults::resoudreOuCreer($orgId, CommissionProcessus::CODE_DISTRIBUTION_CLIENT);

        if (self::versionsActives($equipe, $distribution, $date)->isNotEmpty()) {
            self::reporterSurLePartage($equipe, $distribution, $nouveauParrainId, $date, $aRepartir);

            return;
        }

        $logistique = CommissionProcessus::where('organization_id', $orgId)
            ->where('code', CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT)
            ->first();
        if (! $logistique) {
            return;
        }

        foreach (self::versionsActives($equipe, $logistique, $date) as $categorieId => $lignes) {
            PartageLivraisonVersionService::versionner(
                $equipe->id,
                $distribution->id,
                $categorieId,
                self::montantsLivreurs($lignes),
                $date,
                0,
            );
        }
    }

    /** @return Collection<string, Collection<int, EquipeLivraisonPartageCategorie>> categorie_id => lignes */
    private static function versionsActives(EquipeLivraison $equipe, CommissionProcessus $processus, Carbon $date): Collection
    {
        return EquipeLivraisonPartageCategorie::where('equipe_id', $equipe->id)
            ->where('processus_id', $processus->id)
            ->actifA($date)
            ->get()
            ->groupBy('categorie_id');
    }

    /**
     * @param  Collection<int, EquipeLivraisonPartageCategorie>  $lignes
     * @return array<string, int>
     */
    private static function montantsLivreurs(Collection $lignes): array
    {
        return $lignes
            ->reject(fn (EquipeLivraisonPartageCategorie $p) => $p->estParrain())
            ->mapWithKeys(fn (EquipeLivraisonPartageCategorie $p) => [$p->livreur_id => (int) $p->montant_unitaire])
            ->all();
    }
}
