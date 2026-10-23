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
 * ceux qui rémunèrent le parrain (Vente, Transfert grossiste, Distribution client) :
 *
 * - ajout : une ligne parrain à 0 GNF dans chaque partage existant, et un partage Distribution
 *   propre (copie du partage Logistique + parrain à 0) si le véhicule a l'usage Logistique — aucun
 *   montant ne change, aucune opération n'est bloquée ;
 * - remplacement : la ligne passe au nouveau parrain ;
 * - retrait : la ligne disparaît, le partage Distribution est clos (la distribution reprend le
 *   partage Logistique).
 *
 * Une part POSITIVE n'est jamais transférée ni retirée d'office (décision du 20/10/2026) : le
 * changement doit apporter la nouvelle répartition complète de chaque partage concerné
 * (groupesARepartir()), validée puis enregistrée dans la même transaction — refus sinon.
 *
 * Format d'une répartition : processus_code, categorie_id, parts (livreur_id, montant_unitaire),
 * part_parrain (part du NOUVEAU parrain ; absente pour un retrait).
 */
class PartageParrainService
{
    /**
     * À appeler dans la transaction qui vient d'enregistrer `vehicules.parrain_id`.
     *
     * @param  array<int, array<string, mixed>>  $repartitions
     *
     * @throws ValidationException si une part positive n'est pas répartie, ou si une répartition
     *                             fournie n'est pas conforme
     */
    public static function appliquerChangementDeParrain(Vehicule $vehicule, ?string $ancienParrainId, array $repartitions = []): void
    {
        $nouveauParrainId = $vehicule->parrain_id;
        if ($nouveauParrainId === $ancienParrainId) {
            return;
        }

        $equipe = EquipeLivraison::where('vehicule_id', $vehicule->id)->first();
        if (! $equipe) {
            return;
        }

        $aujourdhui = Carbon::today();
        $parCle = self::validerRepartitions($vehicule, $equipe, $nouveauParrainId, $repartitions, $aujourdhui);

        foreach ([CommissionProcessus::CODE_VENTE, CommissionProcessus::CODE_TRANSFERT_GROSSISTE] as $code) {
            $processus = CommissionProcessus::where('organization_id', $vehicule->organization_id)->where('code', $code)->first();
            if ($processus) {
                self::reporterSurLePartage($equipe, $processus, $nouveauParrainId, $aujourdhui, $parCle);
            }
        }

        self::reporterSurLaDistribution($vehicule, $equipe, $nouveauParrainId, $aujourdhui, $parCle);
    }

    /**
     * Partages dont la part du parrain actuel est positive, à refaire en entier pour le remplacer
     * ou le retirer — avec ce qu'il faut pour les afficher (barème, membres et montants actuels).
     * `au_retrait` : faux pour une distribution qui reprend le partage Logistique au retrait (son
     * partage propre est alors clos, rien à répartir).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function groupesARepartir(Vehicule $vehicule, ?Carbon $date = null): Collection
    {
        $equipe = EquipeLivraison::where('vehicule_id', $vehicule->id)->first();
        if (! $equipe) {
            return collect();
        }

        $date ??= Carbon::today();
        $orgId = $vehicule->organization_id;
        $processus = CommissionProcessus::where('organization_id', $orgId)
            ->whereIn('code', CommissionProcessusDefaults::PROCESSUS_REMUNERANT_LE_PARRAIN)
            ->get()
            ->keyBy('id');

        $partsPositives = EquipeLivraisonPartageCategorie::where('equipe_id', $equipe->id)
            ->whereIn('processus_id', $processus->keys())
            ->whereNotNull('parrain_id')
            ->where('montant_unitaire', '>', 0)
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
                'au_retrait' => $bareme->id === $proc->id,
                'membres' => $membres->map(fn (EquipeLivreur $m) => [
                    'livreur_id' => $m->livreur_id,
                    'nom_complet' => $m->livreur->nom_complet,
                    'role' => $m->role,
                    'montant_actuel' => (int) ($montants->get($m->livreur_id) ?? 0),
                ])->all(),
            ];
        })->values();
    }

    /**
     * Chaque partage à refaire doit être fourni, conforme (somme exacte, chaque livreur actif
     * présent, part du nouveau parrain renseignée — absente pour un retrait) ; aucun autre n'est
     * accepté.
     *
     * @param  array<int, array<string, mixed>>  $repartitions
     * @return Collection<string, array<string, mixed>> processus_code|categorie_id => répartition
     */
    private static function validerRepartitions(Vehicule $vehicule, EquipeLivraison $equipe, ?string $nouveauParrainId, array $repartitions, Carbon $date): Collection
    {
        $aRepartir = self::groupesARepartir($vehicule, $date)
            ->when($nouveauParrainId === null, fn (Collection $g) => $g->where('au_retrait', true))
            ->keyBy(fn (array $g) => $g['processus_code'].'|'.$g['categorie_id']);
        $parCle = collect($repartitions)->keyBy(fn (array $r) => $r['processus_code'].'|'.$r['categorie_id']);

        $manquants = $aRepartir->keys()->diff($parCle->keys());
        if ($manquants->isNotEmpty()) {
            throw ValidationException::withMessages([
                'repartitions' => sprintf(
                    'Le parrain actuel reçoit une part des commissions : refaites la répartition complète de %s avant de %s.',
                    $manquants->map(fn (string $cle) => $aRepartir[$cle]['processus_label'].' · '.$aRepartir[$cle]['categorie_nom'])->implode(', '),
                    $nouveauParrainId === null ? 'le retirer' : 'le remplacer',
                ),
            ]);
        }

        if ($parCle->keys()->diff($aRepartir->keys())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'repartitions' => 'Une répartition envoyée ne concerne pas ce changement de parrain (rafraîchissez la page).',
            ]);
        }

        $requis = $equipe->membres()->with('livreur')->get()
            ->filter(fn (EquipeLivreur $m) => $m->livreur && $m->livreur->is_active)
            ->pluck('livreur_id');

        foreach ($parCle as $cle => $repartition) {
            $groupe = $aRepartir[$cle];
            $partParrain = $repartition['part_parrain'] ?? null;
            $libelle = $groupe['processus_label'].' · '.$groupe['categorie_nom'];

            if (($nouveauParrainId === null) !== ($partParrain === null)) {
                throw ValidationException::withMessages([
                    'repartitions' => $nouveauParrainId === null
                        ? "{$libelle} : aucune part de parrain après son retrait."
                        : "{$libelle} : la part du nouveau parrain doit être renseignée (0 GNF accepté).",
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
                $membres->push((object) ['beneficiaire_id' => $nouveauParrainId, 'montant_unitaire' => $partParrain]);
            }

            try {
                CommissionPartageLivraisonValidator::valider(
                    $membres,
                    $groupe['bareme'],
                    $nouveauParrainId === null ? $requis : $requis->concat([$nouveauParrainId]),
                );
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['repartitions' => "{$libelle} : {$e->getMessage()}"]);
            }
        }

        return $parCle;
    }

    /** @param  Collection<string, array<string, mixed>>  $repartitions */
    private static function reporterSurLePartage(EquipeLivraison $equipe, CommissionProcessus $processus, ?string $nouveauParrainId, Carbon $date, Collection $repartitions): void
    {
        foreach (self::versionsActives($equipe, $processus, $date) as $categorieId => $lignes) {
            $repartition = $repartitions->get($processus->code.'|'.$categorieId);

            if ($repartition) {
                PartageLivraisonVersionService::versionner(
                    $equipe->id,
                    $processus->id,
                    $categorieId,
                    collect($repartition['parts'])->mapWithKeys(fn (array $p) => [$p['livreur_id'] => (int) $p['montant_unitaire']])->all(),
                    $date,
                    isset($repartition['part_parrain']) ? (int) $repartition['part_parrain'] : null,
                );

                continue;
            }

            $ligneParrain = $lignes->first(fn (EquipeLivraisonPartageCategorie $p) => $p->estParrain());
            if ($ligneParrain?->parrain_id === $nouveauParrainId) {
                continue;
            }

            PartageLivraisonVersionService::versionner(
                $equipe->id,
                $processus->id,
                $categorieId,
                self::montantsLivreurs($lignes),
                $date,
                $nouveauParrainId !== null ? 0 : null,
            );
        }
    }

    /**
     * Distribution : partage propre seulement pour un véhicule parrainé (le parrain y est rémunéré,
     * jamais sur un transfert entre sites, dont la distribution garde pourtant le barème). Une
     * organisation qui aurait configuré un barème Distribution propre a déjà son propre partage :
     * traité comme Vente.
     *
     * @param  Collection<string, array<string, mixed>>  $repartitions
     */
    private static function reporterSurLaDistribution(Vehicule $vehicule, EquipeLivraison $equipe, ?string $nouveauParrainId, Carbon $date, Collection $repartitions): void
    {
        $orgId = $vehicule->organization_id;
        $distribution = CommissionProcessus::where('organization_id', $orgId)
            ->where('code', CommissionProcessus::CODE_DISTRIBUTION_CLIENT)
            ->first();

        if ($distribution && CommissionProcessusDefaults::processusResolutionBareme($distribution)->id === $distribution->id) {
            self::reporterSurLePartage($equipe, $distribution, $nouveauParrainId, $date, $repartitions);

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
            self::reporterSurLePartage($equipe, $distribution, $nouveauParrainId, $date, $repartitions);

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
