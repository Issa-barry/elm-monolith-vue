<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Services\OtpService;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Miroir de App\Http\Controllers\Auth\LivreurRegistrationController::store pour
 * l'app vitrine — avec une différence délibérée : PAS de Auth::login() ni de
 * redirection. Ceux-ci connecteraient le serveur de la vitrine (l'appelant),
 * pas le navigateur du visiteur — un bug silencieux sinon, puisque cet
 * endpoint est appelé server-to-server. On renvoie un simple succès JSON ;
 * la vitrine affiche sa propre page de confirmation (compte créé, en attente
 * de validation, se connecter sur fello.eau-la-maman.com une fois validé).
 */
class LivreurRegistrationController extends Controller
{
    public function __invoke(Request $request, OtpService $otp): JsonResponse
    {
        $validated = $request->validate([
            'prenom' => ['required', 'string', 'min:2', 'max:100'],
            'nom' => ['required', 'string', 'min:2', 'max:100'],
            'telephone' => ['required', 'string'],
            'telephone_country' => ['required', 'string'],
            'telephone_local' => ['required', 'string', 'regex:/^\d+$/'],
            'password' => ['required', 'string', Password::default()],
        ]);

        $phone = PhoneNormalizer::normalize($validated['telephone']);

        if ($phone === null) {
            throw ValidationException::withMessages(['telephone' => 'Numéro de téléphone invalide.']);
        }

        $normalise = Personne::normaliserTelephone($phone);

        if (UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, $normalise) !== null) {
            throw ValidationException::withMessages(['telephone' => 'Ce numéro est déjà associé à un compte. Connectez-vous ou réinitialisez votre mot de passe.']);
        }

        if (! $otp->isVerified($phone)) {
            throw ValidationException::withMessages(['telephone' => 'La vérification par code OTP est requise.']);
        }

        DB::transaction(function () use ($validated, $phone, $normalise, $otp) {
            $org = Organization::first();

            $nomComplet = trim(self::formatPrenom($validated['prenom']).' '.mb_strtoupper($validated['nom']));

            $existing = $org
                ? Livreur::where('organization_id', $org->id)
                    ->whereNull('user_id')
                    ->whereHas('personne', fn ($q) => $q->where('telephone_normalise', $normalise))
                    ->first()
                : null;

            $personne = $existing
                ? $existing->personne
                : Personne::create([
                    'organization_id' => $org?->id,
                    'prenom' => self::formatPrenom($validated['prenom']),
                    'nom' => mb_strtoupper($validated['nom']),
                    'telephone' => $phone,
                    'telephone_normalise' => $normalise,
                ]);

            $user = User::create([
                'personne_id' => $personne->id,
                'password' => $validated['password'],
                'organization_id' => $org?->id,
            ]);
            $user->authIdentities()->create([
                'type' => UserAuthIdentity::TYPE_TELEPHONE,
                'value' => $phone,
                'normalized_value' => $normalise,
                'verified_at' => now(),
                'is_primary' => true,
            ]);

            Role::firstOrCreate(['name' => 'livreur', 'guard_name' => 'web']);
            $user->assignRole('livreur');

            if ($existing) {
                $existing->update([
                    'user_id' => $user->id,
                    // Renseigne le nom d'affichage si absent — sans écraser
                    // un surnom éventuellement déjà saisi côté équipe.
                    'nom_complet' => $existing->nom_complet ?? $nomComplet,
                ]);
            } else {
                Livreur::create([
                    'organization_id' => $org?->id,
                    'user_id' => $user->id,
                    'personne_id' => $personne->id,
                    'nom_complet' => $nomComplet,
                    'is_active' => false,
                ]);
            }

            $otp->clear($phone);
        });

        return response()->json([
            'status' => 'pending_validation',
            'message' => 'Votre compte a été créé et est en attente de validation par notre équipe.',
        ]);
    }

    private static function formatPrenom(string $value): string
    {
        $lower = mb_strtolower($value, 'UTF-8');

        return preg_replace_callback(
            '/(^|[\s-])(\pL)/u',
            fn ($m) => $m[1].mb_strtoupper($m[2], 'UTF-8'),
            $lower,
        ) ?? $lower;
    }
}
