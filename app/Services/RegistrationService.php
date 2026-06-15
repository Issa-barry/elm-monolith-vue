<?php

namespace App\Services;

use App\DTOs\RegisterData;
use App\Enums\UserStatus;
use App\Mail\EmailVerificationMail;
use App\Models\Client;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RegistrationService
{
    private const TOKEN_TTL_HOURS = 24;

    /**
     * Recherche un numéro dans toutes les tables personnes.
     * Retourne le statut et les données de pré-remplissage si disponibles.
     *
     * @return array{status: string, prefill: array{prenom: string, nom: string}|null}
     *
     * @throws ValidationException si le numéro est invalide
     */
    public function lookupPhone(string $rawPhone): array
    {
        $phone = PhoneNormalizer::normalize($rawPhone);

        if ($phone === null) {
            throw ValidationException::withMessages([
                'telephone' => 'Numéro de téléphone invalide.',
            ]);
        }

        if (User::where('telephone', $phone)->exists()) {
            return ['status' => 'user_exists', 'prefill' => null];
        }

        $prefill = $this->findPersonPrefill($phone);

        return [
            'status' => $prefill ? 'prefill_available' : 'not_found',
            'prefill' => $prefill,
        ];
    }

    /**
     * Crée un nouveau compte en statut pending et envoie l'email de vérification.
     *
     * @throws ValidationException si le téléphone est invalide ou déjà utilisé
     */
    public function register(RegisterData $data): User
    {
        $phone = PhoneNormalizer::normalize($data->telephone);

        if ($phone === null) {
            throw ValidationException::withMessages([
                'telephone' => 'Numéro de téléphone invalide.',
            ]);
        }

        if (User::where('telephone', $phone)->exists()) {
            throw ValidationException::withMessages([
                'telephone' => 'Un compte existe déjà avec ce numéro de téléphone. Veuillez vous connecter.',
            ]);
        }

        return DB::transaction(function () use ($data, $phone) {
            $token = Str::random(64);

            $user = User::create([
                'prenom' => self::formatPrenom($data->prenom),
                'nom' => mb_strtoupper($data->nom),
                'email' => mb_strtolower($data->email),
                'telephone' => $phone,
                'password' => $data->password,
                'status' => UserStatus::PENDING->value,
                'is_active' => false,
                'email_verified_at' => null,
                'email_verification_token' => $token,
                'email_verification_expires_at' => now()->addHours(self::TOKEN_TTL_HOURS),
            ]);

            Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
            $user->assignRole('client');

            $this->linkPersonRecords($user, $phone);

            Mail::to($user->email)->send(new EmailVerificationMail($user, $token));

            return $user;
        });
    }

    /**
     * Valide le token email, active le compte et retourne l'utilisateur.
     *
     * @throws HttpException
     */
    public function verifyEmail(string $token): User
    {
        $user = User::where('email_verification_token', $token)
            ->whereNotNull('email_verification_token')
            ->first();

        if (! $user) {
            abort(404, 'Lien de validation invalide ou déjà utilisé.');
        }

        if ($user->email_verification_expires_at < now()) {
            abort(410, 'Ce lien de validation a expiré. Veuillez vous réinscrire.');
        }

        $user->update([
            'status' => UserStatus::ACTIVE->value,
            'is_active' => true,
            'email_verified_at' => now(),
            'email_verification_token' => null,
            'email_verification_expires_at' => null,
        ]);

        return $user->fresh();
    }

    private function findPersonPrefill(string $phone): ?array
    {
        $client = Client::where('telephone', $phone)->whereNull('user_id')->first();
        if ($client) {
            return ['prenom' => $client->prenom, 'nom' => $client->nom];
        }

        $livreur = Livreur::where('telephone', $phone)->whereNull('user_id')->first();
        if ($livreur) {
            return ['prenom' => $livreur->prenom, 'nom' => $livreur->nom];
        }

        $proprietaire = Proprietaire::where('telephone', $phone)->whereNull('user_id')->first();
        if ($proprietaire) {
            return ['prenom' => $proprietaire->prenom, 'nom' => $proprietaire->nom];
        }

        return null;
    }

    private function linkPersonRecords(User $user, string $phone): void
    {
        $client = Client::where('telephone', $phone)->whereNull('user_id')->first();
        if ($client) {
            $client->update(['user_id' => $user->id]);

            return;
        }

        $livreur = Livreur::where('telephone', $phone)->whereNull('user_id')->first();
        if ($livreur) {
            Role::firstOrCreate(['name' => 'livreur', 'guard_name' => 'web']);
            $user->assignRole('livreur');
            $livreur->update(['user_id' => $user->id]);
            $user->update(['organization_id' => $livreur->organization_id]);
            $this->findOrCreateClientInOrg($user, $livreur->organization_id, $phone);

            return;
        }

        $proprietaire = Proprietaire::where('telephone', $phone)->whereNull('user_id')->first();
        if ($proprietaire) {
            Role::firstOrCreate(['name' => 'proprietaire', 'guard_name' => 'web']);
            $user->assignRole('proprietaire');
            $proprietaire->update(['user_id' => $user->id]);
            $this->findOrCreateClientInOrg($user, $proprietaire->organization_id, $phone);
        }
    }

    private function findOrCreateClientInOrg(User $user, string $organizationId, string $phone): void
    {
        $existing = Client::where('organization_id', $organizationId)
            ->where('telephone', $phone)
            ->first();

        if ($existing) {
            $existing->update(['user_id' => $user->id]);
        } else {
            Client::create([
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'nom' => $user->nom,
                'prenom' => $user->prenom,
                'telephone' => $phone,
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
}
