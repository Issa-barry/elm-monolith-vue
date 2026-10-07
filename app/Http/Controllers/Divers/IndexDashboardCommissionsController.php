<?php

namespace App\Http\Controllers\Divers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IndexDashboardCommissionsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort_unless($request->user()->canReadCommissions(), 403);

        return Inertia::render('DashboardCommissions');
    }
}
