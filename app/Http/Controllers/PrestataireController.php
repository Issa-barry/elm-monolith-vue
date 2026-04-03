<?php

namespace App\Http\Controllers;

use App\Enums\PrestataireType;
use App\Models\Prestataire;
use App\Traits\PhoneHandlerTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PrestataireController extends Controller
{
    use PhoneHandlerTrait;

    public function index(): Response
    {
        $this->authorize('viewAny', Prestataire::class);

        $prestataires = Prestataire::where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom')
            ->get()
            ->map(fn (Prestataire $p) => [
                'id' => $p->id,
                'reference' => $p->reference,
                'nom_complet' => $p->nom_complet,
                'nom' => $p->nom,
                'prenom' => $p->prenom,
                'raison_sociale' => $p->raison_sociale,
                'email' => $p->email,
                'phone' => $p->phone,
                'code_phone_pays' => $p->code_phone_pays,
                'ville' => $p->ville,
                'type' => $p->type?->value,
                'type_label' => $p->type_label,
                'is_active' => $p->is_active,
            ]);

        return Inertia::render('Prestataires/Index', [
            'prestataires' => $prestataires,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Prestataire::class);

        return Inertia::render('Prestataires/Create', [
            'types' => PrestataireType::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Prestataire::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, 'Votre compte n\'est associé à aucune organisation.');

        $data = $request->validate($this->validationRules());

        $data = $this->resolveCountryData($data);

        $data = $this->normalizeData($data);

        Prestataire::create([...$data, 'organization_id' => $orgId]);

        return redirect()->route('prestataires.index')
            ->with('success', 'Prestataire créé avec succès.');
    }

    public function edit(Prestataire $prestataire): Response
    {
        $this->authorize('update', $prestataire);

        [$phone, $codePhonePays, $codePays, $pays] = $this->splitPhone(
            $prestataire->phone,
            $prestataire->code_phone_pays,
            $prestataire->code_pays,
            $prestataire->pays,
        );

        return Inertia::render('Prestataires/Edit', [
            'prestataire' => [
                'id' => $prestataire->id,
                'reference' => $prestataire->reference,
                'nom' => $prestataire->nom,
                'prenom' => $prestataire->prenom,
                'raison_sociale' => $prestataire->raison_sociale,
                'email' => $prestataire->email,
                'phone' => $phone,
                'code_phone_pays' => $codePhonePays,
                'code_pays' => $codePays,
                'pays' => $pays,
                'ville' => $prestataire->ville,
                'adresse' => $prestataire->adresse,
                'type' => $prestataire->type?->value,
                'notes' => $prestataire->notes,
                'is_active' => $prestataire->is_active,
            ],
            'types' => PrestataireType::options(),
        ]);
    }

    public function update(Request $request, Prestataire $prestataire): RedirectResponse
    {
        $this->authorize('update', $prestataire);

        $data = $request->validate($this->validationRules());

        $data = $this->resolveCountryData($data);

        $data = $this->normalizeData($data);

        $prestataire->update($data);

        return redirect()->route('prestataires.edit', $prestataire)
            ->with('success', 'Prestataire mis à jour avec succès.');
    }

    private function validationRules(): array
    {
        return [
            'nom'            => 'nullable|string|max:255|required_without:raison_sociale',
            'prenom'         => 'nullable|string|max:255|required_without:raison_sociale',
            'raison_sociale' => 'nullable|string|max:255',
            'email'          => 'nullable|email:rfc,dns|max:255',
            'phone'          => ['nullable', 'string', 'max:25', 'regex:/^[+0-9][0-9\s\-().]{5,24}$/'],
            'code_pays'      => ['nullable', Rule::in(array_keys(static::supportedPays()))],
            'ville'          => 'nullable|string|max:100',
            'adresse'        => 'nullable|string',
            'type'           => ['required', Rule::enum(PrestataireType::class)],
            'notes'          => 'nullable|string',
            'is_active'      => 'boolean',
        ];
    }

    public function destroy(Prestataire $prestataire): RedirectResponse
    {
        $this->authorize('delete', $prestataire);
        $prestataire->delete();

        return redirect()->route('prestataires.index')
            ->with('success', 'Prestataire supprimé.');
    }
}
