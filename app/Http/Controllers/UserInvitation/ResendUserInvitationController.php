<?php

namespace App\Http\Controllers\UserInvitation;

use App\Exceptions\InvitationException;
use App\Http\Controllers\Controller;
use App\Models\UserInvitation;
use App\Services\UserInvitationService;
use Illuminate\Http\RedirectResponse;

class ResendUserInvitationController extends Controller
{
    /**
     * POST /invitations/{invitation}/resend
     * Resend (or renew) an expired/revoked invitation.
     */
    public function __invoke(UserInvitation $invitation, UserInvitationService $service): RedirectResponse
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
}
