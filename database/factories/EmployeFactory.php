<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Personne;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

class EmployeFactory extends Factory
{
    private const IDENTITY_KEYS = ['nom', 'prenom', 'email', 'telephone'];

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'matricule' => strtoupper(fake()->bothify('EMP-####')),
            'type_employe' => 'interne',
            'statut' => 'actif',
            'site_id' => null,
        ];
    }

    /**
     * Verrou de réentrance : `parent::create()` avec des attributs non vides route via
     * `state($attributes)->create([])`, qui rappelle CETTE méthode avec `$attributes = []`.
     * Sans ce verrou, la garde ci-dessous redevient vraie à chaque rappel et re-résout un
     * nouveau personne_id indéfiniment (récursion infinie).
     */
    private static bool $resolvingIdentity = false;

    /**
     * Compatibilité : `Employe::factory()->create(['nom' => ..., 'telephone' => ...])` reste
     * possible malgré le déplacement de l'identité civile vers Personne.
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
                    'telephone' => '+224'.fake()->numerify('#########'),
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
