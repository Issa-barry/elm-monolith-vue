<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Personne;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

class ParrainFactory extends Factory
{
    private const IDENTITY_KEYS = ['nom_complet', 'nom', 'prenom', 'surnom', 'email', 'telephone', 'pays', 'code_pays', 'code_phone_pays', 'ville', 'adresse'];

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'is_active' => true,
        ];
    }

    /**
     * Même verrou de réentrance que ProprietaireFactory::create() — évite la récursion infinie
     * de `state($attributes)->create([])` qui rappelle cette méthode.
     */
    private static bool $resolvingIdentity = false;

    /**
     * Compatibilité : `Parrain::factory()->create(['nom_complet' => ..., 'telephone' => ...])`
     * route vers une Personne dédiée de la même organisation plutôt que d'ignorer ces clés
     * (Parrain lui-même ne porte aucune colonne d'identité — cf. app/Models/Parrain.php).
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (is_array($attributes) && ! isset($attributes['personne_id']) && ! self::$resolvingIdentity) {
            self::$resolvingIdentity = true;

            try {
                $orgId = $attributes['organization_id'] ?? Organization::factory()->create()->id;
                $attributes['organization_id'] = $orgId;

                $overrides = array_intersect_key($attributes, array_flip(self::IDENTITY_KEYS));
                $defaults = [
                    'nom' => strtoupper(fake()->lastName()),
                    'prenom' => fake()->firstName(),
                    'telephone' => '+2246'.fake()->unique()->numerify('########'),
                    'code_phone_pays' => '+224',
                    'code_pays' => 'GN',
                    'pays' => 'Guinée',
                    'ville' => 'Conakry',
                ];

                $attributes['personne_id'] = Personne::factory()->create([
                    'organization_id' => $orgId,
                    ...$defaults,
                    ...$overrides,
                ])->id;

                foreach (self::IDENTITY_KEYS as $key) {
                    unset($attributes[$key]);
                }

                return parent::create($attributes, $parent);
            } finally {
                self::$resolvingIdentity = false;
            }
        }

        return parent::create($attributes, $parent);
    }
}
