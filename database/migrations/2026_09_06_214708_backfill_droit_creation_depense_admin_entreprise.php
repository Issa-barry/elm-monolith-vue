<?php

use App\Models\DroitCreationDepense;
use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;

/**
 * Migration de DONNÉES (pas de schéma) — corollaire du retrait du bypass admin_entreprise dans
 * DroitCreationDepenseService (2026-09-06, cf. sa docblock de classe). Avant cette date,
 * `peutCreer()`/`peutCreerSurSite()`/`peutValider()`/`peutValiderSurSite()`/`sitesAutorises()`
 * bypassaient admin_entreprise sans jamais consulter cette table — sans ce backfill, TOUTE
 * organisation existante perdrait d'un coup, silencieusement, le droit pour son admin_entreprise
 * de créer ou valider des dépenses.
 *
 * Non destructive et idempotente :
 *  - une organisation qui a déjà une ligne `admin_entreprise` (formulaire /settings/depenses déjà
 *    visité) ne voit QUE `is_actif` complété à true — jamais son `peut_valider`/`perimetre`/
 *    `plafond_validation` déjà explicitement configurés, qui restent inchangés ;
 *  - une organisation sans ligne en reçoit une nouvelle avec des valeurs de continuité
 *    (is_actif=true, peut_valider=true, perimetre=toutes_agences) — plafond volontairement laissé
 *    NULL (deny-by-default sur le montant, cf. DEPVAL-004 dans docs/depenses-validation.md,
 *    déjà le comportement accepté pour toute organisation n'ayant jamais configuré ce plafond).
 */
return new class extends Migration
{
    public function up(): void
    {
        Organization::query()->select('id')->chunkById(200, function ($organizations) {
            foreach ($organizations as $org) {
                $droit = DroitCreationDepense::firstOrNew([
                    'organization_id' => $org->id,
                    'role_name' => 'admin_entreprise',
                ]);

                if (! $droit->exists) {
                    $droit->perimetre = 'toutes_agences';
                    $droit->peut_valider = true;
                }

                $droit->is_actif = true;
                $droit->save();
            }
        });
    }

    /**
     * Pas de rollback de données : redescendre reviendrait à réintroduire le bypass isAdmin()
     * dans le code (hors périmètre d'une migration), donc à rendre `is_actif` à nouveau sans
     * effet — remettre les lignes à `is_actif=false` ici serait trompeur sans ce rollback de
     * code. `down()` est donc volontairement un no-op.
     */
    public function down(): void
    {
        //
    }
};
