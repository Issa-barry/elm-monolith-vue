<?php

namespace App\Http\Controllers;

use App\Enums\SiteStatut;
use App\Enums\SiteType;
use App\Models\Site;
use App\Models\UserInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class SiteController extends Controller
{
    private function siteData(Site $s): array
    {
        return [
            'id' => $s->id,
            'nom' => $s->nom,
            'code' => $s->code,
            'type' => $s->type?->value,
            'type_label' => $s->type_label,
            'statut' => $s->statut?->value,
            'statut_label' => $s->statut_label,
            'localisation' => $s->localisation,
            'pays' => $s->pays,
            'ville' => $s->ville,
            'quartier' => $s->quartier,
            'description' => $s->description,
            'parent_id' => $s->parent_id,
            'latitude' => $s->latitude,
            'longitude' => $s->longitude,
            'telephone' => $s->telephone,
            'email' => $s->email,
            'parent_nom' => $s->parent?->nom,
            'enfants_count' => (int) ($s->enfants_count ?? $s->enfants()->count()),
        ];
    }

    public function index(): Response
    {
        $this->authorize('viewAny', Site::class);

        $sites = Site::with(['parent'])
            ->withCount(['enfants'])
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

        $site->load([
            'parent',
            'enfants',
            'users.roles',
            'invitations' => fn ($q) => $q->orderByDesc('created_at'),
        ]);

        $canInvite = auth()->user()->can('invite', $site);

        // Active users assigned to this site
        $membresUsers = $site->users->map(fn ($u) => [
            'id' => $u->id,
            'type' => 'user',
            'invitation_id' => null,
            'nom_complet' => $u->name,
            'email' => $u->email,
            'telephone' => $u->telephone,
            'role' => $u->getRoleNames()->first(),
            'statut' => $u->is_active ? 'actif' : 'inactif',
            'statut_label' => $u->is_active ? 'Actif' : 'Inactif',
            'date' => $u->pivot->created_at?->format('d/m/Y'),
            'can_resend' => false,
            'can_revoke' => false,
        ])->values();

        // Invitations (pending/expired/revoked — skip accepted since they become users)
        $membresInvitations = $site->invitations
            ->filter(fn (UserInvitation $inv) => ! $inv->isAccepted())
            ->map(fn (UserInvitation $inv) => [
                'id' => null,
                'type' => 'invitation',
                'invitation_id' => $inv->id,
                'nom_complet' => null,
                'email' => $inv->email,
                'telephone' => null,
                'role' => $inv->role,
                'statut' => $inv->statut,
                'statut_label' => $inv->statut_label,
                'date' => $inv->created_at?->format('d/m/Y'),
                'can_resend' => $canInvite && in_array($inv->statut, ['expired', 'revoked'], true),
                'can_revoke' => $canInvite && $inv->statut === 'pending',
            ])->values();

        $membres = $membresUsers->concat($membresInvitations)->values();

        $rolesDisponibles = Role::whereIn('name', UserController::STAFF_ROLES)
            ->get(['id', 'name'])
            ->map(fn ($r) => ['value' => $r->name, 'label' => $this->roleLabel($r->name)])
            ->values();

        return Inertia::render('Sites/Show', [
            'site' => [
                ...$this->siteData($site),
                'enfants' => $site->enfants->map(fn (Site $e) => [
                    'id' => $e->id,
                    'nom' => $e->nom,
                    'code' => $e->code,
                    'type_label' => $e->type_label,
                    'statut' => $e->statut?->value,
                    'statut_label' => $e->statut_label,
                ]),
            ],
            'membres' => $membres,
            'roles_disponibles' => $rolesDisponibles,
            'can_invite' => $canInvite,
        ]);
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            'super_admin' => 'Super administrateur',
            'admin_entreprise' => 'Administrateur',
            'manager' => 'Manager',
            'commerciale' => 'Commercial(e)',
            'comptable' => 'Comptable',
            default => $role,
        };
    }

    public function create(): Response
    {
        $this->authorize('create', Site::class);

        return Inertia::render('Sites/Create', [
            'types' => SiteType::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Site::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'type' => ['required', Rule::in(array_column(SiteType::cases(), 'value'))],
            'ville' => 'nullable|string|max:100',
            'quartier' => 'nullable|string|max:100',
            'telephone' => 'nullable|string|max:50',
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
            'site' => $this->siteData($site),
            'types' => SiteType::options(),
        ]);
    }

    public function update(Request $request, Site $site): RedirectResponse
    {
        $this->authorize('update', $site);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'code' => [
                'required', 'string', 'max:50',
                'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('sites', 'code')
                    ->where('organization_id', $orgId)
                    ->ignore($site->id),
            ],
            'type' => ['required', Rule::in(array_column(SiteType::cases(), 'value'))],
            'ville' => 'nullable|string|max:100',
            'quartier' => 'nullable|string|max:100',
            'telephone' => 'nullable|string|max:50',
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
        if (! empty($data['code'])) {
            $data['code'] = mb_strtoupper(trim($data['code']), 'UTF-8');
        }
        if (! empty($data['nom'])) {
            $data['nom'] = mb_convert_case(mb_strtolower($data['nom']), MB_CASE_TITLE, 'UTF-8');
        }
        if (! empty($data['ville'])) {
            $data['ville'] = mb_convert_case(mb_strtolower($data['ville']), MB_CASE_TITLE, 'UTF-8');
        }
        if (! empty($data['quartier'])) {
            $data['quartier'] = mb_convert_case(mb_strtolower($data['quartier']), MB_CASE_TITLE, 'UTF-8');
        }

        return $data;
    }

    private function messages(): array
    {
        return [
            'nom.required' => 'Le nom du site est obligatoire.',
            'nom.max' => 'Le nom ne peut pas dépasser 255 caractères.',
            'code.required' => 'Le code du site est obligatoire.',
            'code.max' => 'Le code ne peut pas dépasser 50 caractères.',
            'code.regex' => 'Le code ne peut contenir que des majuscules, chiffres, tirets et underscores.',
            'code.unique' => 'Ce code est déjà utilisé par un autre site de votre organisation.',
            'type.required' => 'Le type de site est obligatoire.',
            'type.in' => 'Type de site invalide.',
            'statut.in' => 'Statut invalide.',
            'localisation.required' => "L'adresse du site est obligatoire.",
            'parent_id.exists' => 'Le site parent sélectionné est introuvable.',
        ];
    }
}
