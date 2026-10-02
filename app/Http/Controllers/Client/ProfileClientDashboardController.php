<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Client\ClientDashboardPayloadBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileClientDashboardController extends Controller
{
    public function __construct(
        private readonly ClientDashboardPayloadBuilder $payloadBuilder,
    ) {}

    public function __invoke(Request $request): Response
    {
        $payload = $this->payloadBuilder->build($request->user());
        $user = $request->user();

        return Inertia::render('client/Profile', [
            'actor' => $payload['actor'],
            'profile' => [
                'full_name' => $user->name,
                'telephone' => $user->telephone,
                'email' => $user->email,
                'member_since_label' => $user->created_at?->translatedFormat('d F Y'),
                'roles' => $user->getRoleNames()->values()->all(),
                'vehicules_count' => count($payload['vehicules']),
                'operations_count' => $payload['earnings']['operations_count'],
            ],
        ]);
    }
}
