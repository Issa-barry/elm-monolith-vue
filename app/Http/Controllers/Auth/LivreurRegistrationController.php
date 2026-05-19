<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\User;
use App\Services\OtpService;
use App\Services\PhoneNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class LivreurRegistrationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/RegisterLivreur');
    }

    public function store(Request $request, OtpService $otp): RedirectResponse
    {
        $validated = $request->validate([
            'prenom'    => ['required', 'string', 'min:2', 'max:100'],
            'nom'       => ['required', 'string', 'min:2', 'max:100'],
            'telephone' => ['required', 'string'],
            'telephone_country' => ['required', 'string'],
            'telephone_local'   => ['required', 'string', 'regex:/^\d+$/'],
            'password'  => ['required', 'string', Password::default()],
        ]);

        $phone = PhoneNormalizer::normalize($validated['telephone']);

        if ($phone === null) {
            throw ValidationException::withMessages(['telephone' => 'Numéro de téléphone invalide.']);
        }

        if (User::where('telephone', $phone)->exists()) {
            throw ValidationException::withMessages(['telephone' => 'Ce numéro est déjà associé à un compte. Connectez-vous ou réinitialisez votre mot de passe.']);
        }

        if (! $otp->isVerified($phone)) {
            throw ValidationException::withMessages(['telephone' => 'La vérification par code OTP est requise.']);
        }

        $user = DB::transaction(function () use ($validated, $phone, $otp) {
            $org = Organization::first();

            $user = User::create([
                'prenom'          => self::formatPrenom($validated['prenom']),
                'nom'             => mb_strtoupper($validated['nom']),
                'telephone'       => $phone,
                'password'        => $validated['password'],
                'organization_id' => $org?->id,
            ]);

            Role::firstOrCreate(['name' => 'livreur', 'guard_name' => 'web']);
            $user->assignRole('livreur');

            // Lier à un livreur pré-existant sans compte, sinon créer (inactif jusqu'à validation admin)
            $existing = $org
                ? Livreur::where('telephone', $phone)->where('organization_id', $org->id)->whereNull('user_id')->first()
                : null;

            if ($existing) {
                $existing->update(['user_id' => $user->id]);
            } else {
                Livreur::create([
                    'organization_id' => $org?->id,
                    'user_id'         => $user->id,
                    'nom'             => mb_strtoupper($validated['nom']),
                    'prenom'          => self::formatPrenom($validated['prenom']),
                    'telephone'       => $phone,
                    'is_active'       => false,
                ]);
            }

            $otp->clear($phone);

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
