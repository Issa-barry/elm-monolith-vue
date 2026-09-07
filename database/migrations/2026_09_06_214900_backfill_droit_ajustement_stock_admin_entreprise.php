<?php

use App\Models\DroitAjustementStock;
use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;

/**
 * Migration de DONNÉES (pas de schéma) — corollaire du retrait du bypass admin_entreprise dans
 * DroitAjustementStockService (2026-09-06, cf. sa docblock de classe). Même logique que
 * 2026_09_06_214708_backfill_droit_creation_depense_admin_entreprise : sans ce backfill, toute
 * organisation existante perdrait d'un coup, silencieusement, le droit pour son admin_entreprise
 * d'ajuster manuellement le stock (augmenter/diminuer).
 *
 * Non destructive et idempotente : une organisation qui a déjà une ligne `admin_entreprise`
 * (formulaire /settings/produits déjà visité) n'est pas modifiée — ses choix explicites sont
 * respectés tels quels ; une organisation sans ligne en reçoit une nouvelle avec des valeurs de
 * continuité (peut_augmenter=true, peut_diminuer=true, perimetre=toutes_agences).
 */
return new class extends Migration
{
    public function up(): void
    {
        Organization::query()->select('id')->chunkById(200, function ($organizations) {
            foreach ($organizations as $org) {
                DroitAjustementStock::firstOrCreate(
                    ['organization_id' => $org->id, 'role_name' => 'admin_entreprise'],
                    [
                        'perimetre' => 'toutes_agences',
                        'peut_augmenter' => true,
                        'peut_diminuer' => true,
                    ]
                );
            }
        });
    }

    /**
     * Pas de rollback de données — mêmes raisons que la migration jumelle sur
     * droit_creation_depenses (cf. sa docblock down()).
     */
    public function down(): void
    {
        //
    }
};
