<?php

namespace App\Http\Controllers;

use App\Models\FonctionRh;
use App\Services\Rh\FonctionRhNamingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD de fonctions RH en self-service, strictement isolé par organisation — mirroring du gating
 * de RoleController (aucune permission granulaire dédiée : écran de paramétrage organisationnel,
 * pas une ressource métier CRUD). Une fonction ne contient jamais de permission.
 *
 * Aucune fonction système, aucune suggestion de profil d'accès (décision finale du 2026-08-21) :
 * chaque organisation construit son propre vocabulaire de zéro. Pas de destroy() : une fonction
 * déjà utilisée reste consultable dans l'historique d'un employé, seulement désactivable
 * (is_active) pour de nouvelles affectations.
 */
class FonctionRhController extends Controller
{
    public function __construct(private readonly FonctionRhNamingService $naming) {}

    public function index(): Response
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $fonctions = FonctionRh::where('organization_id', auth()->user()->organization_id)
            ->withCount('employes')
            ->orderBy('libelle')
            ->get()
            ->map(fn (FonctionRh $f) => $this->toRow($f));

        return Inertia::render('FonctionsRh/Index', [
            'fonctions' => $fonctions,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->canManage(), 403);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'libelle' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:10'],
        ]);

        if ($this->naming->libelleTaken($data['libelle'], $orgId)) {
            throw ValidationException::withMessages([
                'libelle' => "Une fonction équivalente existe déjà (« {$data['libelle']} ») — choisissez un libellé différent.",
            ]);
        }

        $code = filled($data['code'] ?? null)
            ? $this->naming->normalizeTrinome($data['code'])
            : null;

        if ($code !== null && $this->naming->codeTaken($code, $orgId)) {
            throw ValidationException::withMessages([
                'code' => "Ce trinôme est déjà utilisé par une autre fonction (« {$code} »).",
            ]);
        }

        $fonction = FonctionRh::create([
            'organization_id' => $orgId,
            'libelle' => $data['libelle'],
            'code' => $code ?? $this->naming->uniqueTrinome($data['libelle'], $orgId),
            'is_active' => true,
        ]);

        // Toujours back() + flash de l'id créé, jamais de redirection vers une autre page : la
        // création se fait exclusivement en popup (écran Fonctions RH ou sélecteur embarqué dans
        // un formulaire Employé/validation de compte), mirroring CategorieController::store().
        return back()
            ->with('success', 'Fonction créée.')
            ->with('created_fonction_rh_id', $fonction->id);
    }

    public function update(Request $request, FonctionRh $fonctionRh): RedirectResponse
    {
        abort_unless($this->canManage(), 403);
        $this->authorizeSameOrganization($fonctionRh);

        $data = $request->validate([
            'libelle' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:10'],
        ]);

        $orgId = $fonctionRh->organization_id;

        if ($this->naming->libelleTaken($data['libelle'], $orgId, $fonctionRh->id)) {
            throw ValidationException::withMessages([
                'libelle' => "Une fonction équivalente existe déjà (« {$data['libelle']} ») — choisissez un libellé différent.",
            ]);
        }

        $code = filled($data['code'] ?? null) ? $this->naming->normalizeTrinome($data['code']) : null;

        if ($code !== null && $this->naming->codeTaken($code, $orgId, $fonctionRh->id)) {
            throw ValidationException::withMessages([
                'code' => "Ce trinôme est déjà utilisé par une autre fonction (« {$code} »).",
            ]);
        }

        $fonctionRh->update([
            'libelle' => $data['libelle'],
            'code' => $code ?? $fonctionRh->code,
        ]);

        return back()->with('success', 'Fonction mise à jour.');
    }

    /** Active/désactive une fonction — jamais de suppression (cf. docblock de classe). */
    public function toggle(FonctionRh $fonctionRh): RedirectResponse
    {
        abort_unless($this->canManage(), 403);
        $this->authorizeSameOrganization($fonctionRh);

        $fonctionRh->update(['is_active' => ! $fonctionRh->is_active]);

        return back()->with('success', $fonctionRh->is_active ? 'Fonction activée.' : 'Fonction désactivée.');
    }

    private function toRow(FonctionRh $f): array
    {
        return [
            'id' => $f->id,
            'libelle' => $f->libelle,
            'code' => $f->code,
            'is_active' => $f->is_active,
            'employes_count' => $f->employes_count ?? 0,
        ];
    }

    /** Isolation stricte : jamais la fonction d'une autre organisation, même en lecture. */
    private function authorizeSameOrganization(FonctionRh $fonction): void
    {
        abort_if($fonction->organization_id !== auth()->user()->organization_id, 403);
    }

    private function canManage(): bool
    {
        $user = auth()->user();

        return $user && ($user->isSuperAdmin() || $user->hasRole('admin_entreprise'));
    }
}
