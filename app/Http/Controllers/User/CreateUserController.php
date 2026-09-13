<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\User\UserFormOptions;
use Inertia\Inertia;
use Inertia\Response;

class CreateUserController extends Controller
{
    public function __invoke(): Response
    {
        $this->authorize('create', User::class);

        $user = auth()->user();
        $orgId = $user->organization_id;

        return Inertia::render('Users/Create', [
            'roles' => UserFormOptions::getRoleOptions($orgId, $user->isSuperAdmin()),
            'sites' => UserFormOptions::getSiteOptions($orgId),
        ]);
    }
}
