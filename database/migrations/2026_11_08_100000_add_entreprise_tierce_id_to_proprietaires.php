<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel Contacts, lot 5 (ADR 0060) — un propriétaire de type entreprise est relié à sa fiche
 * Entreprise. Colonne facultative, aucune valeur imposée aux lignes existantes : le rattachement des
 * propriétaires déjà enregistrés passe par la commande contacts:reprendre-proprietaires-entreprises
 * (simulation, application journalisée, annulation). Un seul propriétaire par entreprise, garanti
 * par App\Services\Contacts\ProprietaireEntreprise sous verrou (les propriétaires supprimés en
 * douceur empêchent un index unique). `personne_id` reste le contact du propriétaire (D1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proprietaires', function (Blueprint $table) {
            $table->foreignUlid('entreprise_tierce_id')->nullable()->after('personne_id')
                ->constrained('entreprises_tierces')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('proprietaires', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entreprise_tierce_id');
        });
    }
};
