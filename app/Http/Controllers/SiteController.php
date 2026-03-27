<?php

namespace App\Http\Controllers;

use App\Enums\SiteStatut;
use App\Enums\SiteType;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SiteController extends Controller
{
    private function siteData(Site $s): array
    {
        return [
            'id'           => $s->id,
            'nom'          => $s->nom,
            'code'         => $s->code,
            'type'         => $s->type?->value,
            'type_label'   => $s->type_label,
            'statut'       => $s->statut?->value,
            'statut_label' => $s->statut_label,
            'localisation' => $s->localisation,
            'pays'         => $s->pays,
            'ville'        => $s->ville,
            'quartier'     => $s->quartier,
            'description'  => $s->description,
            'parent_id'    => $s->parent_id,
            'latitude'     => $s->latitude,
            'longitude'    => $s->longitude,
            'telephone'    => $s->telephone,
            'email'        => $s->email,
            'parent_nom'   => $s->parent?->nom,
            'enfants_count' => $s->enfants()->count(),
        ];
    }

    public function index(): Response
    {
        $this->authorize('viewAny', Site::class);

        $sites = Site::with(['parent'])
            ->withCount('enfants')
            ->where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom')
            ->get()
            ->map(fn (Site $s) => $this->siteData($s));

        return Inertia::render('Sites/Index', [
            'sites' => $sites,
        ]);
    }

    public function show(Site $site): Response
    {
        $this->authorize('view', $site);

        $site->load(['parent', 'enfants', 'users']);

        return Inertia::render('Sites/Show', [
            'site' => [
                ...$this->siteData($site),
                'enfants'    => $site->enfants->map(fn (Site $e) => [
                    'id'          => $e->id,
                    'nom'         => $e->nom,
                    'code'        => $e->code,
                    'type_label'  => $e->type_label,
                    'statut'      => $e->statut?->value,
                    'statut_label' => $e->statut_label,
                ]),
                'users'      => $site->users->map(fn ($u) => [
                    'id'    => $u->id,
                    'name'  => $u->name,
                    'email' => $u->email,
                    'role'  => $u->pivot->role,
                ]),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Site::class);

        return Inertia::render('Sites/Create', [
            'types'         => SiteType::options(),
            'statuts'       => SiteStatut::options(),
            'parentOptions' => $this->parentOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Site::class);

        $orgId = auth()->user()->organization_id;
        abort_if(!$orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'nom'         => 'required|string|max:255',
            'type'        => ['required', Rule::in(array_column(SiteType::cases(), 'value'))],
            'statut'      => ['nullable', Rule::in(array_column(SiteStatut::cases(), 'value'))],
            'localisation' => 'nullable|string|max:255',
            'pays'        => 'nullable|string|max:100',
            'ville'       => 'nullable|string|max:100',
            'quartier'    => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'parent_id'   => [
                'nullable', 'integer',
                Rule::exists('sites', 'id')->where('organization_id', $orgId),
            ],
            'latitude'    => 'nullable|numeric|between:-90,90',
            'longitude'   => 'nullable|numeric|between:-180,180',
            'telephone'   => 'nullable|string|max:50',
            'email'       => 'nullable|email|max:255',
        ], $this->messages());

        $data = $this->normalizeStrings($data);

        Site::create([...$data, 'organization_id' => $orgId]);

        return redirect()->route('sites.index')
            ->with('success', 'Site créé avec succès.');
    }

    public function edit(Site $site): Response
    {
        $this->authorize('update', $site);

        $site->load('parent');

        return Inertia::render('Sites/Edit', [
            'site'          => $this->siteData($site),
            'types'         => SiteType::options(),
            'statuts'       => SiteStatut::options(),
            'parentOptions' => $this->parentOptions($site->id),
        ]);
    }

    public function update(Request $request, Site $site): RedirectResponse
    {
        $this->authorize('update', $site);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'nom'         => 'required|string|max:255',
            'code'        => [
                'required', 'string', 'max:50',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('sites', 'code')
                    ->where('organization_id', $orgId)
                    ->ignore($site->id),
            ],
            'type'        => ['required', Rule::in(array_column(SiteType::cases(), 'value'))],
            'statut'      => ['nullable', Rule::in(array_column(SiteStatut::cases(), 'value'))],
            'localisation' => 'nullable|string|max:255',
            'pays'        => 'nullable|string|max:100',
            'ville'       => 'nullable|string|max:100',
            'quartier'    => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'parent_id'   => [
                'nullable', 'integer',
                Rule::exists('sites', 'id')->where('organization_id', $orgId),
            ],
            'latitude'    => 'nullable|numeric|between:-90,90',
            'longitude'   => 'nullable|numeric|between:-180,180',
            'telephone'   => 'nullable|string|max:50',
            'email'       => 'nullable|email|max:255',
        ], $this->messages());

        $data = $this->normalizeStrings($data);

        $site->update($data);

        return redirect()->route('sites.index')
            ->with('success', 'Site mis à jour avec succès.');
    }

    public function destroy(Site $site): RedirectResponse
    {
        $this->authorize('delete', $site);

        if ($site->enfants()->exists()) {
            return back()->with('error', 'Impossible de supprimer ce site car il possède des sites enfants. Veuillez d\'abord les réaffecter ou les supprimer.');
        }

        $site->delete();

        return redirect()->route('sites.index')
            ->with('success', 'Site supprimé.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function parentOptions(?int $excludeId = null): array
    {
        $query = Site::where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom');

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->get()
            ->map(fn (Site $s) => [
                'value' => $s->id,
                'label' => "{$s->nom} ({$s->code})",
            ])
            ->toArray();
    }

    private function normalizeStrings(array $data): array
    {
        if (!empty($data['code'])) {
            $data['code'] = mb_strtoupper(trim($data['code']), 'UTF-8');
        }
        if (!empty($data['nom'])) {
            $data['nom'] = mb_convert_case(mb_strtolower($data['nom']), MB_CASE_TITLE, 'UTF-8');
        }
        if (!empty($data['ville'])) {
            $data['ville'] = mb_convert_case(mb_strtolower($data['ville']), MB_CASE_TITLE, 'UTF-8');
        }
        if (!empty($data['quartier'])) {
            $data['quartier'] = mb_convert_case(mb_strtolower($data['quartier']), MB_CASE_TITLE, 'UTF-8');
        }
        return $data;
    }

    private function messages(): array
    {
        return [
            'nom.required'       => 'Le nom du site est obligatoire.',
            'nom.max'            => 'Le nom ne peut pas dépasser 255 caractères.',
            'code.required'      => 'Le code du site est obligatoire.',
            'code.max'           => 'Le code ne peut pas dépasser 50 caractères.',
            'code.regex'         => 'Le code ne peut contenir que des majuscules, chiffres, tirets et underscores.',
            'code.unique'        => 'Ce code est déjà utilisé par un autre site de votre organisation.',
            'type.required'      => 'Le type de site est obligatoire.',
            'type.in'            => 'Type de site invalide.',
            'statut.in'          => 'Statut invalide.',
            'parent_id.exists'   => 'Le site parent sélectionné est introuvable.',
        ];
    }
}
