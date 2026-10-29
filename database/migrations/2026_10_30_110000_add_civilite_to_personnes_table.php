<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Civilité (Monsieur, Madame, Mademoiselle) à la place du sexe dans le dossier salarié
 * (docs/rh-employes-identite.md). Additive : la colonne `sexe` est conservée, rien n'est supprimé.
 *
 * Reprise des valeurs déjà saisies : M → Monsieur, F → Madame (civilité usuelle par défaut,
 * « Mademoiselle » n'est jamais déduite). Rejouable : seules les civilités vides sont complétées.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('personnes', 'civilite')) {
            Schema::table('personnes', function (Blueprint $table) {
                $table->string('civilite', 20)->nullable()->after('lieu_naissance');
            });
        }

        DB::table('personnes')->whereNull('civilite')->where('sexe', 'M')->update(['civilite' => 'monsieur']);
        DB::table('personnes')->whereNull('civilite')->where('sexe', 'F')->update(['civilite' => 'madame']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('personnes', 'civilite')) {
            Schema::table('personnes', function (Blueprint $table) {
                $table->dropColumn('civilite');
            });
        }
    }
};
