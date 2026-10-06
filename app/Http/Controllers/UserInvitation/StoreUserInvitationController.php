<?php

namespace App\Http\Controllers\UserInvitation;

use App\Exceptions\InvitationException;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\UserInvitationService;
use App\Support\User\UserFormOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreUserInvitationController extends Controller
{
    /**
     * POST /sites/{site}/invitations
     * Invite a new member to a site by email.
     */
    public function __invoke(Request $request, Site $site, UserInvitationService $service): RedirectResponse
    {
        $this->authorize('invite', $site);

        $data = $request->validate([
            'email' => 'required|email|max:255',
            'role' => ['required', Rule::in(UserFormOptions::INVITABLE_ROLES)],
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
}
