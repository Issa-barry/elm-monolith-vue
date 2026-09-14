<?php

namespace App\Http\Controllers\User;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;

class RejectAccountUserController extends Controller
{
    /**
     * PATCH /users/{user}/reject
     * Refuse un compte en attente : il reste bloqué (inactif).
     */
    public function __invoke(User $user, AuditLogService $auditLog): RedirectResponse
    {
        $this->authorize('update', $user);

        abort_unless($user->isPendingValidation(), 422, "Ce compte n'est pas en attente de validation.");

        $user->update([
            'status' => User::STATUS_INACTIVE,
            'is_active' => false,
        ]);

        $auditLog->record($user, AuditEvent::REJECTED, auth()->user());

        return back()->with('success', "{$user->name} a été refusé.");
    }
}
