<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Services\PhoneNormalizer;
use App\Support\Auth\AccountEligibility;
use App\Support\Auth\AccountStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class LoginController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'telephone' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $phone = PhoneNormalizer::normalize($request->input('telephone', ''));

        if ($phone === null) {
            throw ValidationException::withMessages([
                'telephone' => 'Numéro de téléphone invalide.',
            ]);
        }

        $user = UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone($phone));

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'telephone' => 'Les identifiants fournis sont incorrects.',
            ]);
        }

        $status = AccountEligibility::status($user);

        if ($status !== AccountStatus::Ok) {
            return response()->json([
                'message' => AccountEligibility::message($status),
                'code' => $status->value,
            ], 403);
        }

        $this->lierCompteParTelephone($user);

        $token = $user->createToken($request->input('device_name'))->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userResource($user),
        ]);
    }

    /**
     * Si le téléphone de l'utilisateur correspond à un livreur ou propriétaire
     * sans user_id, on établit le lien automatiquement — et on attribue le rôle
     * Spatie correspondant, exactement comme le font déjà les flux d'inscription
     * (RegistrationService::linkPersonRecords(), CreateNewUser::linkOrCreateClient()).
     * Avant ce correctif, ce rattachement au LOGIN (staff dont le profil métier est
     * créé après coup par un admin) ne posait que `user_id`, jamais le rôle : le
     * compte restait bloqué hors de l'espace client malgré un profil valide (cf.
     * audit du 26/08/2026 — cas réel constaté sur un compte super_admin devenu
     * propriétaire de 36 véhicules sans jamais recevoir le rôle `proprietaire`).
     */
    private function lierCompteParTelephone(User $user): void
    {
        if (! $user->telephone) {
            return;
        }

        // nom/prenom/telephone ne sont plus des colonnes de livreurs/proprietaires — l'identité
        // civile est portée par Personne (cf. Personne::normaliserTelephone(), même pattern que
        // RegisterLookupController/UserInvitationService).
        $normalise = Personne::normaliserTelephone($user->telephone);

        $livreurLie = Livreur::whereHas('personne', fn ($q) => $q->where('telephone_normalise', $normalise))
            ->whereNull('user_id')
            ->update(['user_id' => $user->id]);

        if ($livreurLie > 0) {
            Role::firstOrCreate(['name' => 'livreur', 'guard_name' => 'web']);
            $user->assignRole('livreur');
        }

        $proprietaireLie = Proprietaire::whereHas('personne', fn ($q) => $q->where('telephone_normalise', $normalise))
            ->whereNull('user_id')
            ->update(['user_id' => $user->id]);

        if ($proprietaireLie > 0) {
            Role::firstOrCreate(['name' => 'proprietaire', 'guard_name' => 'web']);
            $user->assignRole('proprietaire');
        }
    }

    private function userResource(User $user): array
    {
        return [
            'id' => $user->id,
            'prenom' => $user->prenom,
            'nom' => $user->nom,
            'telephone' => $user->telephone,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
        ];
    }
}
