<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserInvitation;

class UserInvitationPolicy
{
    public function resend(User $user, UserInvitation $invitation): bool
    {
        return $user->can('users.create')
            && $user->organization_id === $invitation->organization_id;
    }

    public function delete(User $user, UserInvitation $invitation): bool
    {
        return $user->can('users.create')
            && $user->organization_id === $invitation->organization_id;
    }
}
