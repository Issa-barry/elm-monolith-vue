<?php

namespace App\Http\Controllers\Comptabilite;

use App\Enums\NatureMouvementFonds;
use App\Enums\StatutMouvementFonds;
use App\Http\Controllers\Controller;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use App\Models\Site;
use App\Models\User;
use App\Services\SavedFilterService;
use App\Services\SiteScopeService;
use App\Services\Tresorerie\MouvementFondsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MouvementFondsController extends Controller
{
    public function __construct(
        private readonly MouvementFondsService $service,
        private readonly SiteScopeService $siteScope,
        private readonly SavedFilterService $savedFilterService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', MouvementFonds::class);

        $request->validate(['saved_view' => ['nullable', 'ulid']]);
        $savedView = $this->savedFilterService->applyToRequest($request, 'mouvements-fonds');

        $user = auth()->user();
        $orgId = $user->organization_id;
        $toutesAgences = $user->voitToutesLesAgences();

        $query = $this->mouvementsVisibles($orgId, $user, $toutesAgences)
            ->with([
                'siteOrigine:id,nom', 'siteDestination:id,nom',
                'compteTresorerieOrigine:id,libelle', 'compteTresorerieDestination:id,libelle,agent_id', 'compteTresorerieDestination.agent.personne',
                'expediteur.personne', 'receptionnaire.personne',
                'lignesReglement.encaissement.facture.commande.client',
            ]);

        if ($statut = $request->input('statut')) {
            $query->where('statut', $statut);
        }

        if ($nature = NatureMouvementFonds::tryFrom((string) $request->input('nature'))) {
            $query->where('nature', $nature->value);
        }

        if ($toutesAgences && $siteIds = array_filter((array) $request->input('site_ids', []))) {
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

        // Une caisse peut être l'origine OU la destination d'un mouvement : sans précision de position,
        // les deux comptent. Le rôle n'a de sens qu'avec une caisse choisie, sinon il est ignoré.
        $caisseId = $this->filtreScalaire($request, 'caisse_id');
        $caisseRole = $this->filtreScalaire($request, 'caisse_role');
        if ($caisseId === '' || ! in_array($caisseRole, ['origine', 'destination'], true)) {
            $caisseRole = '';
        }

        if ($caisseId !== '') {
            $query->where(function ($q) use ($caisseId, $caisseRole) {
                if ($caisseRole !== 'destination') {
                    $q->orWhere('compte_tresorerie_origine_id', $caisseId);
                }
                if ($caisseRole !== 'origine') {
                    $q->orWhere('compte_tresorerie_destination_id', $caisseId);
                }
            });
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
            // Frais de l'opérateur, en sus du montant (transfert Mobile Money, ADR 0027) — null ailleurs.
            'frais' => $m->frais !== null ? (float) $m->frais : null,
            'reference_externe' => $m->reference_externe,
            'statut' => $m->statut->value,
            'statut_label' => $m->statut->label(),
            'date_envoi' => $m->date_envoi?->toDateString(),
            'date_reception' => $m->date_reception?->toDateString(),
            'expediteur' => $m->expediteur?->name,
            'receptionnaire' => $m->receptionnaire?->name,
            'created_at' => $m->created_at->toDateString(),
            'confirme_par_expediteur' => $m->confirmeParExpediteur(),
            // Règlement inter-agences (ADR 0012) : les encaissements précis qu'il reverse — vide pour
            // toute autre nature.
            'encaissements_regles' => $m->isReglementAgences()
                ? $m->lignesReglement->map(fn ($ligne) => [
                    'id' => $ligne->id,
                    'facture_reference' => $ligne->encaissement?->facture?->reference,
                    'commande_id' => $ligne->encaissement?->facture?->commande_vente_id,
                    'client_nom' => $ligne->encaissement?->facture?->commande?->client?->nom_complet,
                    'date_encaissement' => $ligne->encaissement?->date_encaissement?->toDateString(),
                    'montant' => (float) $ligne->montant,
                ])->values()
                : [],
            'detail_reglement_url' => $m->isReglementAgences()
                ? route('comptabilite.tresorerie.inter-agences.show', [$m->site_origine_id, $m->site_destination_id], false)
                : null,
            ...$this->actions($m, $user),
            'beneficiaire' => $m->isApprovisionnement() ? $m->compteTresorerieDestination?->agent?->name : null,
            'envoye_le' => $m->sent_at?->toIso8601String(),
            'recu_le' => $m->received_at?->toIso8601String(),
        ]);

        return Inertia::render('Comptabilite/MouvementsFonds/Index', [
            'mouvements' => $mouvements,
            'saved_view' => $savedView,
            'filters' => [
                'statut' => $request->input('statut', ''),
                'nature' => $request->input('nature', ''),
                'search' => $request->input('search', ''),
                'site_ids' => array_values(array_filter((array) $request->input('site_ids', []))),
                'site_origine_id' => $siteOrigineId,
                'site_destination_id' => $siteDestinationId,
                'caisse_id' => $caisseId,
                'caisse_role' => $caisseRole,
                'montant_min' => $montantMin,
                'montant_max' => $montantMax,
            ],
            'statut_options' => StatutMouvementFonds::options(),
            'nature_options' => NatureMouvementFonds::options(),
            'sites' => $this->sitesDisponibles($orgId, $user),
            'sites_mouvements' => $this->sitesOrganisation($orgId),
            'caisses_filtre' => $this->caissesFiltre($orgId, $user, $toutesAgences),
            'is_admin' => $toutesAgences,
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

        $user = auth()->user();
        $orgId = $user->organization_id;
        $sites = $this->sitesDisponibles($orgId, $user, pourCreation: true);
        // Même agence en origine et en destination = approvisionnement de la caisse d'un agent
        // (ADR 0018) ou dépôt en banque (ADR 0026), réservés à `tresorerie.envoyer`
        // (CompteTresoreriePolicy::approvisionner() / deposerEnBanque()). Les banques proposées
        // sont celles de `comptes_tresorerie` (supports actifs de l'agence, avec leur type).
        $peutApprovisionner = $user->can('tresorerie.envoyer');

        return Inertia::render('Comptabilite/MouvementsFonds/Create', [
            'sites' => $sites,
            'comptes_tresorerie' => CompteTresorerie::forOrg($orgId)->actifs()->agence()->get(['id', 'site_id', 'libelle', 'type']),
            'peut_approvisionner' => $peutApprovisionner,
            'caisses_agents' => $peutApprovisionner
                ? CompteTresorerie::forOrg($orgId)->approvisionnablesPar($user)
                    ->whereIn('site_id', array_column($sites, 'value'))
                    ->with('agent.personne')
                    ->orderBy('libelle')
                    ->get(['id', 'site_id', 'libelle', 'agent_id'])
                    ->map(fn (CompteTresorerie $c) => [
                        'id' => $c->id,
                        'site_id' => $c->site_id,
                        'libelle' => $c->libelle,
                        'agent' => $c->agent?->name,
                        'est_ma_caisse' => $c->agent_id === $user->id,
                    ])->values()
                : [],
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

        // Même agence des deux côtés : ce n'est pas un transfert entre agences mais un mouvement
        // interne — approvisionnement de la caisse d'un agent (ADR 0018) ou dépôt en banque
        // (ADR 0026) — avec ses propres règles.
        $siteOrigineId = $request->input('site_origine_id');
        if (is_string($siteOrigineId) && $siteOrigineId !== '' && $siteOrigineId === $request->input('site_destination_id')) {
            return $this->mouvementDansLaMemeAgence($request);
        }

        $data = $request->validate([
            'site_origine_id' => ['required', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
            'site_destination_id' => ['required', 'different:site_origine_id', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
            'compte_tresorerie_origine_id' => ['required', Rule::exists('compta_supports_tresorerie', 'id')->where('organization_id', $orgId)],
            // Entre agences, le support de destination n'est pas saisi à la création : c'est le
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

    /**
     * « Nouveau mouvement de fonds » avec la même agence en origine et en destination. Le support
     * de destination est choisi ici, le mouvement est créé ET envoyé (pas de brouillon), et c'est
     * ce support qui décide de l'opération :
     *  - caisse d'un agent : approvisionnement (ADR 0018), second point d'entrée de celui de
     *    l'écran Supports — CompteTresoreriePolicy::approvisionner(),
     *    MouvementFondsService::approvisionnerCaisseAgent(), réception réservée à l'agent titulaire ;
     *  - tout autre support : dépôt en banque (ADR 0026) depuis une caisse ou un Mobile Money de
     *    l'agence — CompteTresoreriePolicy::deposerEnBanque(),
     *    MouvementFondsService::deposerEnBanque(), qui refuse une destination autre qu'une banque.
     */
    private function mouvementDansLaMemeAgence(Request $request)
    {
        $user = auth()->user();
        $orgId = $user->organization_id;
        $support = Rule::exists('compta_supports_tresorerie', 'id')->where('organization_id', $orgId);

        $data = $request->validate([
            'site_origine_id' => ['required', Rule::exists('sites', 'id')->where('organization_id', $orgId)],
            'compte_tresorerie_origine_id' => ['required', $support],
            'compte_tresorerie_destination_id' => ['required', $support],
            'montant' => ['required', 'numeric', 'min:0.01'],
            'commentaire' => ['nullable', 'string', 'max:500'],
            // Bordereau de dépôt : sans objet pour un approvisionnement, qui les ignore.
            'reference_externe' => ['nullable', 'string', 'max:100'],
            'justificatif' => ['nullable', 'file', 'max:10240'],
        ], [
            'compte_tresorerie_destination_id.required' => 'Choisissez le support de destination : une banque de l\'agence ou la caisse d\'un agent.',
            'compte_tresorerie_destination_id.exists' => 'Support de destination introuvable.',
        ]);

        $source = CompteTresorerie::forOrg($orgId)->findOrFail($data['compte_tresorerie_origine_id']);
        if ($source->site_id !== $data['site_origine_id']) {
            throw ValidationException::withMessages([
                'compte_tresorerie_origine_id' => 'Le support de trésorerie choisi ne correspond pas au site sélectionné.',
            ]);
        }

        $versCaisseAgent = CompteTresorerie::forOrg($orgId)->findOrFail($data['compte_tresorerie_destination_id'])->isDediee();
        $this->authorize($versCaisseAgent ? 'approvisionner' : 'deposerEnBanque', $source);

        if ($versCaisseAgent) {
            $mouvement = $this->service->approvisionnerCaisseAgent(
                $orgId,
                $source,
                $data['compte_tresorerie_destination_id'],
                (float) $data['montant'],
                $data['commentaire'] ?? null,
                $user->id,
            );

            return redirect()->route('comptabilite.tresorerie.mouvements.index')
                ->with('success', "Approvisionnement {$mouvement->reference} envoyé : en attente de confirmation de réception par l'agent.");
        }

        $justificatif = $request->file('justificatif')?->store('tresorerie/justificatifs', 'public');

        try {
            $mouvement = $this->service->deposerEnBanque(
                $orgId,
                $source,
                $data['compte_tresorerie_destination_id'],
                (float) $data['montant'],
                $data['commentaire'] ?? null,
                $user->id,
                ['reference_externe' => $data['reference_externe'] ?? null, 'justificatif_path' => $justificatif ?: null],
            );
        } catch (ValidationException $e) {
            // Dépôt refusé (solde, support) : le bordereau déjà enregistré ne doit pas rester orphelin.
            if ($justificatif) {
                Storage::disk('public')->delete($justificatif);
            }

            throw $e;
        }

        return redirect()->route('comptabilite.tresorerie.mouvements.index')
            ->with('success', "Dépôt en banque {$mouvement->reference} envoyé : en attente de confirmation de réception.");
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

        // Un mouvement interne à une agence (versement, approvisionnement, dépôt en banque) a sa
        // destination fixée à l'envoi : la confirmation ne demande aucun choix (le service refuse
        // de toute façon un autre support).
        $destinationId = $mouvement->destinationFixeeALEnvoi()
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

    /**
     * Actions proposées sur une ligne. L'état du mouvement est vérifié EXPLICITEMENT en plus de la
     * policy : le Gate::before du super admin passe avant elle et afficherait sinon toutes les
     * actions sur chaque ligne, y compris terminée. Approvisionnement (ADR 0018) : réception et
     * contestation réservées à l'agent bénéficiaire, calculées sans le Gate. Le service reste la
     * garantie réelle de chacune de ces règles.
     *
     * @return array<string, bool>
     */
    private function actions(MouvementFonds $m, User $user): array
    {
        $approvisionnement = $m->isApprovisionnement();

        return [
            'peut_envoyer' => $m->isBrouillon() && $user->can('envoyer', $m),
            'peut_recevoir' => ($m->isEnvoye() || $m->isConteste())
                && ($approvisionnement ? $m->receptionReserveeA($user) : $user->can('recevoir', $m)),
            'peut_annuler' => $m->isBrouillon() && $user->can('annuler', $m),
            'peut_contester' => $m->isEnvoye()
                && ($approvisionnement ? $m->receptionReserveeA($user, 'tresorerie.rejeter') : $user->can('contester', $m)),
            'peut_confirmer_retour' => $m->isConteste()
                && ! ($approvisionnement && $user->id === $m->beneficiaireId())
                && $user->can('confirmerRetour', $m),
        ];
    }

    /**
     * Agences proposées : celles que l'utilisateur consulte (filtre de la liste), ou — pour le
     * formulaire de création — celles auxquelles il est rattaché (la vision 360° n'élargit pas l'écriture).
     *
     * @return list<array{value:string,label:string}>
     */
    private function sitesDisponibles(string $orgId, $user, bool $pourCreation = false): array
    {
        $query = Site::where('organization_id', $orgId)->orderBy('nom');

        if ($pourCreation) {
            if (! $user->isAdmin()) {
                $query->whereIn('id', $this->siteScope->assignedSiteIds($user));
            }
        } elseif (! $user->voitToutesLesAgences()) {
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

    /** Mouvements de l'organisation que l'utilisateur a le droit de voir (périmètre limité à ses agences : ceux qui les touchent). */
    private function mouvementsVisibles(string $orgId, $user, bool $toutesAgences): Builder
    {
        $query = MouvementFonds::where('organization_id', $orgId);

        if (! $toutesAgences) {
            $siteIds = $this->siteScope->accessibleSiteIds($user);
            $query->where(fn ($q) => $q->whereIn('site_origine_id', $siteIds)->orWhereIn('site_destination_id', $siteIds));
        }

        return $query;
    }

    /**
     * Caisses proposées par le filtre « Caisse » : celles qui figurent, en origine ou en destination, sur un
     * mouvement visible par l'utilisateur — jamais une caisse d'une agence hors de son périmètre. Deux caisses de
     * même libellé (ex. « Caisse-espèce » dans deux agences) sont distinguées par leur agence.
     *
     * @return list<array{value: string, label: string}>
     */
    private function caissesFiltre(string $orgId, $user, bool $toutesAgences): array
    {
        $visibles = $this->mouvementsVisibles($orgId, $user, $toutesAgences);

        $ids = (clone $visibles)->whereNotNull('compte_tresorerie_origine_id')->distinct()->pluck('compte_tresorerie_origine_id')
            ->merge((clone $visibles)->whereNotNull('compte_tresorerie_destination_id')->distinct()->pluck('compte_tresorerie_destination_id'))
            ->unique()->values();

        $supports = CompteTresorerie::forOrg($orgId)->whereIn('id', $ids)->with('site:id,nom')->orderBy('libelle')->get(['id', 'site_id', 'libelle']);
        $parLibelle = $supports->countBy('libelle');

        return $supports->map(fn (CompteTresorerie $s) => [
            'value' => $s->id,
            'label' => $parLibelle[$s->libelle] > 1 && $s->site ? "{$s->libelle} · {$s->site->nom}" : $s->libelle,
        ])->values()->all();
    }

    private function filtreScalaire(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }
}
