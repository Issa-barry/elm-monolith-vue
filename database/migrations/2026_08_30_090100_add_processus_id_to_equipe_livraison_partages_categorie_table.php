<?php

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionStrategieAncrageSite;
use App\Models\CommissionProcessus;
use App\Models\Parametre;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute la dimension processus au partage Livreur par catégorie, pour que la même équipe
     * puisse avoir des montants fixes différents en vente/distribution_client/logistique_transfert
     * sur une même catégorie (cf. CommissionEnveloppeGenerator, CommissionRegle déjà scopée par
     * processus_id). Backfill : toutes les lignes existantes n'ont jamais couvert que le processus
     * vente — on résout/crée ce processus par organisation avant de le leur assigner.
     */
    public function up(): void
    {
        Schema::table('equipe_livraison_partages_categorie', function (Blueprint $table) {
            $table->foreignUlid('processus_id')->nullable()->after('equipe_id')
                ->constrained('commission_processus')->cascadeOnDelete();
        });

        $organizationIds = DB::table('equipe_livraison_partages_categorie')
            ->join('equipes_livraison', 'equipes_livraison.id', '=', 'equipe_livraison_partages_categorie.equipe_id')
            ->distinct()
            ->pluck('equipes_livraison.organization_id');

        foreach ($organizationIds as $organizationId) {
            $processusVente = CommissionProcessus::firstOrCreate(
                ['organization_id' => $organizationId, 'code' => CommissionProcessus::CODE_VENTE],
                [
                    'libelle' => 'Vente',
                    'declencheur' => Parametre::getDeclencheurCommissionVente($organizationId)->value,
                    'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
                    'statut' => CommissionActivationStatut::ACTIF->value,
                ],
            );

            DB::table('equipe_livraison_partages_categorie')
                ->join('equipes_livraison', 'equipes_livraison.id', '=', 'equipe_livraison_partages_categorie.equipe_id')
                ->where('equipes_livraison.organization_id', $organizationId)
                ->update(['equipe_livraison_partages_categorie.processus_id' => $processusVente->id]);
        }

        // eq_liv_partage_categorie_equipe_idx : les 3 index composites ci-dessous vont perdre
        // equipe_id en tête (remplacé par processus_id) — sans cet index dédié, la contrainte
        // FK sur equipe_id (equipes_livraison, cf. migration de création) se retrouverait sans
        // AUCUN index la couvrant dès la suppression du dernier des 3 anciens index composites,
        // ce que MySQL/InnoDB refuse (contrairement à SQLite, qui laisse passer silencieusement
        // — d'où un échec visible seulement sur la base MySQL réelle des environnements E2E/
        // production, jamais dans la suite de tests Pest en SQLite : correctif du 30/08/2026,
        // après échec du job E2E). Conservé en permanence, jamais supprimé par la suite.
        Schema::table('equipe_livraison_partages_categorie', function (Blueprint $table) {
            $table->index('equipe_id', 'eq_liv_partage_categorie_equipe_idx');
        });

        Schema::table('equipe_livraison_partages_categorie', function (Blueprint $table) {
            $table->foreignUlid('processus_id')->nullable(false)->change();

            $table->dropIndex('eq_liv_partage_categorie_lookup_idx');
            $table->dropIndex('eq_liv_partage_categorie_version_idx');
            $table->dropIndex('eq_liv_partage_categorie_actif_idx');

            $table->index(['processus_id', 'equipe_id', 'categorie_id'], 'eq_liv_partage_categorie_lookup_idx');
            $table->index(['processus_id', 'equipe_id', 'categorie_id', 'livreur_id', 'effective_from'], 'eq_liv_partage_categorie_version_idx');
            $table->index(['processus_id', 'equipe_id', 'categorie_id', 'effective_to'], 'eq_liv_partage_categorie_actif_idx');
        });
    }

    public function down(): void
    {
        Schema::table('equipe_livraison_partages_categorie', function (Blueprint $table) {
            $table->dropIndex('eq_liv_partage_categorie_lookup_idx');
            $table->dropIndex('eq_liv_partage_categorie_version_idx');
            $table->dropIndex('eq_liv_partage_categorie_actif_idx');

            $table->index(['equipe_id', 'categorie_id'], 'eq_liv_partage_categorie_lookup_idx');
            $table->index(['equipe_id', 'categorie_id', 'livreur_id', 'effective_from'], 'eq_liv_partage_categorie_version_idx');
            $table->index(['equipe_id', 'categorie_id', 'effective_to'], 'eq_liv_partage_categorie_actif_idx');
        });

        // Retiré seulement maintenant que les 3 index ci-dessus couvrent de nouveau equipe_id
        // (même raisonnement FK qu'en up(), en sens inverse).
        Schema::table('equipe_livraison_partages_categorie', function (Blueprint $table) {
            $table->dropIndex('eq_liv_partage_categorie_equipe_idx');
            $table->dropConstrainedForeignId('processus_id');
        });
    }
};
