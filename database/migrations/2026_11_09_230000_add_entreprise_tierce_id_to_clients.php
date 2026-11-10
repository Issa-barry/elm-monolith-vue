<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel Contacts, lot 7b (ADR 0060, R2, L7-2, L7-3) — un client professionnel est relié à sa
 * fiche Entreprise ; `personne_id` reste son contact. Colonne facultative, aucune valeur imposée :
 * aucun client existant n'est rattaché automatiquement (L7-3). Un seul client par entreprise,
 * garanti par App\Services\Contacts\ProprietaireEntreprise sous verrou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignUlid('entreprise_tierce_id')->nullable()->after('personne_id')
                ->constrained('entreprises_tierces')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entreprise_tierce_id');
        });
    }
};
