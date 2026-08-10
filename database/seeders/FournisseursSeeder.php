<?php

namespace Database\Seeders;

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
        Fournisseur::firstOrCreate(
            ['email' => 'contact@soguidep.example.com'],
            [
                'organization_id' => $org->id,
                'raison_sociale' => 'SOGUIDEP',
                'phone' => '+224620000003',
                'code_phone_pays' => '+224',
                'code_pays' => 'GN',
                'pays' => 'Guinée',
                'ville' => 'Conakry',
                'adresse' => 'Matoto',
                'is_active' => true,
            ]
        );
    }
}
