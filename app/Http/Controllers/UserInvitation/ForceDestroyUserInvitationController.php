<?php

namespace App\Http\Controllers\UserInvitation;

use App\Exceptions\InvitationException;
use App\Http\Controllers\Controller;
use App\Models\UserInvitation;
use App\Services\UserInvitationService;
use Illuminate\Http\RedirectResponse;

class ForceDestroyUserInvitationController extends Controller
{
    /**
     * DELETE /invitations/{invitation}/force
     * Permanently delete an already revoked/expired invitation.
     */
    public function __invoke(UserInvitation $invitation, UserInvitationService $service): RedirectResponse
    {
        $this->authorize('delete', $invitation);

        try {
            $service->delete($invitation);
        } catch (InvitationException $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        return back()->with('success', 'Invitation supprimée définitivement.');
    }
}
