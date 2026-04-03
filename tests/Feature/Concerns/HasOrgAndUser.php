<?php

namespace Tests\Feature\Concerns;

use App\Models\Organization;
use App\Models\User;

trait HasOrgAndUser
{
    private Organization $org;

    private User $user;

    protected function initOrgAndUser(array $permissions): void
    {
        $this->org  = Organization::factory()->create();
        $this->user = $this->makeUserWithPermissions($this->org, $permissions);
    }
}
