<?php

namespace App\Http\Controllers\Settings\Password;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class EditPasswordController extends Controller
{
    /**
     * Show the user's password settings page.
     */
    public function __invoke(): Response
    {
        return Inertia::render('settings/Password');
    }
}
