<?php
namespace App\Http\Controllers;

use App\Models\Proprietaire;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

const PROPRIETAIRE_PAYS = [
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

class ProprietaireController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Proprietaire::class);

        $proprietaires = Proprietaire::where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom')
            ->get()
            ->map(fn (Proprietaire $p) => [
                'id'         => $p->id,
                'nom'        => $p->nom,
                'prenom'     => $p->prenom,
                'nom_complet'=> trim("{$p->prenom} {$p->nom}"),
                'email'      => $p->email,
                'telephone'  => $p->telephone,
                'adresse'    => $p->adresse,
                'is_active'  => $p->is_active,
            ]);

        return Inertia::render('Proprietaires/Index', [
            'proprietaires' => $proprietaires,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Proprietaire::class);

        return Inertia::render('Proprietaires/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Proprietaire::class);

        $orgId = auth()->user()->organization_id;
        abort_if(!$orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'nom'       => 'required|string|max:255',
            'prenom'    => 'required|string|max:255',
            'email'     => 'nullable|email:rfc,dns|max:255',
            'telephone' => ['nullable', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'code_pays' => ['nullable', Rule::in(array_keys(PROPRIETAIRE_PAYS))],
            'ville'     => 'nullable|string|max:100',
            'adresse'   => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ], [
            'nom.required'      => 'Le nom est obligatoire.',
            'prenom.required'   => 'Le prénom est obligatoire.',
            'email.email'       => "L'adresse email est invalide.",
            'telephone.regex'   => 'Le numéro de téléphone est invalide.',
            'code_pays.in'      => 'Pays invalide.',
        ]);

        if (!empty($data['code_pays']) && isset(PROPRIETAIRE_PAYS[$data['code_pays']])) {
            [$data['pays'], $data['code_phone_pays']] = PROPRIETAIRE_PAYS[$data['code_pays']];
        }

        $data = $this->normalizeData($data);

        Proprietaire::create([...$data, 'organization_id' => $orgId]);

        return redirect()->route('proprietaires.index')
            ->with('success', 'Propriétaire créé avec succès.');
    }

    public function edit(Proprietaire $proprietaire): Response
    {
        $this->authorize('update', $proprietaire);

        return Inertia::render('Proprietaires/Edit', [
            'proprietaire' => [
                'id'              => $proprietaire->id,
                'nom'             => $proprietaire->nom,
                'prenom'          => $proprietaire->prenom,
                'email'           => $proprietaire->email,
                'telephone'       => $proprietaire->telephone,
                'adresse'         => $proprietaire->adresse,
                'ville'           => $proprietaire->ville,
                'pays'            => $proprietaire->pays,
                'code_pays'       => $proprietaire->code_pays,
                'code_phone_pays' => $proprietaire->code_phone_pays,
                'is_active'       => $proprietaire->is_active,
            ],
        ]);
    }

    public function update(Request $request, Proprietaire $proprietaire): RedirectResponse
    {
        $this->authorize('update', $proprietaire);

        $data = $request->validate([
            'nom'       => 'required|string|max:255',
            'prenom'    => 'required|string|max:255',
            'email'     => 'nullable|email:rfc,dns|max:255',
            'telephone' => ['nullable', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'code_pays' => ['nullable', Rule::in(array_keys(PROPRIETAIRE_PAYS))],
            'adresse'   => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ], [
            'nom.required'    => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'email.email'     => "L'adresse email est invalide.",
            'telephone.regex' => 'Le numéro de téléphone est invalide.',
            'code_pays.in'    => 'Pays invalide.',
        ]);

        if (!empty($data['code_pays']) && isset(PROPRIETAIRE_PAYS[$data['code_pays']])) {
            [$data['pays'], $data['code_phone_pays']] = PROPRIETAIRE_PAYS[$data['code_pays']];
        }

        $data = $this->normalizeData($data);

        $proprietaire->update($data);

        return redirect()->route('proprietaires.index')
            ->with('success', 'Propriétaire mis à jour avec succès.');
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

    public function destroy(Proprietaire $proprietaire): RedirectResponse
    {
        $this->authorize('delete', $proprietaire);
        $proprietaire->delete();

        return redirect()->route('proprietaires.index')
            ->with('success', 'Propriétaire supprimé.');
    }
}
