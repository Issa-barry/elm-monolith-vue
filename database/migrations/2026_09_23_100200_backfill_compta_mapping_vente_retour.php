<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Migration de DONNÉES — provisionne, pour chaque organisation déjà comptabilisée, les mappings
 * du nouvel événement comptable `vente_retour` (régularisation de facture après un retour de
 * livraison, cf. VenteComptabilisationService::comptabiliserRetourVente()). L'événement réutilise
 * exactement les mêmes rôles et comptes que `vente_facturee` (client / produit_vente) : chaque
 * mapping existant est simplement copié, sans jamais écraser un mapping `vente_retour` déjà présent
 * (par exemple configuré à la main ou déjà créé par PlanComptableBootstrapService).
 *
 * Les organisations créées après ce déploiement reçoivent ces mappings directement via
 * PlanComptableBootstrapService::bootstrap().
 */
return new class extends Migration
{
    public function up(): void
    {
        $sources = DB::table('compta_mappings')->where('evenement', 'vente_facturee')->get();

        foreach ($sources as $source) {
            $existe = DB::table('compta_mappings')
                ->where('organization_id', $source->organization_id)
                ->where('evenement', 'vente_retour')
                ->where('role', $source->role)
                ->where('moyen_paiement', $source->moyen_paiement)
                ->exists();

            if ($existe) {
                continue;
            }

            DB::table('compta_mappings')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => $source->organization_id,
                'evenement' => 'vente_retour',
                'role' => $source->role,
                'moyen_paiement' => $source->moyen_paiement,
                'compte_comptable_id' => $source->compte_comptable_id,
                'journal_comptable_id' => $source->journal_comptable_id,
                'actif' => $source->actif,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Pas de rollback de données : les mappings `vente_retour` ont pu être ajustés à la main depuis.
     */
    public function down(): void
    {
        //
    }
};
