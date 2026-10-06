<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Client devient un rôle porté par Personne, comme Proprietaire/Fournisseur/
            // Prestataire/Livreur/Employe/User — cf. docs/identite-client-personne.md. Nullable
            // et non destructif : les colonnes d'identité propres à Client (nom_complet,
            // telephone, ville...) restent inchangées et continuent d'alimenter tous les
            // affichages existants ; personne_id sert à la résolution/dédoublonnage d'identité
            // (recherche parrain, partage entre rôles), pas à leur remplacement.
            $table->foreignUlid('personne_id')
                ->nullable()
                ->after('user_id')
                ->constrained('personnes')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('personne_id');
        });
    }
};
