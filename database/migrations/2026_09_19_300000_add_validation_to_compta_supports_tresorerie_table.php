<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cycle de vie d'un support de trésorerie : un support est créé en BROUILLON, inutilisable, puis
 * VALIDÉ par un utilisateur habilité (`tresorerie.valider_supports`) — il devient alors ACTIF.
 *
 * Le statut n'est pas une colonne : il se déduit de `valide_le` (jamais validé = brouillon) et de
 * `actif` (en service ou désactivé). `actif` reste l'unique verrou d'usage lu partout (encaissements,
 * mouvements, versements, position) ; `valide_le` garantit seulement qu'un support n'a pas pu devenir
 * actif sans validation.
 *
 * Reprise de l'existant : tous les supports déjà en base sont réputés validés à leur date de
 * création (`valide_par_id` reste NULL = repris à la mise en place du workflow). Aucun support
 * existant ne change d'état ni ne perd son usage ; l'état actif/inactif est conservé tel quel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compta_supports_tresorerie', function (Blueprint $table) {
            $table->timestamp('valide_le')->nullable()->after('actif');
            $table->foreignUlid('valide_par_id')->nullable()->after('valide_le')->constrained('users')->nullOnDelete();
        });

        DB::table('compta_supports_tresorerie')
            ->whereNull('valide_le')
            ->update(['valide_le' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        Schema::table('compta_supports_tresorerie', function (Blueprint $table) {
            $table->dropConstrainedForeignId('valide_par_id');
            $table->dropColumn('valide_le');
        });
    }
};
