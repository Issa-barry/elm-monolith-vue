<?php

namespace App\Http\Controllers;

use App\Enums\TypeVehicule;
use App\Models\Livreur;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use App\Services\ImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

use Inertia\Inertia;
use Inertia\Response;

class VehiculeController extends Controller
{
    private function vehiculeData(Vehicule $v): array
    {
        return [
            'id'                       => $v->id,
            'nom_vehicule'             => $v->nom_vehicule,
            'marque'                   => $v->marque,
            'modele'                   => $v->modele,
            'immatriculation'          => $v->immatriculation,
            'type_vehicule'            => $v->type_vehicule?->value,
            'type_label'               => $v->type_label,
            'capacite_packs'           => $v->capacite_packs,
            'proprietaire_id'          => $v->proprietaire_id,
            'proprietaire_nom'         => $v->proprietaire ? trim($v->proprietaire->prenom . ' ' . $v->proprietaire->nom) : null,
            'livreur_principal_id'     => $v->livreur_principal_id,
            'livreur_nom'              => $v->livreurPrincipal
                                            ? trim($v->livreurPrincipal->prenom . ' ' . $v->livreurPrincipal->nom)
                                            : null,
            'pris_en_charge_par_usine' => $v->pris_en_charge_par_usine,
            'taux_commission_livreur'         => $v->taux_commission_livreur,
            'taux_commission_proprietaire'    => $v->taux_commission_proprietaire,
            'photo_url'                       => $v->photo_url,
            'is_active'                => $v->is_active,
        ];
    }

    public function index(): Response
    {
        $this->authorize('viewAny', Vehicule::class);

        $vehicules = Vehicule::with(['proprietaire', 'livreurPrincipal'])
            ->where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom_vehicule')
            ->get()
            ->map(fn (Vehicule $v) => $this->vehiculeData($v));

        return Inertia::render('Vehicules/Index', [
            'vehicules' => $vehicules,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Vehicule::class);

        return Inertia::render('Vehicules/Create', [
            'proprietaires' => $this->proprietairesOptions(),
            'livreurs'      => $this->livreursOptions(),
            'types'         => TypeVehicule::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Vehicule::class);

        $orgId = auth()->user()->organization_id;
        abort_if(!$orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'nom_vehicule'             => 'required|string|max:100',
            'immatriculation'          => [
                'required', 'string', 'max:20',
                Rule::unique('vehicules', 'immatriculation')->where('organization_id', $orgId),
            ],
            'type_vehicule'            => ['required', Rule::in(TypeVehicule::allowedValues())],
            'capacite_packs'           => 'nullable|integer|min:1|max:99999',
            'proprietaire_id'          => ['required', 'integer', Rule::exists('proprietaires', 'id')->where('organization_id', $orgId)],
            'livreur_principal_id'     => ['nullable', 'integer', Rule::exists('livreurs', 'id')->where('organization_id', $orgId)],
            'pris_en_charge_par_usine' => 'boolean',
            'taux_commission_livreur'         => 'nullable|numeric|min:0|max:100',
            'taux_commission_proprietaire'    => 'nullable|numeric|min:0|max:100',
            'photo'                           => 'nullable|image|mimes:jpg,jpeg,png,webp|max:3072',
            'is_active'                => 'boolean',
        ], $this->messages());

        $data = $this->normalizeStrings($data);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = (new ImageService())->storeAsWebp($request->file('photo'), 'vehicules');
        }

        unset($data['photo']);
        Vehicule::create([...$data, 'organization_id' => $orgId]);

        return redirect()->route('vehicules.index')
            ->with('success', 'Véhicule créé avec succès.');
    }

    public function edit(Vehicule $vehicule): Response
    {
        $this->authorize('update', $vehicule);

        $vehicule->load(['proprietaire', 'livreurPrincipal']);

        return Inertia::render('Vehicules/Edit', [
            'vehicule'      => $this->vehiculeData($vehicule),
            'proprietaires' => $this->proprietairesOptions(),
            'livreurs'      => $this->livreursOptions(),
            'types'         => TypeVehicule::options(),
        ]);
    }

    public function update(Request $request, Vehicule $vehicule): RedirectResponse
    {
        $this->authorize('update', $vehicule);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'nom_vehicule'             => 'required|string|max:100',
            'immatriculation'          => [
                'required', 'string', 'max:20',
                Rule::unique('vehicules', 'immatriculation')
                    ->where('organization_id', $orgId)
                    ->ignore($vehicule->id),
            ],
            'type_vehicule'            => ['required', Rule::in(TypeVehicule::allowedValues())],
            'capacite_packs'           => 'nullable|integer|min:1|max:99999',
            'proprietaire_id'          => ['required', 'integer', Rule::exists('proprietaires', 'id')->where('organization_id', $orgId)],
            'livreur_principal_id'     => ['nullable', 'integer', Rule::exists('livreurs', 'id')->where('organization_id', $orgId)],
            'pris_en_charge_par_usine' => 'boolean',
            'taux_commission_livreur'         => 'nullable|numeric|min:0|max:100',
            'taux_commission_proprietaire'    => 'nullable|numeric|min:0|max:100',
            'photo'                           => 'nullable|image|mimes:jpg,jpeg,png,webp|max:3072',
            'is_active'                => 'boolean',
        ], $this->messages());

        $data = $this->normalizeStrings($data);

        if ($request->hasFile('photo')) {
            $imageService = new ImageService();
            $imageService->delete($vehicule->photo_path);
            $data['photo_path'] = $imageService->storeAsWebp($request->file('photo'), 'vehicules');
        }

        unset($data['photo']);
        $vehicule->update($data);

        return redirect()->route('vehicules.index')
            ->with('success', 'Véhicule mis à jour avec succès.');
    }

    public function destroy(Vehicule $vehicule): RedirectResponse
    {
        $this->authorize('delete', $vehicule);

        if ($vehicule->photo_path) {
            Storage::disk('public')->delete($vehicule->photo_path);
        }

        $vehicule->delete();

        return redirect()->route('vehicules.index')
            ->with('success', 'Véhicule supprimé.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function proprietairesOptions(): array
    {
        return Proprietaire::where('organization_id', auth()->user()->organization_id)
            ->where('is_active', true)
            ->orderBy('nom')
            ->get()
            ->map(fn (Proprietaire $p) => [
                'value' => $p->id,
                'label' => trim("{$p->prenom} {$p->nom}"),
            ])
            ->toArray();
    }

    private function livreursOptions(): array
    {
        return Livreur::where('organization_id', auth()->user()->organization_id)
            ->where('is_active', true)
            ->orderBy('nom')
            ->get()
            ->map(fn (Livreur $l) => [
                'value' => $l->id,
                'label' => trim("{$l->prenom} {$l->nom}"),
            ])
            ->toArray();
    }

    private function normalizeStrings(array $data): array
    {
        if (!empty($data['nom_vehicule'])) {
            $data['nom_vehicule'] = mb_convert_case(mb_strtolower($data['nom_vehicule']), MB_CASE_TITLE, 'UTF-8');
        }
        if (!empty($data['immatriculation'])) {
            $data['immatriculation'] = mb_strtoupper(trim($data['immatriculation']), 'UTF-8');
        }
        return $data;
    }

    private function messages(): array
    {
        return [
            'nom_vehicule.required'        => 'Le nom du véhicule est obligatoire.',
            'immatriculation.required'     => "L'immatriculation est obligatoire.",
            'immatriculation.unique'       => "Ce numéro d'immatriculation est déjà utilisé.",
            'type_vehicule.required'       => 'Le type de véhicule est obligatoire.',
            'type_vehicule.in'             => 'Type de véhicule invalide.',
            'proprietaire_id.required'     => 'Le propriétaire est obligatoire.',
            'proprietaire_id.exists'       => 'Le propriétaire sélectionné est introuvable.',
            'livreur_principal_id.exists'  => 'Le livreur sélectionné est introuvable.',
            'photo.image'                  => 'Le fichier doit être une image.',
            'photo.mimes'                  => 'La photo doit être au format jpg, jpeg, png ou webp.',
            'photo.max'                    => 'La photo ne peut pas dépasser 3 Mo.',
        ];
    }
}
