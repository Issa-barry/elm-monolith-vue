<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('option_catalogues', function (Blueprint $table) {
            // Option de bibliothèque proposée par défaut (Couleur/Taille/Pointure), seedée
            // pour chaque organisation. Non supprimable (cf. OptionCatalogueController::destroy()),
            // mais ses valeurs proposées restent librement ajoutables/supprimables.
            $table->boolean('is_system')->default(false)->after('nom');
        });
    }

    public function down(): void
    {
        Schema::table('option_catalogues', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }
};
