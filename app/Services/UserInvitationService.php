<?php

namespace App\Services;

use App\Enums\AuditEvent;
use App\Http\Controllers\UserController;
use App\Mail\UserInvitationMail;
use App\Models\Client;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserInvitationService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * Create and send a new invitation.
     *
     * @throws \RuntimeException on duplicate or blocked email, or disallowed role
     */
    public function invite(User $inviter, Site $site, string $email, string $role): UserInvitation
    {
        $email = mb_strtolower(trim($email));

        // Défense en profondeur : ne jamais faire confiance au rôle transmis, même
        // si le contrôleur a déjà validé — les rôles admin ne s'attribuent jamais
        // par invitation, seulement après validation du compte (gestion utilisateur).
        if (in_array($role, UserController::ADMIN_ROLES, true)) {
            throw new \RuntimeException('Ce rôle ne peut pas être attribué par invitation.');
        }

        if (User::where('email', $email)->where('organization_id', $site->organization_id)->exists()) {
            throw new \RuntimeException('Cette adresse email est déjà associée à un compte utilisateur sur cette organisation.');
        }

        $existing = UserInvitation::where('email', $email)
            ->where('site_id', $site->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($existing) {
            throw new \RuntimeException('Une invitation en attente existe déjà pour cet email sur ce site. Vous pouvez la renvoyer depuis la liste des membres.');
        }

        $token = Str::random(64);

        $invitation = UserInvitation::create([
            'email' => $email,
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'invited_by' => $inviter->id,
            'expires_at' => now()->addHours(24),
        ]);

        Mail::to($email)->send(new UserInvitationMail($invitation, $token));

        $this->auditLog->record($invitation, AuditEvent::CREATED, $inviter, null, [
            'email' => $email,
            'role' => $role,
            'site_id' => $site->id,
        ]);

        return $invitation;
    }

    /**
     * Regenerate the token and resend the invitation email (works for expired/revoked).
     */
    public function resend(UserInvitation $invitation): void
    {
        $token = Str::random(64);

        $invitation->update([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours(24),
            'revoked_at' => null,
        ]);

        Mail::to($invitation->email)->send(new UserInvitationMail($invitation, $token));
    }

    /**
     * Revoke an invitation so it can no longer be accepted.
     */
    public function revoke(UserInvitation $invitation): void
    {
        $invitation->update(['revoked_at' => now()]);
    }

    /**
     * Find an invitation by its plain-text token (hashes internally).
     */
    public function findByToken(string $token): ?UserInvitation
    {
        return UserInvitation::where('token_hash', hash('sha256', $token))
            ->with(['site', 'organization'])
            ->first();
    }

    /**
     * Accept an invitation: create the user, assign role + site, mark invitation accepted.
     *
     * Le compte est toujours créé en pending_validation, quel que soit le rôle : il ne
     * devient actif qu'après validation explicite par un admin (voir UserController::validateAccount).
     */
    public function accept(UserInvitation $invitation, array $data): User
    {
        // Défense en profondeur : un rôle admin n'a jamais dû pouvoir être stocké
        // sur une invitation (voir invite()), mais on refuse quand même de créer
        // le compte si c'était le cas plutôt que de faire confiance à la donnée.
        if (in_array($invitation->role, UserController::ADMIN_ROLES, true)) {
            throw new \RuntimeException('Ce rôle ne peut pas être attribué via une invitation.');
        }

        $user = User::create([
            'prenom' => mb_convert_case(mb_strtolower($data['prenom'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
            'nom' => mb_strtoupper($data['nom'], 'UTF-8'),
            'email' => $invitation->email,
            // L'email est considéré vérifié : pour arriver ici, l'utilisateur a dû ouvrir
            // le lien d'invitation reçu à cette adresse, ce qui en prouve déjà la possession.
            'email_verified_at' => now(),
            'telephone' => $data['telephone'],
            'password' => Hash::make($data['password']),
            'organization_id' => $invitation->organization_id,
            'code_pays' => $data['code_pays'] ?? null,
            'is_active' => false,
            'status' => User::STATUS_PENDING_VALIDATION,
        ]);

        $user->assignRole($invitation->role);
        $user->sites()->attach($invitation->site_id, [
            'role' => 'employe',
            'is_default' => true,
        ]);

        if (MatriculeService::isStaffRole($invitation->role)) {
            app(MatriculeService::class)->assignForUser($user);
        }

        $invitation->update(['accepted_at' => now()]);

        $this->auditLog->record($user, AuditEvent::CREATED, null, null, [
            'role' => $invitation->role,
            'status' => User::STATUS_PENDING_VALIDATION,
            'source' => 'invitation',
            'invitation_id' => $invitation->id,
        ]);

        return $user;
    }

    /**
     * Look up prefill data (nom/prenom) from business tables using a normalized phone number.
     * Mirrors the logic in RegisterLookupController.
     */
    public function phonePrefill(string $normalizedPhone): ?array
    {
        $client = Client::where('telephone', $normalizedPhone)->whereNull('user_id')->first();
        if ($client) {
            return ['prenom' => $client->prenom, 'nom' => $client->nom];
        }

        $livreur = Livreur::where('telephone', $normalizedPhone)->first();
        if ($livreur) {
            return ['prenom' => $livreur->prenom, 'nom' => $livreur->nom];
        }

        $proprietaire = Proprietaire::where('telephone', $normalizedPhone)->whereNull('user_id')->first();
        if ($proprietaire) {
            return ['prenom' => $proprietaire->prenom, 'nom' => $proprietaire->nom];
        }

        return null;
    }
}
