<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0017 — le type de site décrit ce qu'est le site, le site central de trésorerie est un rôle
 * explicite indépendant du type.
 *
 * 1. `is_siege_principal` devient `is_central_tresorerie` (renommage : valeurs conservées, le site
 *    qui centralisait les flux reste exactement le même).
 * 2. Le type `siege` disparaît : les sites concernés passent en `autre`, jamais vers un type
 *    deviné (agence, dépôt…). Leur vrai type est ensuite choisi site par site depuis l'écran Sites.
 * 3. Les filtres enregistrés « Commissions sites » sur le type `siege` suivent le même reclassement.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sites', 'is_siege_principal') && ! Schema::hasColumn('sites', 'is_central_tresorerie')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->renameColumn('is_siege_principal', 'is_central_tresorerie');
            });
        }

        DB::table('sites')->where('type', 'siege')->update(['type' => 'autre', 'updated_at' => now()]);

        DB::table('saved_filters')
            ->where('scope', 'commissions-sites')
            ->orderBy('id')
            ->get(['id', 'filters'])
            ->each(function (object $vue): void {
                $filters = json_decode($vue->filters, true) ?: [];
                if (($filters['site_type'] ?? null) === 'siege') {
                    $filters['site_type'] = 'autre';
                    DB::table('saved_filters')->where('id', $vue->id)->update(['filters' => json_encode($filters)]);
                }
            });
    }

    /** Seul le nom de colonne est restauré : l'ancien type `siege` n'est pas reconstituable. */
    public function down(): void
    {
        if (Schema::hasColumn('sites', 'is_central_tresorerie') && ! Schema::hasColumn('sites', 'is_siege_principal')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->renameColumn('is_central_tresorerie', 'is_siege_principal');
            });
        }
    }
};
