<?php

namespace App\Http\Controllers\Auth\ForcePasswordChange;

use App\Actions\Fortify\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Support\AuthRedirects;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UpdateForcePasswordChangeController extends Controller
{
    use PasswordValidationRules;

    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->must_change_password, 403);

        Validator::make($request->all(), [
            'password' => $this->passwordRules(),
        ])->validate();

        $request->user()->forceFill([
            'password' => $request->input('password'),
            'must_change_password' => false,
        ])->save();

        return redirect(AuthRedirects::defaultPathForUser($request->user()))
            ->with('success', 'Mot de passe défini avec succès.');
    }
}
