<?php

namespace App\Http\Controllers\UserInvitation;

use App\Http\Controllers\Controller;
use App\Models\UserInvitation;
use App\Services\UserInvitationService;
use Illuminate\Http\RedirectResponse;

class DestroyUserInvitationController extends Controller
{
    /**
     * DELETE /invitations/{invitation}
     * Revoke a pending invitation.
     */
    public function __invoke(UserInvitation $invitation, UserInvitationService $service): RedirectResponse
    {
        $this->authorize('delete', $invitation);

        $service->revoke($invitation);

        return back()->with('success', 'Invitation révoquée.');
    }
}
