<?php

use App\Models\Parametre;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $orgs = DB::table('organizations')->pluck('id');

        foreach ($orgs as $orgId) {
            $exists = DB::table('parametres')
                ->where('organization_id', $orgId)
                ->where('cle', Parametre::CLE_TAUX_PROPRIETAIRE_DEFAUT)
                ->exists();

            if (! $exists) {
                DB::table('parametres')->insert([
                    'organization_id' => $orgId,
                    'cle' => Parametre::CLE_TAUX_PROPRIETAIRE_DEFAUT,
                    'valeur' => '60',
                    'type' => Parametre::TYPE_DECIMAL,
                    'groupe' => Parametre::GROUPE_VEHICULES,
                    'description' => 'Taux de commission attribué au propriétaire par défaut (%) lors de la création d\'un véhicule sans équipe',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('parametres')
            ->where('cle', Parametre::CLE_TAUX_PROPRIETAIRE_DEFAUT)
            ->delete();
    }
};
