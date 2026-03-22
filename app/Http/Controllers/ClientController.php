<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Client::class);

        $clients = Client::where('organization_id', auth()->user()->organization_id)
            ->paginate(25);

        return Inertia::render('Clients/Index', compact('clients'));
    }

    public function show(Client $client): Response
    {
        $this->authorize('view', $client);

        return Inertia::render('Clients/Show', compact('client'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Client::class);

        $data = $request->validate([
            'nom'       => 'required|string|max:255',
            'prenom'    => 'required|string|max:255',
            'email'     => 'nullable|email|max:255',
            'telephone' => 'nullable|string|max:20',
            'adresse'   => 'nullable|string',
        ]);

        Client::create([
            ...$data,
            'organization_id' => auth()->user()->organization_id,
        ]);

        return redirect()->route('clients.index');
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'nom'       => 'sometimes|string|max:255',
            'prenom'    => 'sometimes|string|max:255',
            'email'     => 'nullable|email|max:255',
            'telephone' => 'nullable|string|max:20',
            'adresse'   => 'nullable|string',
        ]);

        $client->update($data);

        return redirect()->route('clients.index');
    }

    public function destroy(Client $client): RedirectResponse
    {
        $this->authorize('delete', $client);

        $client->delete();

        return redirect()->route('clients.index');
    }
}
