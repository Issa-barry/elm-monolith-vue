<?php

namespace App\Http\Controllers;

use App\Exceptions\InvitationException;
use App\Models\Site;
use App\Models\UserInvitation;
use App\Services\UserInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserInvitationController extends Controller
{
    /**
     * POST /sites/{site}/invitations
     * Invite a new member to a site by email.
     */
    public function store(Request $request, Site $site, UserInvitationService $service): RedirectResponse
    {
        $this->authorize('invite', $site);

        $data = $request->validate([
            'email' => 'required|email|max:255',
            'role' => ['required', Rule::in(UserController::INVITABLE_ROLES)],
        ], [
            'email.required' => "L'adresse email est obligatoire.",
            'email.email' => "L'adresse email est invalide.",
            'role.required' => 'Le rôle est obligatoire.',
            'role.in' => "Ce rôle ne peut pas être attribué par invitation. Les rôles administrateur ne peuvent être accordés qu'après validation du compte, depuis la gestion des utilisateurs.",
        ]);

        try {
            $service->invite(auth()->user(), $site, $data['email'], $data['role']);
        } catch (InvitationException $e) {
            return back()->withErrors(['email' => $e->getMessage()])->withInput();
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['email' => "L'invitation n'a pas pu être envoyée pour le moment. Réessayez plus tard."])->withInput();
        }

        return back()->with('success', 'Invitation envoyée avec succès.');
    }

    /**
     * POST /invitations/{invitation}/resend
     * Resend (or renew) an expired/revoked invitation.
     */
    public function resend(UserInvitation $invitation, UserInvitationService $service): RedirectResponse
    {
        $this->authorize('resend', $invitation);

        try {
            $service->resend($invitation);
        } catch (InvitationException $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['email' => "L'invitation n'a pas pu être renvoyée pour le moment. Réessayez plus tard."]);
        }

        return back()->with('success', 'Invitation renvoyée avec succès.');
    }

    /**
     * DELETE /invitations/{invitation}
     * Revoke a pending invitation.
     */
    public function destroy(UserInvitation $invitation, UserInvitationService $service): RedirectResponse
    {
        $this->authorize('delete', $invitation);

        $service->revoke($invitation);

        return back()->with('success', 'Invitation révoquée.');
    }
}
