<?php

namespace App\Policies;

use App\Models\OptionCatalogue;
use App\Models\User;

class OptionCataloguePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('options.read');
    }

    public function view(User $user, OptionCatalogue $option): bool
    {
        return $user->can('options.read')
            && $user->organization_id === $option->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->can('options.create');
    }

    public function update(User $user, OptionCatalogue $option): bool
    {
        return $user->can('options.update')
            && $user->organization_id === $option->organization_id;
    }

    public function delete(User $user, OptionCatalogue $option): bool
    {
        return $user->can('options.delete')
            && $user->organization_id === $option->organization_id;
    }
}
