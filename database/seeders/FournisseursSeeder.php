<?php

namespace Database\Seeders;

use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class FournisseursSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        // Fournisseur de démonstration — volontairement rattaché à aucun produit (le
        // fournisseur est facultatif, cf. ProduitsSeeder) : juste de quoi tester le select dès
        // le premier chargement de "Nouveau produit" sans passer par la création rapide.
        $entreprise = EntrepriseTierce::resoudreOuCreer($org->id, [
            'raison_sociale' => 'SOGUIDEP',
            'telephone' => '+224620000003',
            'email' => 'contact@soguidep.example.com',
            'pays' => 'Guinée',
            'code_pays' => 'GN',
            'code_phone_pays' => '+224',
            'ville' => 'Conakry',
            'adresse' => 'Matoto',
        ]);

        Fournisseur::firstOrCreate(
            ['entreprise_tierce_id' => $entreprise->id],
            [
                'organization_id' => $org->id,
                'entreprise_tierce_id' => $entreprise->id,
                'is_active' => true,
            ]
        );
    }
}
