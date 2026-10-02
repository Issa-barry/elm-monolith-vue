<?php

namespace App\Http\Controllers\Depenses;

use App\Http\Controllers\Controller;
use App\Models\Depense;
use App\Models\Vehicule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehiculeDetailDepenseController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Depense::class);

        $id = $request->query('id');
        $orgId = auth()->user()->organization_id;

        $vehicule = Vehicule::where('organization_id', $orgId)
            ->with(['typeVehicule:id,nom', 'proprietaire:id,personne_id', 'proprietaire.personne', 'site:id,nom'])
            ->find($id, ['id', 'nom_vehicule', 'immatriculation', 'type_vehicule_id', 'proprietaire_id', 'site_id', 'categorie']);

        if (! $vehicule) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json([
            'nom' => $vehicule->nom_vehicule,
            'immatriculation' => $vehicule->immatriculation,
            'type' => $vehicule->typeVehicule?->nom ?? '—',
            'proprietaire' => $vehicule->proprietaire
                ? trim("{$vehicule->proprietaire->prenom} {$vehicule->proprietaire->nom}")
                : '—',
            'site' => $vehicule->site?->nom ?? '—',
            // Propriété réelle du véhicule — plus jamais reconstruite depuis
            // livraison_logistique (confusion usage/propriété corrigée, cf. Vehicule::categorie).
            'categorie' => $vehicule->categorie->value,
        ]);
    }
}
