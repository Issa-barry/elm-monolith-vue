<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        abort_if(! auth()->user()->isSuperAdmin(), 403);

        $clientUserIds = Client::whereNotNull('user_id')->pluck('user_id')->flip();

        $accounts = User::orderBy('created_at', 'desc')
            ->get()
            ->map(function (User $u) use ($clientUserIds) {
                $hasStaffRole = $u->hasAnyRole(['super_admin', 'admin_entreprise', 'manager', 'commerciale', 'comptable']);

                if ($hasStaffRole) {
                    $type = 'agent';
                } elseif ($clientUserIds->has($u->id)) {
                    $type = 'client';
                } else {
                    $type = 'inscrit';
                }

                return [
                    'id' => $u->id,
                    'nom_complet' => $u->name,
                    'email' => $u->email,
                    'telephone' => $u->telephone,
                    'is_active' => $u->is_active,
                    'email_verified' => ! is_null($u->email_verified_at),
                    'type' => $type,
                    'created_at' => $u->created_at?->format('d/m/Y'),
                ];
            });

        return Inertia::render('Accounts/Index', [
            'accounts' => $accounts,
        ]);
    }

    public function toggleActive(User $user): RedirectResponse
    {
        abort_if(! auth()->user()->isSuperAdmin(), 403);
        abort_if($user->id === auth()->id(), 403, 'Vous ne pouvez pas bloquer votre propre compte.');

        $user->update(['is_active' => ! $user->is_active]);

        $action = $user->is_active ? 'débloqué' : 'bloqué';

        return back()->with('success', "{$user->name} a été {$action}.");
    }
}
