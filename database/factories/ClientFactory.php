<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        // Génère un numéro guinéen canonique : +224 6XXXXXXXX (9 chiffres locaux)
        $localDigits = '6'.fake()->numerify('########');

        return [
            'organization_id' => Organization::factory(),
            'user_id' => null,
            'nom' => strtoupper(fake()->lastName()),
            'prenom' => fake()->firstName(),
            'email' => fake()->unique()->safeEmail(),
            'telephone' => '+224'.$localDigits,
            'code_phone_pays' => '+224',
            'code_pays' => 'GN',
            'pays' => 'Guinée',
            'ville' => 'Conakry',
            'adresse' => null,
            'is_active' => true,
        ];
    }
}
