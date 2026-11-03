<?php

namespace App\Services\Client;

use App\Enums\OtpChannel;
use App\Mail\ConfirmationEmailProfilMail;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Support\Clients\ClientUniqueness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Changement d'e-mail depuis l'espace client (ADR 0051). Rien n'est écrit tant que le
 * lien n'a pas été ouvert : une identité e-mail non vérifiée bloquerait la prochaine
 * connexion (AccountEligibility), et une adresse non prouvée ne doit recevoir ni code
 * OTP ni lien de réinitialisation. La nouvelle adresse voyage dans le lien signé, sans
 * état intermédiaire en base.
 */
class ChangementEmailProfilService
{
    private const VALIDITE_HEURES = 24;

    private const ENVOIS_MAX_PAR_HEURE = 5;

    public function __construct(private readonly ClientIdentityResolver $identityResolver) {}

    /**
     * @return bool false si l'envoi a échoué : le reste du profil n'en dépend pas.
     *
     * @throws ValidationException adresse déjà prise ou trop de demandes
     */
    public function demander(User $user, ClientIdentity $identity, string $email): bool
    {
        $this->assertDisponible($user, $identity, $email);

        $cle = 'profil-email:'.$user->id;
        if (RateLimiter::tooManyAttempts($cle, self::ENVOIS_MAX_PAR_HEURE)) {
            throw ValidationException::withMessages([
                'email' => 'Trop de demandes de changement d\'adresse. Réessayez dans une heure.',
            ]);
        }
        RateLimiter::hit($cle, 3600);

        $chemin = URL::temporarySignedRoute('api.auth.confirm-email', now()->addHours(self::VALIDITE_HEURES), [
            'userId' => $user->id,
            'email' => $email,
            'empreinte' => $this->empreinte($user),
        ], absolute: false);

        try {
            Mail::to($email)->send(new ConfirmationEmailProfilMail($user, url($chemin), self::VALIDITE_HEURES));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** @throws ValidationException adresse prise entre la demande et la confirmation */
    public function confirmer(User $user, string $email): void
    {
        $identity = $this->identityResolver->resolve($user);
        $this->assertDisponible($user, $identity, $email);

        DB::transaction(function () use ($user, $identity, $email) {
            $normalise = UserAuthIdentity::normaliser(UserAuthIdentity::TYPE_EMAIL, $email);
            $authIdentity = $user->emailIdentity();

            if ($authIdentity) {
                $authIdentity->update([
                    'value' => $email,
                    'normalized_value' => $normalise,
                    'verification_token' => null,
                    'verification_expires_at' => null,
                ]);
            } else {
                $authIdentity = $user->authIdentities()->create([
                    'type' => UserAuthIdentity::TYPE_EMAIL,
                    'value' => $email,
                    'normalized_value' => $normalise,
                ]);
            }

            $authIdentity->markVerifiedVia(OtpChannel::EMAIL);

            $identity->personnesDu($user)->each->update(['email' => $email]);
            $identity->client?->update(['email' => $email]);
        });

        $user->unsetRelation('authIdentities');
    }

    /**
     * Lie le lien à l'adresse actuelle et à sa date de vérification : tout changement ultérieur
     * rend les anciens liens caducs, même si le compte revient ensuite à cette même adresse.
     */
    public function empreinte(User $user): string
    {
        return hash('sha256', $user->email.'|'.$user->email_verified_at?->timestamp);
    }

    private function assertDisponible(User $user, ClientIdentity $identity, string $email): void
    {
        $existant = UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_EMAIL, $email);
        if ($existant && $existant->id !== $user->id) {
            throw ValidationException::withMessages(['email' => 'Cette adresse e-mail est déjà utilisée.']);
        }

        if ($identity->client) {
            ClientUniqueness::assertEmailUniqueInOrg($email, $identity->client->organization_id, $identity->client->id);
        }
    }
}
