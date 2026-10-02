<?php

namespace App\Http\Controllers\Role;

use App\Http\Controllers\Controller;
use App\Support\Permissions\RoleAccess;
use Inertia\Inertia;
use Inertia\Response;

class CreateRoleController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(RoleAccess::canManageRoles(), 403);

        return Inertia::render('Roles/Create');
    }
}
