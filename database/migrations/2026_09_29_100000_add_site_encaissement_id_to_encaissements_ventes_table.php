<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agence qui a réellement encaissé l'argent (ADR 0012) — distincte de l'agence de la commande
 * (`factures_ventes.site_id`) quand un client paie dans une autre agence.
 *
 * Reprise de l'historique : chaque encaissement existant reçoit le site de sa facture. C'est la
 * valeur exacte, pas une supposition : jusqu'ici toute pièce d'encaissement était posée sur le site
 * de la facture, et tous les moyens de paiement étaient ceux de cette agence. Non destructive
 * (colonne ajoutée, aucune donnée modifiée ailleurs) et rejouable (ne remplit que les vides).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('encaissements_ventes', 'site_encaissement_id')) {
            Schema::table('encaissements_ventes', function (Blueprint $table) {
                $table->foreignUlid('site_encaissement_id')
                    ->nullable()
                    ->after('facture_vente_id')
                    ->constrained('sites')
                    ->restrictOnDelete();
            });
        }

        DB::table('encaissements_ventes')
            ->whereNull('site_encaissement_id')
            ->update([
                'site_encaissement_id' => DB::raw('(SELECT factures_ventes.site_id FROM factures_ventes WHERE factures_ventes.id = encaissements_ventes.facture_vente_id)'),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('encaissements_ventes', 'site_encaissement_id')) {
            return;
        }

        Schema::table('encaissements_ventes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_encaissement_id');
        });
    }
};
