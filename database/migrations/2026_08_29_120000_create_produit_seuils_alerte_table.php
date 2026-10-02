<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Seuil d'alerte de stock faible, désormais configuré par COUPLE (produit, site) — remplace le
 * seuil unique produits.seuil_alerte_stock (conservé en base à titre historique, plus jamais lu
 * ni écrit par le code applicatif à partir de cette migration, cf. StockStatutService::
 * seuilEffectifPourSite()). Absence de ligne pour un site = repli sur le seuil global de
 * l'organisation (Parametre::getSeuilStockFaible()) — jamais 0 implicite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produit_seuils_alerte', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->unsignedInteger('seuil_alerte_stock');
            $table->timestamps();

            $table->unique(['produit_id', 'site_id']);
            $table->index(['organization_id', 'site_id']);
        });

        // Bascule non destructive : un produit qui avait déjà un seuil spécifique (jusqu'ici
        // appliqué uniformément à tous les sites, cf. ancienne colonne produits.seuil_alerte_stock)
        // reçoit une ligne équivalente pour chacun des sites ACTIFS de son organisation, afin que
        // le seuil effectivement vu par chaque site reste inchangé au moment du basculement vers
        // le paramétrage par site. Aucune ligne n'est créée pour un produit qui n'avait jamais
        // défini de seuil spécifique (repli déjà correct sur le seuil global de l'organisation) ni
        // pour les sites créés après cette migration (héritent nativement du seuil global).
        $now = now();
        $produits = DB::table('produits')
            ->whereNotNull('seuil_alerte_stock')
            ->whereNull('deleted_at')
            ->select('id', 'organization_id', 'seuil_alerte_stock')
            ->get();

        foreach ($produits as $produit) {
            $siteIds = DB::table('sites')
                ->where('organization_id', $produit->organization_id)
                ->where('statut', 'active')
                ->whereNull('deleted_at')
                ->pluck('id');

            foreach ($siteIds as $siteId) {
                DB::table('produit_seuils_alerte')->insert([
                    'id' => (string) Str::ulid(),
                    'organization_id' => $produit->organization_id,
                    'produit_id' => $produit->id,
                    'site_id' => $siteId,
                    'seuil_alerte_stock' => $produit->seuil_alerte_stock,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('produit_seuils_alerte');
    }
};
