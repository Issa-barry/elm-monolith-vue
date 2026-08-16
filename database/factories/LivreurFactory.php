<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Personne;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

class LivreurFactory extends Factory
{
    private const IDENTITY_KEYS = ['nom', 'prenom', 'telephone'];

    public function definition(): array
    {
        $nom = fake()->lastName();
        $prenom = fake()->firstName();

        return [
            'organization_id' => Organization::factory(),
            'nom_complet' => "{$prenom} {$nom}",
            'is_active' => true,
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
     * Compatibilité : `Livreur::factory()->create(['nom' => ..., 'telephone' => ...])` reste
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
                $nom = $overrides['nom'] ?? fake()->lastName();
                $prenom = $overrides['prenom'] ?? fake()->firstName();

                $attributes['personne_id'] = Personne::factory()->create([
                    'organization_id' => $orgId,
                    'nom' => $nom,
                    'prenom' => $prenom,
                    'telephone' => $overrides['telephone'] ?? ('+224'.fake()->unique()->numerify('#########')),
                ])->id;

                if (! isset($attributes['nom_complet']) && ($overrides['nom'] ?? null) !== null) {
                    $attributes['nom_complet'] = "{$prenom} {$nom}";
                }

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
