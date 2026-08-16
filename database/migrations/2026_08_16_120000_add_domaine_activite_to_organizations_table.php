<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activité principale de l'organisation (cf. App\Enums\DomaineActivite), saisie une fois à
 * l'installation (CLI et web) et jamais déduite du nom. Nullable : les organisations créées
 * avant cette colonne (seeders historiques, jeux de données existants) n'ont pas de valeur —
 * tout code qui lit domaine_activite doit tolérer null plutôt que planter (cf.
 * DomaineActivite::siteTypes() appelé uniquement quand la valeur est présente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('domaine_activite', 30)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('domaine_activite');
        });
    }
};
