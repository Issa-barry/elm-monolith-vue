<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agence de rattachement d'une fiche (ADR 0061) : celle du véhicule (livreur, propriétaire,
 * parrain), le site lui-même (commission Site), celle du salarié. Elle ouvre la lecture aux
 * responsables de cette agence ; `site_id` reste l'agence qui PAIE.
 *
 * Reprise : jusqu'ici les deux agences étaient la même (ADR 0020), la valeur historique est donc
 * l'agence actuelle de la fiche. Aucune fiche ne change d'agence payeuse ici : la bascule passe
 * par `php artisan commissions:realigner-sites-fiches`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('paiement_fiches', 'site_rattachement_id')) {
            Schema::table('paiement_fiches', function (Blueprint $table) {
                $table->foreignUlid('site_rattachement_id')->nullable()->after('site_id')->constrained('sites')->nullOnDelete();
            });
        }

        DB::table('paiement_fiches')
            ->whereNull('site_rattachement_id')
            ->whereNotNull('site_id')
            ->update(['site_rattachement_id' => DB::raw('site_id')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('paiement_fiches', 'site_rattachement_id')) {
            Schema::table('paiement_fiches', function (Blueprint $table) {
                $table->dropConstrainedForeignId('site_rattachement_id');
            });
        }
    }
};
