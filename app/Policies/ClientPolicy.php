<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('clients.read');
    }

    public function view(User $user, Client $client): bool
    {
        return $user->can('clients.read')
            && $this->sameOrganization($user, $client);
    }

    public function create(User $user): bool
    {
        return $user->can('clients.create');
    }

    public function update(User $user, Client $client): bool
    {
        return $user->can('clients.update')
            && $this->sameOrganization($user, $client);
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->can('clients.delete')
            && $this->sameOrganization($user, $client);
    }

    private function sameOrganization(User $user, Client $client): bool
    {
        return $user->organization_id === $client->organization_id;
    }
}
