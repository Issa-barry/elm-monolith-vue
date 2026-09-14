<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class ToggleActiveAccountController extends Controller
{
    public function __invoke(User $user): RedirectResponse
    {
        abort_if(! auth()->user()->isSuperAdmin(), 403);
        abort_if($user->id === auth()->id(), 403, 'Vous ne pouvez pas bloquer votre propre compte.');

        $user->update(['is_active' => ! $user->is_active]);

        $action = $user->is_active ? 'débloqué' : 'bloqué';

        return back()->with('success', "{$user->name} a été {$action}.");
    }
}
