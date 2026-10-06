<?php

namespace App\Http\Controllers\Clients;

use App\Enums\ClientType;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Inertia\Inertia;
use Inertia\Response;

class CreateClientController extends Controller
{
    public function __invoke(): Response
    {
        $this->authorize('create', Client::class);

        return Inertia::render('Clients/Create', [
            'types' => ClientType::options(),
        ]);
    }
}
