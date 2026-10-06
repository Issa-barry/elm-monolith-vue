<?php

namespace App\Http\Controllers\Depenses;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Depense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuggestionsDepenseController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Depense::class);

        $field = $request->input('field', '');
        $q = trim($request->input('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $orgId = auth()->user()->organization_id;
        $like = '%'.$q.'%';

        if ($field === 'vehicule') {
            $likeImmat = '%'.preg_replace('/[\s\-]/', '', $q).'%';

            $vehicules = Depense::where('organization_id', $orgId)
                ->where('beneficiaire_type', 'vehicule')
                ->whereHas('vehiculeBeneficiaire', fn ($qb) => $qb
                    ->where('nom_vehicule', 'LIKE', $like)
                    ->orWhereRaw("REPLACE(REPLACE(immatriculation, '-', ''), ' ', '') LIKE ?", [$likeImmat])
                )
                ->with('vehiculeBeneficiaire:id,nom_vehicule,immatriculation')
                ->get()
                ->pluck('vehiculeBeneficiaire')
                ->filter()
                ->unique('id')
                ->take(8)
                ->map(fn ($v) => [
                    'label' => $v->nom_vehicule.' — '.$v->immatriculation,
                    'value' => $v->immatriculation,
                ])
                ->values();

            return response()->json($vehicules);
        }

        if ($field === 'concerne') {
            $results = collect();

            // livreur/proprietaire : recherche par leur propre nom OU par le nom du véhicule
            // auquel ils sont rattachés (livreur → equipes.vehicule, proprietaire → vehicules) —
            // en pratique, on identifie souvent ces bénéficiaires par le véhicule plutôt que
            // par leur nom propre.
            $vehiculeRelationParType = [
                'livreur' => 'equipes.vehicule',
                'proprietaire' => 'vehicules',
            ];

            foreach (['employe' => 'employeBeneficiaire', 'livreur' => 'livreurBeneficiaire', 'proprietaire' => 'proprietaireBeneficiaire'] as $type => $relation) {
                $vehiculeRelation = $vehiculeRelationParType[$type] ?? null;

                $items = Depense::where('organization_id', $orgId)
                    ->where('beneficiaire_type', $type)
                    ->whereHas($relation, function ($qb) use ($like, $vehiculeRelation) {
                        $qb->where(fn ($q) => $q->whereHas('personne', fn ($p) => $p
                            ->where('nom', 'LIKE', $like)
                            ->orWhere('prenom', 'LIKE', $like)
                        ));

                        if ($vehiculeRelation) {
                            $qb->orWhereHas($vehiculeRelation, fn ($v) => $v->where('nom_vehicule', 'LIKE', $like));
                        }
                    })
                    ->with([$relation, $relation.'.personne'])
                    ->get()
                    ->pluck($relation)
                    ->filter()
                    ->unique('id')
                    ->take(4)
                    ->map(fn ($p) => [
                        'label' => trim($p->prenom.' '.$p->nom),
                        'value' => trim($p->prenom.' '.$p->nom),
                    ]);

                $results = $results->merge($items);
            }

            $vehicules = Depense::where('organization_id', $orgId)
                ->where('beneficiaire_type', 'vehicule')
                ->whereHas('vehiculeBeneficiaire', fn ($qb) => $qb->where('nom_vehicule', 'LIKE', $like))
                ->with('vehiculeBeneficiaire:id,nom_vehicule')
                ->get()
                ->pluck('vehiculeBeneficiaire')
                ->filter()
                ->unique('id')
                ->take(4)
                ->map(fn ($v) => [
                    'label' => $v->nom_vehicule,
                    'value' => $v->nom_vehicule,
                ]);

            $results = $results->merge($vehicules);

            // Prestataire : identité physique (Personne) OU morale (EntrepriseTierce) selon le
            // prestataire — nom_complet est un accesseur PHP, pas une colonne, donc filtré en
            // mémoire plutôt qu'en LIKE SQL (volumes bornés par organisation, cf. convention déjà
            // appliquée aux écrans Commission v2).
            $prestataires = Depense::where('organization_id', $orgId)
                ->where('beneficiaire_type', 'prestataire')
                ->with('prestataireBeneficiaire')
                ->get()
                ->pluck('prestataireBeneficiaire')
                ->filter()
                ->unique('id')
                ->filter(fn ($p) => str_contains(mb_strtolower((string) $p->nom_complet), mb_strtolower($q)))
                ->take(4)
                ->map(fn ($p) => [
                    'label' => $p->nom_complet,
                    'value' => $p->nom_complet,
                ]);

            $results = $results->merge($prestataires);

            $clients = Depense::where('organization_id', $orgId)
                ->where('beneficiaire_type', 'client')
                ->with('clientBeneficiaire:id,nom,prenom,nom_complet')
                ->get()
                ->pluck('clientBeneficiaire')
                ->filter()
                ->unique('id')
                ->filter(fn (Client $client) => str_contains(mb_strtolower($client->nom_complet), mb_strtolower($q)))
                ->take(4)
                ->map(fn (Client $client) => ['label' => $client->nom_complet, 'value' => $client->nom_complet]);

            $results = $results->merge($clients);

            return response()->json($results->unique('value')->take(8)->values());
        }

        if ($field === 'telephone_concerne') {
            $digits = preg_replace('/\D/', '', $q);
            $likeTel = '%'.($digits ?: $q).'%';
            $results = collect();

            foreach (['employe' => 'employeBeneficiaire', 'livreur' => 'livreurBeneficiaire', 'proprietaire' => 'proprietaireBeneficiaire'] as $type => $relation) {
                $items = Depense::where('organization_id', $orgId)
                    ->where('beneficiaire_type', $type)
                    ->whereHas($relation, fn ($qb) => $qb->whereHas('personne', fn ($p) => $p
                        ->whereRaw("REPLACE(REPLACE(telephone, ' ', ''), '-', '') LIKE ?", [$likeTel])
                    ))
                    ->with([$relation, $relation.'.personne'])
                    ->get()
                    ->pluck($relation)
                    ->filter()
                    ->unique('id')
                    ->take(4)
                    ->map(fn ($p) => [
                        'label' => trim($p->prenom.' '.$p->nom).' — '.$p->telephone,
                        'value' => $p->telephone,
                    ]);

                $results = $results->merge($items);
            }

            $prestataires = Depense::where('organization_id', $orgId)
                ->where('beneficiaire_type', 'prestataire')
                ->with('prestataireBeneficiaire')
                ->get()
                ->pluck('prestataireBeneficiaire')
                ->filter()
                ->unique('id')
                ->filter(fn ($p) => $p->phone && str_contains(preg_replace('/\D/', '', (string) $p->phone), $digits ?: $q))
                ->take(4)
                ->map(fn ($p) => [
                    'label' => $p->nom_complet.' — '.$p->phone,
                    'value' => $p->phone,
                ]);

            $results = $results->merge($prestataires);

            $clients = Depense::where('organization_id', $orgId)
                ->where('beneficiaire_type', 'client')
                ->with('clientBeneficiaire:id,nom,prenom,nom_complet,telephone')
                ->get()
                ->pluck('clientBeneficiaire')
                ->filter(fn (?Client $client) => $client?->telephone)
                ->unique('id')
                ->filter(fn (Client $client) => str_contains(preg_replace('/\D/', '', $client->telephone), $digits ?: $q))
                ->take(4)
                ->map(fn (Client $client) => ['label' => $client->nom_complet.' — '.$client->telephone, 'value' => $client->telephone]);

            $results = $results->merge($clients);

            return response()->json($results->unique('value')->take(8)->values());
        }

        return response()->json([]);
    }
}
