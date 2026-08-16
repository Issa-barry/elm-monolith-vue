<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Personne;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

class ProprietaireFactory extends Factory
{
    private const IDENTITY_KEYS = ['nom', 'prenom', 'surnom', 'email', 'telephone', 'pays', 'code_pays', 'code_phone_pays', 'ville', 'adresse'];

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'is_active' => true,
        ];
    }

    /**
     * Verrou de réentrance : `parent::create()` avec des attributs non vides route via
     * `state($attributes)->create([])`, qui rappelle CETTE méthode avec `$attributes = []`.
     * Sans ce verrou, la garde ci-dessous redevient vraie à chaque rappel et re-résout un
     * nouveau personne_id indéfiniment (récursion infinie observée en CI : job PHPUnit qui
     * ne termine jamais dès qu'un test crée un Vehicule/Proprietaire).
     */
    private static bool $resolvingIdentity = false;

    /**
     * Compatibilité : `Proprietaire::factory()->create(['nom' => ..., 'telephone' => ...])`
     * reste possible malgré le déplacement de l'identité vers Personne — routé vers une
     * Personne dédiée de la même organisation plutôt que silencieusement ignoré.
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
                    'email' => fake()->unique()->safeEmail(),
                    'telephone' => '+2246'.fake()->numerify('########'),
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
