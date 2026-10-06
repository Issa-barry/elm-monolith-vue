<?php

namespace App\Http\Controllers\Auth\AcceptInvitation;

use App\Http\Controllers\Controller;
use App\Services\UserInvitationService;
use App\Support\Auth\AcceptInvitationStates;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShowAcceptInvitationController extends Controller
{
    /**
     * GET /invitations/accept/{token}
     * Render the onboarding stepper page (or an error state).
     */
    public function __invoke(Request $request, string $token, UserInvitationService $service): Response
    {
        // État affiché juste après la création du compte : ce n'est pas une erreur,
        // donc vérifié avant invitationErrorState() (qui verrait sinon "already_accepted").
        if ((string) $request->query('state', '') === 'pending_validation') {
            return Inertia::render('Invitations/Accept', ['pending_validation' => true]);
        }

        $invitation = $service->findByToken($token);

        $error = AcceptInvitationStates::invitationErrorState($invitation)
            ?? $this->queryErrorState((string) $request->query('state', ''));

        if ($error !== null) {
            return Inertia::render('Invitations/Accept', ['error' => $error]);
        }

        return Inertia::render('Invitations/Accept', [
            'token' => $token,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'site_type_label' => $invitation->site->type_label,
            'site_nom' => $invitation->site->nom,
        ]);
    }

    private function queryErrorState(string $state): ?string
    {
        return in_array($state, [
            'already_authenticated',
            'not_found',
            'already_accepted',
            'revoked',
            'expired',
        ], true) ? $state : null;
    }
}
