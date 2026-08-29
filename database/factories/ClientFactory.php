<?php

namespace Database\Factories;

use App\Enums\ClientType;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
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
            // Explicite plutôt que de reposer sur le défaut colonne : évite toute dépendance au
            // défaut SQL (non modifiable sur SQLite sans doctrine/dbal, cf. migration
            // migrate_client_type_standard_to_revendeur) et reste correct même si ce défaut change.
            'type' => ClientType::REVENDEUR->value,
            // Un Revendeur exige cashback actif + montant positif (CASHBACK-001, cf.
            // CashbackEligibiliteService) — cohérent avec le type par défaut ci-dessus, pour
            // qu'un Client::factory()->create() nu représente toujours un état valide.
            'cashback_eligible' => true,
            'cashback_montant_par_pack' => 300,
        ];
    }
}
