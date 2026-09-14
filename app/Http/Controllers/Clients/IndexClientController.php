<?php

namespace App\Http\Controllers\Clients;

use App\Enums\ClientType;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Inertia\Inertia;
use Inertia\Response;

class IndexClientController extends Controller
{
    public function __invoke(): Response
    {
        $this->authorize('viewAny', Client::class);

        $clients = Client::where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom')
            ->get()
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'nom' => $c->nom,
                'prenom' => $c->prenom,
                'nom_complet' => $c->nom_complet,
                'email' => $c->email,
                'telephone' => $c->telephone,
                'code_phone_pays' => $c->code_phone_pays,
                'ville' => $c->ville,
                'pays' => $c->pays,
                'code_pays' => $c->code_pays,
                'adresse' => $c->adresse,
                'is_active' => $c->is_active,
                'type' => $c->type->value,
                'type_label' => $c->type->label(),
                'cashback_eligible' => $c->cashback_eligible,
                'cashback_montant_par_pack' => $c->cashback_montant_par_pack,
            ]);

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
            'types' => ClientType::options(),
        ]);
    }
}
