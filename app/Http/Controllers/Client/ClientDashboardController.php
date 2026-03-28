<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class ClientDashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('client/Dashboard');
    }
}
