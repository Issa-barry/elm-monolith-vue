<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Usage « Grossiste » (ADR 0023) : troisième usage du véhicule, indépendant de Vente et de
 * Logistique — il décide seul si le véhicule peut livrer un client grossiste (processus de
 * commission transfert_grossiste).
 *
 * Reprise décidée le 09/10/2026 : reçoivent l'usage les véhicules déjà autorisés pour la Vente ET la
 * Logistique (à la fois proposés pour une livraison grossiste et soumis au partage Transfert
 * grossiste), tout véhicule dont l'équipe a déjà un partage Transfert grossiste en vigueur, et tout
 * véhicule qui a déjà livré une commande grossiste (ex. tricycle Vente seule) — la reprise ne retire
 * jamais à un véhicule une livraison grossiste qu'il pratique déjà ni un partage déjà saisi. Valeur de départ
 * seulement : chaque fiche véhicule peut ensuite cocher ou décocher l'usage librement.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vehicules', 'livraison_grossiste')) {
            return;
        }

        Schema::table('vehicules', function (Blueprint $table) {
            $table->boolean('livraison_grossiste')->default(false)->after('livraison_logistique');
        });

        DB::table('vehicules')
            ->where('livraison_vente', true)
            ->where('livraison_logistique', true)
            ->update(['livraison_grossiste' => true]);

        $vehiculesAvecPartageGrossiste = DB::table('equipe_livraison_partages_categorie as pc')
            ->join('commission_processus as p', 'p.id', '=', 'pc.processus_id')
            ->join('equipes_livraison as e', 'e.id', '=', 'pc.equipe_id')
            ->where('p.code', 'transfert_grossiste')
            ->whereNull('pc.effective_to')
            ->whereNull('e.deleted_at')
            ->whereNotNull('e.vehicule_id')
            ->distinct()
            ->pluck('e.vehicule_id');

        $vehiculesAyantLivreUnGrossiste = DB::table('commandes_ventes')
            ->where('mode_remise_grossiste', 'livraison')
            ->whereNull('deleted_at')
            ->whereNotNull('vehicule_id')
            ->distinct()
            ->pluck('vehicule_id');

        foreach ($vehiculesAvecPartageGrossiste->merge($vehiculesAyantLivreUnGrossiste)->unique()->chunk(500) as $ids) {
            DB::table('vehicules')->whereIn('id', $ids->all())->update(['livraison_grossiste' => true]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('vehicules', 'livraison_grossiste')) {
            return;
        }

        Schema::table('vehicules', function (Blueprint $table) {
            $table->dropColumn('livraison_grossiste');
        });
    }
};
