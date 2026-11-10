<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel Contacts, lot 1 (ADR 0060, docs/contacts.md) — coordonnées complémentaires des deux
 * fiches d'identité. Additive : colonnes nullables, aucune ligne existante modifiée. Le téléphone
 * secondaire n'est jamais unique (numéro partagé, décision 6) ; l'unicité reste portée par
 * `telephone_normalise`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnes', function (Blueprint $table) {
            $table->string('telephone_secondaire', 30)->nullable()->after('telephone_normalise');
            $table->string('quartier', 100)->nullable()->after('ville');
            $table->decimal('latitude', 10, 7)->nullable()->after('adresse');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        Schema::table('entreprises_tierces', function (Blueprint $table) {
            $table->string('nom_commercial', 200)->nullable()->after('raison_sociale');
            $table->string('rccm', 50)->nullable()->after('identifiant_fiscal');
            $table->string('telephone_secondaire', 30)->nullable()->after('telephone_normalise');
            $table->string('quartier', 100)->nullable()->after('ville');
            $table->decimal('latitude', 10, 7)->nullable()->after('adresse');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('entreprises_tierces', function (Blueprint $table) {
            $table->dropColumn(['nom_commercial', 'rccm', 'telephone_secondaire', 'quartier', 'latitude', 'longitude']);
        });

        Schema::table('personnes', function (Blueprint $table) {
            $table->dropColumn(['telephone_secondaire', 'quartier', 'latitude', 'longitude']);
        });
    }
};
