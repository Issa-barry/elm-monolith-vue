<?php

namespace App\Http\Controllers;

use App\Models\Proprietaire;
use App\Traits\PhoneHandlerTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProprietaireController extends Controller
{
    use PhoneHandlerTrait;

    public function index(): Response
    {
        $this->authorize('viewAny', Proprietaire::class);

        $proprietaires = Proprietaire::where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom')
            ->get()
            ->map(fn (Proprietaire $p) => [
                'id' => $p->id,
                'nom' => $p->nom,
                'prenom' => $p->prenom,
                'nom_complet' => trim("{$p->prenom} {$p->nom}"),
                'email' => $p->email,
                'telephone' => $p->telephone,
                'code_phone_pays' => $p->code_phone_pays,
                'ville' => $p->ville,
                'pays' => $p->pays,
                'code_pays' => $p->code_pays,
                'adresse' => $p->adresse,
                'is_active' => $p->is_active,
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
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'prenom' => 'required|string|max:255',
            'email' => 'nullable|email:rfc,dns|max:255',
            'telephone' => ['nullable', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'code_pays' => ['nullable', Rule::in(array_keys(static::supportedPays()))],
            'ville' => 'nullable|string|max:100',
            'adresse' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ], [
            'nom.required' => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'email.email' => "L'adresse email est invalide.",
            'telephone.regex' => 'Le numéro de téléphone est invalide.',
            'code_pays.in' => 'Pays invalide.',
        ]);

        if (! empty($data['code_pays']) && isset(static::supportedPays()[$data['code_pays']])) {
            [$data['pays'], $data['code_phone_pays']] = static::supportedPays()[$data['code_pays']];
        }

        $this->validateLocalPhoneLength($data);

        $data = $this->normalizeData($data);

        Proprietaire::create([...$data, 'organization_id' => $orgId]);

        return redirect()->route('proprietaires.index')
            ->with('success', 'Propriétaire créé avec succès.');
    }

    public function edit(Proprietaire $proprietaire): Response
    {
        $this->authorize('update', $proprietaire);

        [$telephone, $codePhonePays, $codePays, $pays] = $this->splitPhone(
            $proprietaire->telephone,
            $proprietaire->code_phone_pays,
            $proprietaire->code_pays,
            $proprietaire->pays,
        );

        return Inertia::render('Proprietaires/Edit', [
            'proprietaire' => [
                'id' => $proprietaire->id,
                'nom' => $proprietaire->nom,
                'prenom' => $proprietaire->prenom,
                'email' => $proprietaire->email,
                'telephone' => $telephone,
                'adresse' => $proprietaire->adresse,
                'ville' => $proprietaire->ville,
                'pays' => $pays,
                'code_pays' => $codePays,
                'code_phone_pays' => $codePhonePays,
                'is_active' => $proprietaire->is_active,
            ],
        ]);
    }

    public function update(Request $request, Proprietaire $proprietaire): RedirectResponse
    {
        $this->authorize('update', $proprietaire);

        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'prenom' => 'required|string|max:255',
            'email' => 'nullable|email:rfc,dns|max:255',
            'telephone' => ['nullable', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'code_pays' => ['nullable', Rule::in(array_keys(static::supportedPays()))],
            'ville' => 'nullable|string|max:100',
            'adresse' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ], [
            'nom.required' => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'email.email' => "L'adresse email est invalide.",
            'telephone.regex' => 'Le numéro de téléphone est invalide.',
            'code_pays.in' => 'Pays invalide.',
        ]);

        if (! empty($data['code_pays']) && isset(static::supportedPays()[$data['code_pays']])) {
            [$data['pays'], $data['code_phone_pays']] = static::supportedPays()[$data['code_pays']];
        }

        $this->validateLocalPhoneLength($data);

        $data = $this->normalizeData($data);

        $proprietaire->update($data);

        return redirect()->route('proprietaires.edit', $proprietaire)
            ->with('success', 'Propriétaire mis à jour avec succès.');
    }

    private function normalizeData(array $data): array
    {
        if (! empty($data['nom'])) {
            $data['nom'] = mb_strtoupper($data['nom'], 'UTF-8');
        }
        if (! empty($data['prenom'])) {
            $data['prenom'] = mb_convert_case(mb_strtolower($data['prenom'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }
        if (! empty($data['ville'])) {
            $data['ville'] = mb_convert_case(mb_strtolower($data['ville'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }
        if (! empty($data['adresse'])) {
            $data['adresse'] = mb_convert_case(mb_strtolower($data['adresse'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }
        if (! empty($data['telephone'])) {
            $data['telephone'] = $this->buildInternationalPhone($data['telephone'], $data['code_phone_pays'] ?? null);
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
