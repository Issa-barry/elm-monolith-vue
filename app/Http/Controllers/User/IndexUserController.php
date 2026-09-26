<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\User\UserIndexPayload;
use Inertia\Inertia;
use Inertia\Response;

class IndexUserController extends Controller
{
    public function __invoke(): Response
    {
        $this->authorize('viewAny', User::class);

        return Inertia::render('Users/Index', UserIndexPayload::build(auth()->user()));
    }
}
