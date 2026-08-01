<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class LivreurFactory extends Factory
{
    public function definition(): array
    {
        $nom = fake()->lastName();
        $prenom = fake()->firstName();

        return [
            'organization_id' => Organization::factory(),
            // Identité civile jamais utilisée côté Eau La Maman — conservée ici
            // pour compatibilité, nom_complet est le seul champ affiché.
            'nom' => $nom,
            'prenom' => $prenom,
            'nom_complet' => "{$prenom} {$nom}",
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
            'is_active' => true,
        ];
    }
}
