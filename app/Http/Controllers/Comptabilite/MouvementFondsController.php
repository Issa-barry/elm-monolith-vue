<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\NatureMouvementFonds;
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
            ->with([
                'siteOrigine:id,nom', 'siteDestination:id,nom',
                'compteTresorerieOrigine:id,libelle', 'compteTresorerieDestination:id,libelle',
                'expediteur.personne', 'receptionnaire.personne',
            ]);

        if (! $isAdmin) {
            $siteIds = $this->siteScope->accessibleSiteIds($user);
            $query->where(fn ($q) => $q->whereIn('site_origine_id', $siteIds)->orWhereIn('site_destination_id', $siteIds));
        }

        if ($statut = $request->input('statut')) {
            $query->where('statut', $statut);
        }

        if ($nature = NatureMouvementFonds::tryFrom((string) $request->input('nature'))) {
            $query->where('nature', $nature->value);
        }

        if ($isAdmin && $siteIds = array_filter((array) $request->input('site_ids', []))) {
            $query->where(fn ($q) => $q->whereIn('site_origine_id', $siteIds)->orWhereIn('site_destination_id', $siteIds));
        }

        // Filtres directionnels : s'ajoutent (ET) au périmètre déjà posé ci-dessus, ils ne l'élargissent jamais.
        $siteOrigineId = $this->filtreScalaire($request, 'site_origine_id');
        $siteDestinationId = $this->filtreScalaire($request, 'site_destination_id');
        $montantMin = $this->filtreScalaire($request, 'montant_min');
        $montantMax = $this->filtreScalaire($request, 'montant_max');

        if ($siteOrigineId !== '') {
            $query->where('site_origine_id', $siteOrigineId);
        }

        if ($siteDestinationId !== '') {
            $query->where('site_destination_id', $siteDestinationId);
        }

        if (is_numeric($montantMin)) {
            $query->where('montant', '>=', (float) $montantMin);
        }

        if (is_numeric($montantMax)) {
            $query->where('montant', '<=', (float) $montantMax);
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
            'nature' => $m->nature->value,
            'nature_label' => $m->nature->label(),
            'commentaire' => $m->commentaire,
            'site_origine' => $m->siteOrigine?->nom,
            'site_destination' => $m->siteDestination?->nom,
            'site_destination_id' => $m->site_destination_id,
            'compte_origine' => $m->compteTresorerieOrigine?->libelle,
            'compte_destination' => $m->compteTresorerieDestination?->libelle,
            'compte_destination_id' => $m->compte_tresorerie_destination_id,
            'montant' => (float) $m->montant,
            'statut' => $m->statut->value,
            'statut_label' => $m->statut->label(),
            'date_envoi' => $m->date_envoi?->toDateString(),
            'date_reception' => $m->date_reception?->toDateString(),
            'expediteur' => $m->expediteur?->name,
            'receptionnaire' => $m->receptionnaire?->name,
            'created_at' => $m->created_at->toDateString(),
            // L'état du mouvement est vérifié EXPLICITEMENT en plus de la policy : le Gate::before du
            // super admin passe avant elle et afficherait sinon toutes les actions sur chaque ligne,
            // y compris terminée. Même raison pour la séparation envoi/réception d'un versement de
            // caisse (MouvementFonds::separationEnvoiReceptionRespectee()). Le service reste la
            // garantie réelle de chacune de ces règles.
            'peut_envoyer' => $m->isBrouillon() && $user->can('envoyer', $m),
            'peut_recevoir' => ($m->isEnvoye() || $m->isConteste())
                && $m->separationEnvoiReceptionRespectee($user)
                && $user->can('recevoir', $m),
            'peut_annuler' => $m->isBrouillon() && $user->can('annuler', $m),
            'peut_contester' => $m->isEnvoye()
                && $m->separationEnvoiReceptionRespectee($user)
                && $user->can('contester', $m),
            'peut_confirmer_retour' => $m->isConteste() && $user->can('confirmerRetour', $m),
        ]);

        return Inertia::render('Comptabilite/MouvementsFonds/Index', [
            'mouvements' => $mouvements,
            'filters' => [
                'statut' => $request->input('statut', ''),
                'nature' => $request->input('nature', ''),
                'search' => $request->input('search', ''),
                'site_ids' => array_values(array_filter((array) $request->input('site_ids', []))),
                'site_origine_id' => $siteOrigineId,
                'site_destination_id' => $siteDestinationId,
                'montant_min' => $montantMin,
                'montant_max' => $montantMax,
            ],
            'statut_options' => StatutMouvementFonds::options(),
            'nature_options' => NatureMouvementFonds::options(),
            'sites' => $this->sitesDisponibles($orgId, $user),
            'sites_mouvements' => $this->sitesOrganisation($orgId),
            'is_admin' => $isAdmin,
            'peut_creer' => $user->can('create', MouvementFonds::class),
            // Nécessaire pour choisir le support de destination au moment de
            // « Confirmer réception » (cf. MouvementFondsService::recevoir()) —
            // le frontend filtre par site_destination_id du mouvement concerné.
            'comptes_tresorerie' => CompteTresorerie::forOrg($orgId)->actifs()->agence()->get(['id', 'site_id', 'libelle', 'type']),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', MouvementFonds::class);

        $orgId = auth()->user()->organization_id;

        return Inertia::render('Comptabilite/MouvementsFonds/Create', [
            'sites' => $this->sitesDisponibles($orgId, auth()->user()),
            'comptes_tresorerie' => CompteTresorerie::forOrg($orgId)->actifs()->agence()->get(['id', 'site_id', 'libelle', 'type']),
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
            // Le support de destination n'est plus saisi à la création : c'est le
            // destinataire qui le choisit au moment de « Confirmer réception »
            // (cf. docblock de MouvementFondsService), il ne peut donc pas être
            // connu avec certitude par l'émetteur.
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

    public function recevoir(Request $request, MouvementFonds $mouvement)
    {
        $this->authorize('recevoir', $mouvement);

        $orgId = auth()->user()->organization_id;

        // Un versement de caisse a sa destination fixée à l'envoi : la confirmation ne demande
        // aucun choix (le service refuse de toute façon un autre support).
        $destinationId = $mouvement->isInterne()
            ? $mouvement->compte_tresorerie_destination_id
            : $request->validate([
                'compte_tresorerie_destination_id' => ['required', Rule::exists('compta_supports_tresorerie', 'id')->where('organization_id', $orgId)],
            ])['compte_tresorerie_destination_id'];

        $mouvement = $this->service->recevoir($mouvement, auth()->id(), $destinationId);

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

    /**
     * Tous les sites de l'organisation, pour les filtres Origine/Destination : un utilisateur
     * limité à son agence voit aussi les mouvements venant du siège ou allant vers lui, il doit
     * donc pouvoir filtrer sur un site hors de son périmètre (le périmètre reste imposé à la requête).
     *
     * @return list<array{value:string,label:string}>
     */
    private function sitesOrganisation(string $orgId): array
    {
        return Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom'])
            ->map(fn (Site $s) => ['value' => $s->id, 'label' => $s->nom])->all();
    }

    private function filtreScalaire(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }
}
