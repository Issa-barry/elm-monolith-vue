<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiement_periodes', function (Blueprint $table) {
            // Empreinte des données source (commissions/dépenses/paie) au moment du dernier
            // calcul : permet de détecter si la période doit être recalculée sans avoir à
            // relancer le calcul complet à chaque ouverture de page.
            $table->string('calcul_hash', 32)->nullable()->after('statut');
            $table->timestamp('calculated_at')->nullable()->after('calcul_hash');
        });
    }

    public function down(): void
    {
        Schema::table('paiement_periodes', function (Blueprint $table) {
            $table->dropColumn(['calcul_hash', 'calculated_at']);
        });
    }
};
