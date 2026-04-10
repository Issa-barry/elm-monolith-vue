<?php

namespace App\Actions\Fortify;

use App\Features\ModuleFeature;
use App\Models\Client;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\OtpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Spatie\Permission\Models\Role;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    private const PHONE_BY_COUNTRY = [
        'GN' => ['prefix' => '+224', 'min' => 9,  'max' => 9],
        'GW' => ['prefix' => '+245', 'min' => 7,  'max' => 7],
        'SN' => ['prefix' => '+221', 'min' => 9,  'max' => 9],
        'ML' => ['prefix' => '+223', 'min' => 8,  'max' => 8],
        'CI' => ['prefix' => '+225', 'min' => 10, 'max' => 10],
        'LR' => ['prefix' => '+231', 'min' => 8,  'max' => 8],
        'SL' => ['prefix' => '+232', 'min' => 8,  'max' => 8],
        'FR' => ['prefix' => '+33',  'min' => 9,  'max' => 9],
        'CN' => ['prefix' => '+86',  'min' => 11, 'max' => 11],
        'AE' => ['prefix' => '+971', 'min' => 9,  'max' => 9],
        'IN' => ['prefix' => '+91',  'min' => 10, 'max' => 10],
    ];

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        if (! ModuleService::isPublicActive(ModuleFeature::INSCRIPTION)) {
            abort(403, 'Les inscriptions sont desactivees.');
        }

        $validated = Validator::make($input, [
            'prenom' => ['required', 'string', 'min:2', 'max:100'],
            'nom' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'telephone' => ['nullable', 'string', 'max:30'],
            'telephone_country' => ['nullable', 'string', Rule::in(array_keys(self::PHONE_BY_COUNTRY))],
            'telephone_local' => ['nullable', 'string', 'regex:/^\d*$/', 'max:15'],
            'password' => ['required', 'string', \Illuminate\Validation\Rules\Password::default()],
        ], [
            'telephone_local.regex' => 'Saisissez uniquement des chiffres pour le numéro.',
        ])->validate();

        $telephone = $this->resolveTelephone($validated);

        // ── Unicité téléphone dans users ───────────────────────────────────────
        if ($telephone !== null && User::where('telephone', $telephone)->exists()) {
            throw ValidationException::withMessages([
                'telephone' => 'Ce numéro est déjà associé à un compte. Connectez-vous ou réinitialisez votre mot de passe.',
            ]);
        }

        // ── Vérification OTP (obligatoire si un numéro est fourni) ─────────────
        if ($telephone !== null) {
            /** @var OtpService $otp */
            $otp = app(OtpService::class);

            if (! $otp->isVerified($telephone)) {
                throw ValidationException::withMessages([
                    'telephone' => 'La vérification par code OTP est requise pour créer un compte avec ce numéro.',
                ]);
            }
        }

        return DB::transaction(function () use ($validated, $telephone) {
            $user = User::create([
                'prenom' => self::formatPrenom($validated['prenom']),
                'nom' => mb_strtoupper($validated['nom']),
                'email' => isset($validated['email']) ? mb_strtolower($validated['email']) : null,
                'telephone' => $telephone,
                'password' => $validated['password'],
            ]);

            Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
            $user->assignRole('client');

            if ($telephone !== null) {
                $this->linkOrCreateClient($user, $telephone);

                // Libérer l'OTP de la session
                app(OtpService::class)->clear($telephone);
            }

            return $user;
        });
    }

    /**
     * Lie l'utilisateur à un client existant ou crée un enregistrement client
     * si le numéro est connu dans les tables livreurs ou proprietaires.
     */
    private function linkOrCreateClient(User $user, string $telephone): void
    {
        // 1. Client existant sans user_id → liaison directe
        $client = Client::where('telephone', $telephone)->whereNull('user_id')->first();
        if ($client) {
            $client->update(['user_id' => $user->id]);

            return;
        }

        // 2. Livreur → créer un client dans son organisation
        $livreur = Livreur::where('telephone', $telephone)->first();
        if ($livreur) {
            $this->findOrCreateClientInOrg($user, $livreur->organization_id, $telephone);

            return;
        }

        // 3. Propriétaire sans user_id → lier le propriétaire et créer un client
        $proprietaire = Proprietaire::where('telephone', $telephone)->whereNull('user_id')->first();
        if ($proprietaire) {
            $proprietaire->update(['user_id' => $user->id]);
            $this->findOrCreateClientInOrg($user, $proprietaire->organization_id, $telephone);
        }
    }

    /**
     * Trouve ou crée un client dans l'organisation donnée pour l'utilisateur.
     */
    private function findOrCreateClientInOrg(User $user, int $organizationId, string $telephone): void
    {
        $existing = Client::where('organization_id', $organizationId)
            ->where('telephone', $telephone)
            ->first();

        if ($existing) {
            $existing->update(['user_id' => $user->id]);
        } else {
            Client::create([
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'nom' => $user->nom,
                'prenom' => $user->prenom,
                'telephone' => $telephone,
            ]);
        }
    }

    private static function formatPrenom(string $value): string
    {
        $lower = mb_strtolower($value, 'UTF-8');

        return preg_replace_callback(
            '/(^|[\s-])(\pL)/u',
            fn ($m) => $m[1].mb_strtoupper($m[2], 'UTF-8'),
            $lower,
        );
    }

    private function resolveTelephone(array $validated): ?string
    {
        $local = preg_replace('/\D/', '', (string) ($validated['telephone_local'] ?? '')) ?? '';

        if ($local !== '') {
            $countryCode = (string) ($validated['telephone_country'] ?? '');
            $country = self::PHONE_BY_COUNTRY[$countryCode] ?? null;

            if ($country === null) {
                throw ValidationException::withMessages([
                    'telephone' => 'Pays téléphonique invalide.',
                ]);
            }

            $length = strlen($local);
            $min = $country['min'];
            $max = $country['max'];
            if ($length < $min || $length > $max) {
                $expected = $min === $max ? "{$min}" : "entre {$min} et {$max}";
                throw ValidationException::withMessages([
                    'telephone' => "Le numéro doit contenir {$expected} chiffres (sans indicatif).",
                ]);
            }

            return $country['prefix'].$local;
        }

        $legacy = trim((string) ($validated['telephone'] ?? ''));
        if ($legacy === '') {
            return null;
        }

        $normalized = $this->normalizeLegacyTelephone($legacy);
        if ($normalized === null) {
            throw ValidationException::withMessages([
                'telephone' => 'Le numéro de téléphone est invalide.',
            ]);
        }

        return $normalized;
    }

    private function normalizeLegacyTelephone(string $value): ?string
    {
        $telephone = trim($value);
        if ($telephone === '') {
            return null;
        }

        if (str_starts_with($telephone, '00')) {
            $telephone = '+'.substr($telephone, 2);
        }

        if (! str_starts_with($telephone, '+')) {
            return null;
        }

        $digits = preg_replace('/\D/', '', substr($telephone, 1)) ?? '';
        $len = strlen($digits);
        if ($len < 6 || $len > 18) {
            return null;
        }

        return '+'.$digits;
    }
}
