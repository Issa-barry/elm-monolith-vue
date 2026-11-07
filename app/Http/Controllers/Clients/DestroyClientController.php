<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Support\Contacts\SuppressionFonction;
use Illuminate\Http\RedirectResponse;

class DestroyClientController extends Controller
{
    public function __invoke(Client $client): RedirectResponse
    {
        $this->authorize('delete', $client);

        // D17 (ADR 0060) : un client référencé par une opération se désactive, il ne se supprime plus.
        if (! SuppressionFonction::estSupprimable($client)) {
            return back()->with('error', SuppressionFonction::message($client));
        }

        $client->delete();

        return redirect()->route('clients.index')
            ->with('success', 'Client supprimé.');
    }
}
