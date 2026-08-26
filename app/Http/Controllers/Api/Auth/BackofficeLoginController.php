<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class BackofficeLoginController extends Controller
{
    /** Rôles autorisés à se connecter sur l'application mobile backoffice. */
    private const STAFF_ROLES = ['admin_entreprise', 'super_admin'];

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

        if (! $user->hasVerifiedEmail() && ! $user->isSuperAdmin()) {
            return response()->json([
                'message' => 'Veuillez vérifier votre adresse email pour activer votre compte.',
                'code' => 'email_not_verified',
            ], 403);
        }

        if (! $user->is_active && ! $user->isSuperAdmin()) {
            return response()->json([
                'message' => 'Votre compte a été bloqué. Contactez votre administrateur.',
                'code' => 'account_blocked',
            ], 403);
        }

        // Seul le staff est autorisé sur cette application.
        if (! $user->hasAnyRole(self::STAFF_ROLES)) {
            return response()->json([
                'message' => "Ce compte n'est pas autorisé à accéder à l'application Pro. Contactez votre administrateur.",
                'code' => 'not_staff',
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
     * Même correctif que LoginController::lierCompteParTelephone() (cf. sa docblock) :
     * le rattachement doit aussi attribuer le rôle Spatie correspondant, pas
     * seulement poser `user_id`.
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
