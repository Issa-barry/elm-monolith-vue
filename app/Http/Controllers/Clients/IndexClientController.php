<?php

namespace App\Http\Controllers\Clients;

use App\Enums\ClientType;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class IndexClientController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $this->authorize('viewAny', Client::class);

        $data = $request->validate([
            'type' => ['nullable', Rule::enum(ClientType::class)],
            'cashback' => ['nullable', Rule::in(['eligible', 'non_eligible'])],
            'statut' => ['nullable', Rule::in(['actif', 'inactif'])],
            'recherche' => ['nullable', 'string', 'max:255'],
        ]);
        $filters = [
            'type' => $data['type'] ?? '',
            'cashback' => $data['cashback'] ?? '',
            'statut' => $data['statut'] ?? '',
            'recherche' => trim($data['recherche'] ?? ''),
        ];

        $query = Client::where('organization_id', $request->user()->organization_id);
        if ($filters['type'] !== '') {
            $query->where('type', $filters['type']);
        }
        if ($filters['cashback'] !== '') {
            $query->where('cashback_eligible', $filters['cashback'] === 'eligible');
        }
        if ($filters['statut'] !== '') {
            $query->where('is_active', $filters['statut'] === 'actif');
        }
        if ($filters['recherche'] !== '') {
            foreach (preg_split('/\s+/u', $filters['recherche']) as $mot) {
                $query->where(function ($q) use ($mot) {
                    foreach (['nom_complet', 'nom', 'prenom', 'email', 'telephone', 'adresse', 'ville', 'pays'] as $champ) {
                        $q->orWhere($champ, 'like', '%'.$mot.'%');
                    }
                });
            }
        }

        $clients = $query
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
            'filters' => $filters,
        ]);
    }
}
