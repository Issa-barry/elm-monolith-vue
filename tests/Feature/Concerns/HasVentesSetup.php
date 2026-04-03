<?php

namespace Tests\Feature\Concerns;

use App\Models\Organization;
use App\Models\User;

trait HasVentesSetup
{
    private function userWithPermissions(Organization $org): User
    {
        return $this->makeUserWithPermissions($org, ['ventes.read']);
    }
}
