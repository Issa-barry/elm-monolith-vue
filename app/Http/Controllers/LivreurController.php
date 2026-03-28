<?php

namespace App\Http\Controllers;

use App\Models\Livreur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

const LIVREUR_PAYS = [
    'GN' => ['Guinée',               '+224'],
    'GW' => ['Guinée-Bissau',        '+245'],
    'SN' => ['Sénégal',              '+221'],
    'ML' => ['Mali',                 '+223'],
    'CI' => ["Côte d'Ivoire",        '+225'],
    'LR' => ['Liberia',              '+231'],
    'SL' => ['Sierra Leone',         '+232'],
    'FR' => ['France',               '+33'],
    'CN' => ['Chine',                '+86'],
    'AE' => ['Émirats arabes unis',  '+971'],
    'IN' => ['Inde',                 '+91'],
];

class LivreurController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Livreur::class);

        $livreurs = Livreur::where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom')
            ->get()
            ->map(fn (Livreur $l) => [
                'id'         => $l->id,
                'nom'        => $l->nom,
                'prenom'     => $l->prenom,
                'nom_complet'=> trim("{$l->prenom} {$l->nom}"),
                'email'      => $l->email,
                'telephone'  => $l->telephone,
                'code_phone_pays' => $l->code_phone_pays,
                'ville'      => $l->ville,
                'pays'       => $l->pays,
                'code_pays'  => $l->code_pays,
                'is_active'  => $l->is_active,
            ]);

        return Inertia::render('Livreurs/Index', [
            'livreurs' => $livreurs,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Livreur::class);

        return Inertia::render('Livreurs/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Livreur::class);

        $orgId = auth()->user()->organization_id;
        abort_if(!$orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'nom'       => 'required|string|max:255',
            'prenom'    => 'required|string|max:255',
            'email'     => 'nullable|email:rfc,dns|max:255',
            'telephone' => ['nullable', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'code_pays' => ['nullable', Rule::in(array_keys(LIVREUR_PAYS))],
            'ville'     => 'nullable|string|max:100',
            'adresse'   => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ], [
            'nom.required'    => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'email.email'     => "L'adresse email est invalide.",
            'telephone.regex' => 'Le numéro de téléphone est invalide.',
            'code_pays.in'    => 'Pays invalide.',
        ]);

        if (!empty($data['code_pays']) && isset(LIVREUR_PAYS[$data['code_pays']])) {
            [$data['pays'], $data['code_phone_pays']] = LIVREUR_PAYS[$data['code_pays']];
        }

        $data = $this->normalizeData($data);

        Livreur::create([...$data, 'organization_id' => $orgId]);

        return redirect()->route('livreurs.index')
            ->with('success', 'Livreur créé avec succès.');
    }

    public function edit(Livreur $livreur): Response
    {
        $this->authorize('update', $livreur);

        return Inertia::render('Livreurs/Edit', [
            'livreur' => [
                'id'              => $livreur->id,
                'nom'             => $livreur->nom,
                'prenom'          => $livreur->prenom,
                'email'           => $livreur->email,
                'telephone'       => $livreur->telephone,
                'adresse'         => $livreur->adresse,
                'ville'           => $livreur->ville,
                'pays'            => $livreur->pays,
                'code_pays'       => $livreur->code_pays,
                'code_phone_pays' => $livreur->code_phone_pays,
                'is_active'       => $livreur->is_active,
            ],
        ]);
    }

    public function update(Request $request, Livreur $livreur): RedirectResponse
    {
        $this->authorize('update', $livreur);

        $data = $request->validate([
            'nom'       => 'required|string|max:255',
            'prenom'    => 'required|string|max:255',
            'email'     => 'nullable|email:rfc,dns|max:255',
            'telephone' => ['nullable', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'code_pays' => ['nullable', Rule::in(array_keys(LIVREUR_PAYS))],
            'ville'     => 'nullable|string|max:100',
            'adresse'   => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ], [
            'nom.required'    => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'email.email'     => "L'adresse email est invalide.",
            'telephone.regex' => 'Le numéro de téléphone est invalide.',
            'code_pays.in'    => 'Pays invalide.',
        ]);

        if (!empty($data['code_pays']) && isset(LIVREUR_PAYS[$data['code_pays']])) {
            [$data['pays'], $data['code_phone_pays']] = LIVREUR_PAYS[$data['code_pays']];
        }

        $data = $this->normalizeData($data);

        $livreur->update($data);

        return redirect()->route('livreurs.index')
            ->with('success', 'Livreur mis à jour avec succès.');
    }

    private function normalizeData(array $data): array
    {
        if (!empty($data['nom'])) {
            $data['nom'] = mb_strtoupper($data['nom'], 'UTF-8');
        }
        if (!empty($data['prenom'])) {
            $data['prenom'] = mb_convert_case(mb_strtolower($data['prenom'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }
        if (!empty($data['ville'])) {
            $data['ville'] = mb_convert_case(mb_strtolower($data['ville'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }
        return $data;
    }

    public function destroy(Livreur $livreur): RedirectResponse
    {
        $this->authorize('delete', $livreur);
        $livreur->delete();

        return redirect()->route('livreurs.index')
            ->with('success', 'Livreur supprimé.');
    }
}
