<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_ventes', function (Blueprint $table) {
            // Nullable pour tous les clients autres que Grossiste (Externe/Revendeur/
            // Distributeur n'ont pas de mode de remise explicite, cf. docs/grossiste.md) —
            // jamais un défaut implicite, la cohérence est vérifiée à l'écriture
            // (CommandeVenteController::ensureModeRemiseGrossisteCoherent()).
            $table->string('mode_remise_grossiste', 20)->nullable()->after('nature_operation');
        });
    }

    public function down(): void
    {
        Schema::table('commandes_ventes', function (Blueprint $table) {
            $table->dropColumn('mode_remise_grossiste');
        });
    }
};
