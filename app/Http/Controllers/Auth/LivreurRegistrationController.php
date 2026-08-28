<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Services\OtpService;
use App\Services\PhoneNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class LivreurRegistrationController extends Controller
{
    public function store(Request $request, OtpService $otp): RedirectResponse
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

        if (! $otp->isVerified($phone, OtpPurpose::PHONE_VERIFICATION)) {
            throw ValidationException::withMessages(['telephone' => 'La vérification par code OTP est requise.']);
        }

        $user = DB::transaction(function () use ($validated, $phone, $normalise, $otp) {
            $org = Organization::first();

            $nomComplet = trim(self::formatPrenom($validated['prenom']).' '.mb_strtoupper($validated['nom']));

            // Lier à un livreur pré-existant sans compte, sinon créer (inactif jusqu'à
            // validation admin) — cherché AVANT de créer la Personne, pour réutiliser la
            // sienne plutôt que d'en créer une nouvelle pour la même personne physique.
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
            // Le code OTP de cette inscription n'est aujourd'hui délivré par AUCUN
            // canal réel (cf. RegisterLookupController — MVP sans fournisseur
            // SMS/WhatsApp) : `verified_at` reste donc NULL. Corrigé le 27/08/2026 —
            // cette identité était auparavant marquée vérifiée à tort du seul fait
            // que le code OTP avait été validé (souvent via OTP_FIXED_CODE en
            // local/tests), sans jamais prouver la possession réelle du numéro.
            $user->authIdentities()->create([
                'type' => UserAuthIdentity::TYPE_TELEPHONE,
                'value' => $phone,
                'normalized_value' => $normalise,
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

            $otp->clear($phone, OtpPurpose::PHONE_VERIFICATION);

            return $user;
        });

        Auth::login($user);

        return redirect()->route('client.pending');
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
