<?php

namespace App\Services;

use App\DTOs\RegisterData;
use App\Enums\UserStatus;
use App\Mail\EmailVerificationMail;
use App\Models\Client;
use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\User;
use App\Models\UserAuthIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RegistrationService
{
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

        if (UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone($phone)) !== null) {
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

        if (UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone($phone)) !== null) {
            throw ValidationException::withMessages([
                'telephone' => 'Un compte existe déjà avec ce numéro de téléphone. Veuillez vous connecter.',
            ]);
        }

        $hasEmail = filled($data->email);
        $token = Str::random(64);

        $user = DB::transaction(function () use ($data, $phone, $token, $hasEmail) {
            // Pas encore d'organisation à ce stade (auto-inscription "à la volée" — cf.
            // Personne::resoudreOuCreer(), organization_id nullable) : linkPersonRecords()
            // rattachera cette Personne à une organisation si un rôle existant est trouvé.
            $personne = Personne::create([
                'organization_id' => null,
                'prenom' => self::formatPrenom($data->prenom),
                'nom' => mb_strtoupper($data->nom),
                'telephone' => $phone,
                'telephone_normalise' => Personne::normaliserTelephone($phone),
                'email' => $hasEmail ? mb_strtolower($data->email) : null,
            ]);

            $user = User::create([
                'personne_id' => $personne->id,
                'password' => $data->password,
                'status' => $hasEmail ? UserStatus::PENDING->value : UserStatus::ACTIVE->value,
                'is_active' => ! $hasEmail,
            ]);
            $user->authIdentities()->create([
                'type' => UserAuthIdentity::TYPE_TELEPHONE,
                'value' => $phone,
                'normalized_value' => Personne::normaliserTelephone($phone),
                'verified_at' => now(),
                'is_primary' => true,
            ]);
            if ($hasEmail) {
                $user->authIdentities()->create([
                    'type' => UserAuthIdentity::TYPE_EMAIL,
                    'value' => mb_strtolower($data->email),
                    'normalized_value' => UserAuthIdentity::normaliser(UserAuthIdentity::TYPE_EMAIL, $data->email),
                    'verification_token' => $token,
                    'verification_expires_at' => now()->addHours(24),
                ]);
            }

            Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
            $user->assignRole('client');

            $this->linkPersonRecords($user, $phone);

            return $user;
        });

        if ($user->email) {
            Mail::to($user->email)->send(new EmailVerificationMail($user, $token));
        }

        return $user;
    }

    /**
     * Valide le token email, active le compte et retourne l'utilisateur.
     *
     * @throws HttpException
     */
    public function verifyEmail(string $token): User
    {
        $identity = UserAuthIdentity::where('verification_token', $token)
            ->whereNotNull('verification_token')
            ->first();

        if (! $identity) {
            abort(404, 'Lien de validation invalide ou déjà utilisé.');
        }

        if ($identity->verification_expires_at < now()) {
            abort(410, 'Ce lien de validation a expiré. Veuillez vous réinscrire.');
        }

        $identity->update([
            'verified_at' => now(),
            'verification_token' => null,
            'verification_expires_at' => null,
        ]);

        $user = $identity->user;
        $user->update([
            'status' => UserStatus::ACTIVE->value,
            'is_active' => true,
        ]);

        return $user->fresh();
    }

    private function findPersonPrefill(string $phone): ?array
    {
        $client = Client::where('telephone', $phone)->whereNull('user_id')->first();
        if ($client) {
            return ['prenom' => $client->prenom, 'nom' => $client->nom];
        }

        $normalise = Personne::normaliserTelephone($phone);

        $livreur = Livreur::whereNull('user_id')
            ->whereHas('personne', fn ($q) => $q->where('telephone_normalise', $normalise))
            ->first();
        if ($livreur) {
            return ['prenom' => $livreur->prenom, 'nom' => $livreur->nom];
        }

        $proprietaire = Proprietaire::whereNull('user_id')
            ->whereHas('personne', fn ($q) => $q->where('telephone_normalise', $normalise))
            ->first();
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

        $normalise = Personne::normaliserTelephone($phone);

        $livreur = Livreur::whereNull('user_id')
            ->whereHas('personne', fn ($q) => $q->where('telephone_normalise', $normalise))
            ->first();
        if ($livreur) {
            Role::firstOrCreate(['name' => 'livreur', 'guard_name' => 'web']);
            $user->assignRole('livreur');
            $livreur->update(['user_id' => $user->id]);
            $this->rattacherMemePersonne($user, $livreur->personne, $livreur->organization_id);
            $this->findOrCreateClientInOrg($user, $livreur->organization_id, $phone);

            return;
        }

        $proprietaire = Proprietaire::whereNull('user_id')
            ->whereHas('personne', fn ($q) => $q->where('telephone_normalise', $normalise))
            ->first();
        if ($proprietaire) {
            Role::firstOrCreate(['name' => 'proprietaire', 'guard_name' => 'web']);
            $user->assignRole('proprietaire');
            $proprietaire->update(['user_id' => $user->id]);
            $this->rattacherMemePersonne($user, $proprietaire->personne, $proprietaire->organization_id);
            $this->findOrCreateClientInOrg($user, $proprietaire->organization_id, $phone);
        }
    }

    /**
     * Le compte vient d'être créé avec une Personne "à la volée" (sans organisation) ; on
     * découvre ici qu'il s'agit en réalité de la même personne physique qu'un rôle métier déjà
     * existant (même téléphone) — on rattache le compte à CETTE Personne (celle du rôle,
     * rattachée à son organisation) plutôt que de garder deux fiches distinctes pour le même
     * humain, et on supprime la fiche provisoire devenue orpheline.
     */
    private function rattacherMemePersonne(User $user, Personne $personneDuRole, string $organizationId): void
    {
        $ancienne = $user->personne;
        $user->update(['organization_id' => $organizationId, 'personne_id' => $personneDuRole->id]);

        if ($ancienne->id !== $personneDuRole->id) {
            $ancienne->delete();
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
