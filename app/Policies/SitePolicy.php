<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;

class SitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sites.read');
    }

    public function view(User $user, Site $site): bool
    {
        return $user->can('sites.read')
            && $this->sameOrganization($user, $site);
    }

    public function create(User $user): bool
    {
        return $user->can('sites.create');
    }

    public function update(User $user, Site $site): bool
    {
        return $user->can('sites.update')
            && $this->sameOrganization($user, $site);
    }

    public function delete(User $user, Site $site): bool
    {
        return $user->can('sites.delete')
            && $this->sameOrganization($user, $site);
    }

    private function sameOrganization(User $user, Site $site): bool
    {
        return $user->organization_id === $site->organization_id;
    }
}
