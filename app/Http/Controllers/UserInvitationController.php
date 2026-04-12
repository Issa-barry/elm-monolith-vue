<?php

namespace App\Http\Controllers;

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
            'role' => ['required', Rule::in(UserController::STAFF_ROLES)],
        ], [
            'email.required' => "L'adresse email est obligatoire.",
            'email.email' => "L'adresse email est invalide.",
            'role.required' => 'Le rôle est obligatoire.',
            'role.in' => 'Rôle invalide.',
        ]);

        try {
            $service->invite(auth()->user(), $site, $data['email'], $data['role']);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['email' => $e->getMessage()])->withInput();
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

        $service->resend($invitation);

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
