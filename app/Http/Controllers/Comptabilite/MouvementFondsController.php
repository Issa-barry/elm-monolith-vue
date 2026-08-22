<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\StatutMouvementFonds;
use App\Http\Controllers\Controller;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use App\Models\Site;
use App\Services\SiteScopeService;
use App\Services\Tresorerie\MouvementFondsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MouvementFondsController extends Controller
{
    public function __construct(
        private readonly MouvementFondsService $service,
        private readonly SiteScopeService $siteScope,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', MouvementFonds::class);

        $user = auth()->user();
        $orgId = $user->organization_id;
        $isAdmin = $user->isAdmin();

        $query = MouvementFonds::where('organization_id', $orgId)
            ->with(['siteOrigine:id,nom', 'siteDestination:id,nom', 'compteTresorerieOrigine:id,libelle', 'compteTresorerieDestination:id,libelle']);

        if (! $isAdmin) {
            $siteIds = $this->siteScope->accessibleSiteIds($user);
            $query->where(fn ($q) => $q->whereIn('site_origine_id', $siteIds)->orWhereIn('site_destination_id', $siteIds));
        }

        if ($statut = $request->input('statut')) {
            $query->where('statut', $statut);
        }

        if ($isAdmin && $siteIds = array_filter((array) $request->input('site_ids', []))) {
            $query->where(fn ($q) => $q->whereIn('site_origine_id', $siteIds)->orWhereIn('site_destination_id', $siteIds));
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $s = mb_strtolower($search);
            $query->where(fn ($q) => $q
                ->whereRaw('LOWER(reference) LIKE ?', ["%{$s}%"])
                ->orWhereRaw('LOWER(reference_externe) LIKE ?', ["%{$s}%"])
            );
        }

        $mouvements = $query->orderByDesc('created_at')->paginate(25)->withQueryString()->through(fn (MouvementFonds $m) => [
            'id' => $m->id,
            'reference' => $m->reference,
            'site_origine' => $m->siteOrigine?->nom,
            'site_destination' => $m->siteDestination?->nom,
            'compte_origine' => $m->compteTresorerieOrigine?->libelle,
            'compte_destination' => $m->compteTresorerieDestination?->libelle,
            'montant' => (float) $m->montant,
            'statut' => $m->statut->value,
            'statut_label' => $m->statut->label(),
            'date_envoi' => $m->date_envoi?->toDateString(),
            'date_reception' => $m->date_reception?->toDateString(),
            'created_at' => $m->created_at->toDateString(),
            'peut_envoyer' => $user->can('envoyer', $m),
            'peut_recevoir' => $user->can('recevoir', $m),
            'peut_annuler' => $user->can('annuler', $m),
            'peut_contester' => $user->can('contester', $m),
            'peut_confirmer_retour' => $user->can('confirmerRetour', $m),
        ]);

        return Inertia::render('Comptabilite/MouvementsFonds/Index', [
            'mouvements' => $mouvements,
            'filters' => [
                'statut' => $request->input('statut', ''),
                'search' => $request->input('search', ''),
                'site_ids' => array_values(array_filter((array) $request->input('site_ids', []))),
            ],
            'statut_options' => StatutMouvementFonds::options(),
            'sites' => $this->sitesDisponibles($orgId, $user),
            'is_admin' => $isAdmin,
            'peut_creer' => $user->can('create', MouvementFonds::class),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', MouvementFonds::class);

        $orgId = auth()->user()->organization_id;

        return Inertia::render('Comptabilite/MouvementsFonds/Create', [
            'sites' => $this->sitesDisponibles($orgId, auth()->user()),
            'comptes_tresorerie' => CompteTresorerie::forOrg($orgId)->actifs()->get(['id', 'site_id', 'libelle', 'type']),
            'site_prerempli' => $request->input('site_id'),
            'montant_prerempli' => $request->input('montant'),
            'echeance_debut_prerempli' => $request->input('echeance_debut'),
            'echeance_fin_prerempli' => $request->input('echeance_fin'),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', MouvementFonds::class);

        $orgId = auth()->user()->organization_id;

        $data = $request->validate([
            'site_origine_id' => ['required', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
            'site_destination_id' => ['required', 'different:site_origine_id', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
            'compte_tresorerie_origine_id' => ['required', Rule::exists('compta_supports_tresorerie', 'id')->where('organization_id', $orgId)],
            'compte_tresorerie_destination_id' => ['required', Rule::exists('compta_supports_tresorerie', 'id')->where('organization_id', $orgId)],
            'montant' => ['required', 'numeric', 'min:0.01'],
            'moyen_transfert' => ['nullable', 'string', 'max:30'],
            'reference_externe' => ['nullable', 'string', 'max:100'],
            // Échéance visée par ce financement — pré-remplie depuis Financement des
            // agences, mais toujours modifiable (une remise agence->siège n'en a pas).
            'echeance_debut' => ['nullable', 'date'],
            'echeance_fin' => ['nullable', 'date', 'after_or_equal:echeance_debut'],
            'commentaire' => ['nullable', 'string'],
            'justificatif' => ['nullable', 'file', 'max:10240'],
        ]);

        if ($request->hasFile('justificatif')) {
            $data['justificatif_path'] = $request->file('justificatif')->store('tresorerie/justificatifs', 'public');
        }

        $mouvement = $this->service->creerBrouillon($orgId, $data, auth()->id());

        return redirect()->route('comptabilite.tresorerie.mouvements.index')
            ->with('success', "Mouvement {$mouvement->reference} créé en brouillon.");
    }

    public function envoyer(MouvementFonds $mouvement)
    {
        $this->authorize('envoyer', $mouvement);

        $mouvement = $this->service->envoyer($mouvement, auth()->id());

        return back()->with('success', "Mouvement {$mouvement->reference} marqué comme envoyé.");
    }

    public function recevoir(MouvementFonds $mouvement)
    {
        $this->authorize('recevoir', $mouvement);

        $mouvement = $this->service->recevoir($mouvement, auth()->id());

        return back()->with('success', "Mouvement {$mouvement->reference} confirmé reçu.");
    }

    public function annuler(Request $request, MouvementFonds $mouvement)
    {
        $this->authorize('annuler', $mouvement);

        $data = $request->validate(['motif' => ['required', 'string', 'max:500']]);

        $mouvement = $this->service->annuler($mouvement, auth()->id(), $data['motif']);

        return back()->with('success', "Mouvement {$mouvement->reference} annulé.");
    }

    public function contester(Request $request, MouvementFonds $mouvement)
    {
        $this->authorize('contester', $mouvement);

        $data = $request->validate(['motif' => ['required', 'string', 'max:500']]);

        $mouvement = $this->service->contester($mouvement, auth()->id(), $data['motif']);

        return back()->with('success', "Mouvement {$mouvement->reference} marqué comme contesté.");
    }

    public function confirmerRetour(Request $request, MouvementFonds $mouvement)
    {
        $this->authorize('confirmerRetour', $mouvement);

        $data = $request->validate(['motif' => ['required', 'string', 'max:500']]);

        $mouvement = $this->service->confirmerRetour($mouvement, auth()->id(), $data['motif']);

        return back()->with('success', "Retour des fonds confirmé pour le mouvement {$mouvement->reference}.");
    }

    /** @return list<array{value:string,label:string}> */
    private function sitesDisponibles(string $orgId, $user): array
    {
        $query = Site::where('organization_id', $orgId)->orderBy('nom');

        if (! $user->isAdmin()) {
            $query->whereIn('id', $this->siteScope->accessibleSiteIds($user));
        }

        return $query->get(['id', 'nom'])->map(fn (Site $s) => ['value' => $s->id, 'label' => $s->nom])->all();
    }
}
