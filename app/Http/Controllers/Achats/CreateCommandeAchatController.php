<?php

namespace App\Http\Controllers\Achats;

use App\Http\Controllers\Controller;
use App\Models\CommandeAchat;
use App\Support\Achats\CommandeAchatFormOptions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreateCommandeAchatController extends Controller
{
    public function __invoke(Request $request, CommandeAchatFormOptions $options): Response
    {
        $this->authorize('create', CommandeAchat::class);

        return Inertia::render('Achats/Form', [
            'commande' => null,
            ...$options->pour($request->user()),
        ]);
    }
}
