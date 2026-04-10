<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProprietaireFactory extends Factory
{
    public function definition(): array
    {
        // Génère un numéro guinéen canonique : +224 6XXXXXXXX (9 chiffres locaux)
        $localDigits = '6'.fake()->numerify('########');

        return [
            'organization_id' => Organization::factory(),
            'nom' => strtoupper(fake()->lastName()),
            'prenom' => fake()->firstName(),
            'email' => fake()->unique()->safeEmail(),
            'telephone' => '+224'.$localDigits,
            'code_phone_pays' => '+224',
            'code_pays' => 'GN',
            'pays' => 'Guinée',
            'ville' => 'Conakry',
            'is_active' => true,
        ];
    }
}
