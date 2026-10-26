<?php

use App\Models\CommissionProcessus;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\Vehicule;
use App\Services\Commission\PartageLivraisonVersionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PARRAIN-025 (25/10/2026) : le parrain peut désormais recevoir une part sur les transferts
 * logistiques entre sites. Un véhicule parrainé doit donc porter la ligne de son parrain dans son
 * partage Logistique, sinon ses transferts seraient refusés (ligne du parrain exigée, PARRAIN-010).
 *
 * Reprise des véhicules déjà parrainés, décidée avec le métier : chaque partage Logistique en vigueur
 * reçoit une nouvelle version, à la date d'exécution de cette reprise (activation effective de la
 * règle), avec les montants des livreurs inchangés et la part du parrain à 0 GNF CONFIRMÉE — aucun
 * transfert n'est bloqué, la part se modifie ensuite dans l'onglet Parrain. Les autres processus,
 * les versions passées et les commissions déjà générées ne sont pas touchés. Rejouable : un partage
 * qui porte déjà la ligne de ce parrain est laissé tel quel.
 */
return new class extends Migration
{
    public function up(): void
    {
        $activation = Carbon::today();

        Vehicule::query()
            ->whereNotNull('parrain_id')
            ->whereHas('equipe')
            ->with('equipe')
            ->each(function (Vehicule $vehicule) use ($activation) {
                $logistique = CommissionProcessus::where('organization_id', $vehicule->organization_id)
                    ->where('code', CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT)
                    ->first();
                if (! $logistique) {
                    return;
                }

                $versions = EquipeLivraisonPartageCategorie::where('equipe_id', $vehicule->equipe->id)
                    ->where('processus_id', $logistique->id)
                    ->whereNull('effective_to')
                    ->get()
                    ->groupBy('categorie_id');

                foreach ($versions as $categorieId => $lignes) {
                    if ($lignes->contains(fn (EquipeLivraisonPartageCategorie $l) => $l->parrain_id === $vehicule->parrain_id)) {
                        continue;
                    }

                    $montants = $lignes
                        ->whereNotNull('livreur_id')
                        ->mapWithKeys(fn (EquipeLivraisonPartageCategorie $l) => [$l->livreur_id => (int) $l->montant_unitaire])
                        ->all();

                    DB::transaction(fn () => PartageLivraisonVersionService::versionner(
                        $vehicule->equipe->id,
                        $logistique->id,
                        $categorieId,
                        $montants,
                        $activation,
                        0,
                        confirmerPartParrainNulle: true,
                        droitsEnAttenteTraites: true,
                    ));
                }
            });
    }

    public function down(): void
    {
        // Rien à défaire : les versions créées restent l'historique des partages (part à 0 GNF).
    }
};
