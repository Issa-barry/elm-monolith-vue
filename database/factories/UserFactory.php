<?php

namespace Database\Factories;

use App\Models\Personne;
use App\Models\User;
use App\Models\UserAuthIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    private const IDENTITY_KEYS = ['nom', 'prenom', 'email', 'telephone', 'pays', 'code_pays', 'code_phone_pays', 'ville', 'adresse'];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'personne_id' => Personne::factory(),
            'password' => static::$password ??= 'password',
            'remember_token' => Str::random(10),
            'two_factor_secret' => Str::random(10),
            'two_factor_recovery_codes' => Str::random(10),
            'two_factor_confirmed_at' => now(),
            'is_active' => true,
            'status' => User::STATUS_ACTIVE,
        ];
    }

    /**
     * Compatibilité : `User::factory()->create(['nom' => ..., 'telephone' => ...])` reste
     * possible malgré le déplacement de l'identité vers Personne/UserAuthIdentity — les clés
     * d'identité passées ici sont routées vers une Personne dédiée (et une UserAuthIdentity
     * téléphone/email) plutôt que d'être silencieusement ignorées par le mass-assignment.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (is_array($attributes)) {
            $overrides = array_intersect_key($attributes, array_flip(self::IDENTITY_KEYS));

            if ($overrides !== [] && ! isset($attributes['personne_id'])) {
                $attributes['personne_id'] = Personne::factory()->create($overrides)->id;
            }

            foreach (self::IDENTITY_KEYS as $key) {
                unset($attributes[$key]);
            }
        }

        return parent::create($attributes, $parent);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if ($user->telephoneIdentity() === null) {
                $telephone = $user->personne?->telephone ?? ('+224'.fake()->unique()->numerify('#########'));
                $user->authIdentities()->create([
                    'type' => UserAuthIdentity::TYPE_TELEPHONE,
                    'value' => $telephone,
                    'normalized_value' => Personne::normaliserTelephone($telephone),
                    'verified_at' => now(),
                    'is_primary' => true,
                ]);
            }

            if ($user->emailIdentity() === null && $user->personne?->email) {
                $email = $user->personne->email;
                $user->authIdentities()->create([
                    'type' => UserAuthIdentity::TYPE_EMAIL,
                    'value' => $email,
                    'normalized_value' => UserAuthIdentity::normaliser(UserAuthIdentity::TYPE_EMAIL, $email),
                    'verified_at' => now(),
                ]);
            }
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->afterCreating(function (User $user) {
            $user->emailIdentity()?->update(['verified_at' => null]);
        });
    }

    /**
     * Indicate that the model does not have two-factor authentication configured.
     */
    public function withoutTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }
}
