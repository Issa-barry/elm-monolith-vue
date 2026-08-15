<?php

use App\Models\Vehicule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Détecte les doublons "même plaque, saisie différemment" (tirets/espaces/points/casse), qui
 * échappaient à la contrainte unique existante sur `immatriculation` (comparaison brute) — ex :
 * "BK-4627-02", "bk 4627 02" et "BK.4627.02" désignent le même véhicule mais sont trois chaînes
 * différentes. `immatriculation` reste le champ affiché tel que saisi ; cette colonne est la clé
 * technique de comparaison (cf. Vehicule::normaliserImmatriculation(), recalculée automatiquement
 * à chaque sauvegarde du modèle — jamais à renseigner à la main). Contrainte unique PAR
 * organisation : deux organisations différentes peuvent avoir la même plaque, jamais deux
 * véhicules de la même organisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicules', function (Blueprint $table) {
            $table->string('immatriculation_normalisee', 20)->nullable()->after('immatriculation');
        });

        // Backfill des véhicules déjà en base avant l'ajout de cette colonne — même algorithme
        // que le modèle, jamais laissée null après cette migration.
        DB::table('vehicules')->select('id', 'immatriculation')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('vehicules')->where('id', $row->id)->update([
                    'immatriculation_normalisee' => Vehicule::normaliserImmatriculation($row->immatriculation),
                ]);
            }
        });

        Schema::table('vehicules', function (Blueprint $table) {
            $table->unique(['organization_id', 'immatriculation_normalisee'], 'vehicules_org_immat_normalisee_unique');
        });
    }

    public function down(): void
    {
        Schema::table('vehicules', function (Blueprint $table) {
            $table->dropUnique('vehicules_org_immat_normalisee_unique');
            $table->dropColumn('immatriculation_normalisee');
        });
    }
};
