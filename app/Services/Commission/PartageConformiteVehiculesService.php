<?php

namespace App\Services\Commission;

use App\Models\Categorie;
use App\Models\CommissionProcessus;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Vehicule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * État du partage Livreur de chaque véhicule, pour chaque processus configurable (Vente,
 * Transfert logistique, Transfert grossiste) et pour la Distribution d'un véhicule parrainé (son
 * partage propre, cf. docs/parrainage-vehicule.md) — colonne « Partages » de la liste des véhicules.
 * Même juge que la fiche véhicule et que le blocage des commandes (COMM-015) : pour chaque
 * catégorie dont le barème Livreur en vigueur (type du véhicule) est positif, somme exacte et
 * chaque membre actif présent. Calcul groupé (partages et barèmes chargés une fois) pour rester
 * rapide sur plusieurs centaines de véhicules.
 *
 * Statuts : `fait` (tout conforme), `a_faire` (au moins une catégorie non conforme — commandes
 * refusées sur cette catégorie), `non_requis` (aucun barème Livreur positif), `sans_equipe`
 * (processus exercé mais aucune équipe), `non_applicable` (usage du véhicule sans ce processus).
 */
class PartageConformiteVehiculesService
{
    public const FAIT = 'fait';

    public const A_FAIRE = 'a_faire';

    /** PARRAIN-022 : partage complet, mais part du parrain à 0 GNF jamais confirmée. */
    public const A_REPARTIR = 'a_repartir';

    public const NON_REQUIS = 'non_requis';

    public const SANS_EQUIPE = 'sans_equipe';

    public const NON_APPLICABLE = 'non_applicable';

    /**
     * Les véhicules doivent avoir `equipe.membres.livreur` chargés.
     *
     * @param  Collection<int, Vehicule>  $vehicules
     * @return array<string, array<string, string>> vehicule_id => [processus_code => statut]
     */
    public static function statuts(string $organizationId, Collection $vehicules): array
    {
        $codes = CommissionProcessusDefaults::codesPartageEquipe();
        $processus = CommissionProcessus::where('organization_id', $organizationId)
            ->whereIn('code', $codes)
            ->get()
            ->keyBy('code');
        // Barème de chaque processus : la distribution garde celui de Logistique (COMM-005).
        $processusBareme = $processus->map(fn (CommissionProcessus $p) => CommissionProcessusDefaults::processusResolutionBareme($p));
        $categorieIds = Categorie::where('organization_id', $organizationId)->where('statut', 'actif')->pluck('id');
        $aujourdhui = Carbon::today();

        $equipeIds = $vehicules->pluck('equipe.id')->filter()->values();
        $partages = $equipeIds->isEmpty() || $processus->isEmpty()
            ? collect()
            : EquipeLivraisonPartageCategorie::whereIn('equipe_id', $equipeIds)
                ->whereIn('processus_id', $processus->pluck('id'))
                ->actifA($aujourdhui)
                ->get()
                ->groupBy(fn (EquipeLivraisonPartageCategorie $p) => "{$p->equipe_id}|{$p->processus_id}|{$p->categorie_id}");

        $baremes = [];
        $resultat = [];

        foreach ($vehicules as $vehicule) {
            $applicables = CommissionProcessusDefaults::codesPartageEquipePourVehicule($vehicule);
            $equipe = $vehicule->equipe;
            $requisLivreurs = $equipe
                ? $equipe->membres
                    ->filter(fn (EquipeLivreur $m) => $m->livreur && $m->livreur->is_active)
                    ->pluck('livreur_id')
                    ->all()
                : [];

            foreach ($codes as $code) {
                // Distribution : état exposé seulement pour un véhicule qui tient ce partage.
                if ($code === CommissionProcessus::CODE_DISTRIBUTION_CLIENT && ! in_array($code, $applicables, true)) {
                    continue;
                }

                if (! in_array($code, $applicables, true)) {
                    $resultat[$vehicule->id][$code] = self::NON_APPLICABLE;

                    continue;
                }

                $proc = $processus->get($code);
                $procBareme = $processusBareme->get($code);
                $statut = self::NON_REQUIS;
                $aRepartir = false;
                // Même juge que la commande : la ligne du parrain est exigée là où il est rémunéré.
                $parrainId = CommissionProcessusDefaults::remunereLeParrain($code) ? $vehicule->parrain_id : null;
                $requis = $parrainId ? [...$requisLivreurs, $parrainId] : $requisLivreurs;

                foreach ($proc && $procBareme ? $categorieIds : [] as $categorieId) {
                    $cle = "{$procBareme->id}|{$categorieId}|{$vehicule->type_vehicule_id}";
                    $baremes[$cle] ??= CommissionPartageLivraisonCategorieChecker::resoudreEnveloppe(
                        $organizationId, $procBareme->id, $categorieId, $vehicule->type_vehicule_id, $aujourdhui,
                    );
                    if ($baremes[$cle] <= 0) {
                        continue;
                    }

                    if (! $equipe) {
                        $statut = self::SANS_EQUIPE;
                        break;
                    }

                    $lignes = $partages->get("{$equipe->id}|{$proc->id}|{$categorieId}", collect());

                    try {
                        CommissionPartageLivraisonCategorieChecker::verifierParrainAJour($lignes, $parrainId);
                        CommissionPartageLivraisonValidator::valider($lignes, $baremes[$cle], $requis);
                        $statut = self::FAIT;
                        $aRepartir = $aRepartir || ($parrainId && CommissionPartageLivraisonCategorieChecker::partParrainARepartir($lignes));
                    } catch (InvalidArgumentException) {
                        $statut = self::A_FAIRE;
                        break;
                    }
                }

                $resultat[$vehicule->id][$code] = $statut === self::FAIT && $aRepartir ? self::A_REPARTIR : $statut;
            }
        }

        return $resultat;
    }
}
