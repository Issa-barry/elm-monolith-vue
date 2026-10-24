<?php

use App\Models\Vehicule;
use App\Services\Commission\PartageParrainService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reprise des véhicules déjà parrainés (cf. docs/parrainage-vehicule.md) : depuis le 21/10/2026, un
 * véhicule parrainé doit avoir la ligne de son parrain dans chaque partage qui le rémunère, sinon
 * ses commandes seraient refusées (COMM-015). Chaque partage existant reçoit une ligne parrain à
 * 0 GNF et, si le véhicule a l'usage Logistique, un partage Distribution propre (copie du partage
 * Logistique + parrain à 0) — exactement ce que fait l'ajout d'un parrain aujourd'hui. Aucun montant
 * ne change. Rejouable : un partage qui porte déjà ce parrain est laissé tel quel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Vehicule::query()
            ->whereNotNull('parrain_id')
            ->whereHas('equipe')
            ->each(function (Vehicule $vehicule) {
                DB::transaction(fn () => PartageParrainService::appliquerChangementDeParrain($vehicule, null));
            });
    }

    public function down(): void
    {
        // Rien à défaire ici : les lignes parrain sont retirées par la migration de schéma.
    }
};
