<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * L'activation de l'alerte de stock faible ("Souhaitez-vous être alerté ?"), jusqu'ici un choix
 * unique produits.alerte_stock_active appliqué uniformément à TOUS les sites, devient elle aussi
 * un réglage par COUPLE (produit, site) — décision produit du 01/09/2026, en remplacement (pas
 * une évolution compatible), même bascule que le seuil lui-même le 29/08/2026 (cf.
 * create_produit_seuils_alerte_table). produits.alerte_stock_active est conservée en base à titre
 * historique, plus jamais lue ni écrite par le code applicatif à partir de cette migration.
 *
 * Absence de ligne pour un site = alerte INACTIVE sur ce site — jamais actif implicitement : un
 * produit non géré sur un site (ex. non vendu dans cette agence) ne doit générer aucune alerte
 * tant qu'un administrateur ne l'a pas explicitement activée pour CE site (cf. docs/stock-alertes.md).
 * Ce nouveau défaut ("actif" par défaut à false) ne s'applique qu'aux configurations futures : la
 * bascule ci-dessous préserve le comportement effectif de CHAQUE produit déjà actif au moment du
 * changement, en activant explicitement tous les sites qu'il couvrait jusqu'ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produit_seuils_alerte', function (Blueprint $table) {
            $table->boolean('actif')->default(false)->after('site_id');
            $table->unsignedInteger('seuil_alerte_stock')->nullable()->change();
        });

        // Bascule non destructive : un produit dont l'alerte était globalement active reçoit une
        // ligne actif=true pour chacun des sites ACTIFS de son organisation (créée si absente,
        // sans jamais toucher un seuil spécifique déjà enregistré) — le comportement effectif de
        // chaque site reste identique au moment du basculement. Un produit dont l'alerte était
        // déjà désactivée ne reçoit aucune ligne : ses éventuelles lignes de seuil historiques
        // (créées avant sa désactivation, cf. migration du 29/08/2026) restent actif=false par
        // défaut, comme il se doit.
        $now = now();
        $produits = DB::table('produits')
            ->where('alerte_stock_active', true)
            ->whereNull('deleted_at')
            ->select('id', 'organization_id')
            ->get();

        foreach ($produits as $produit) {
            DB::table('produit_seuils_alerte')
                ->where('produit_id', $produit->id)
                ->update(['actif' => true]);

            $siteIdsAvecLigne = DB::table('produit_seuils_alerte')
                ->where('produit_id', $produit->id)
                ->pluck('site_id');

            $siteIds = DB::table('sites')
                ->where('organization_id', $produit->organization_id)
                ->where('statut', 'active')
                ->whereNull('deleted_at')
                ->pluck('id');

            foreach ($siteIds as $siteId) {
                if ($siteIdsAvecLigne->contains($siteId)) {
                    continue;
                }

                DB::table('produit_seuils_alerte')->insert([
                    'id' => (string) Str::ulid(),
                    'organization_id' => $produit->organization_id,
                    'produit_id' => $produit->id,
                    'site_id' => $siteId,
                    'seuil_alerte_stock' => null,
                    'actif' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('produit_seuils_alerte', function (Blueprint $table) {
            $table->dropColumn('actif');
            $table->unsignedInteger('seuil_alerte_stock')->nullable(false)->change();
        });
    }
};
