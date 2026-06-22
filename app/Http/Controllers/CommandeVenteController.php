<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\MotifAnnulation;
use App\Enums\ProduitStatut;
use App\Enums\ProduitType;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Enums\StatutFactureVente;
use App\Jobs\NotifierLivreursCommandeVenteJob;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\AuditLogService;
use App\Services\CommandeVenteActiviteService;
use App\Services\CommandeVenteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CommandeVenteController extends Controller
{
    private const DATE_DISPLAY_FORMAT = 'd/m/Y';

    private const LIGNES_REQUIRED_MESSAGE = 'Au moins une ligne de commande est requise.';

    private const UNIT_PRICE_UPDATE_PERMISSION = 'ventes.prix.update';

    public function __construct(private readonly AuditLogService $auditService) {}

    // ── Check solvabilité ─────────────────────────────────────────────────────

    public function checkSolvabilite(Request $request): JsonResponse
    {
        $request->validate([
            'vehicule_id' => 'nullable|exists:vehicules,id',
            'client_id' => 'nullable|exists:clients,id',
        ]);

        $orgId = auth()->user()->organization_id;
        $vehiculeId = $request->input('vehicule_id');
        $clientId = $request->input('client_id');

        if (! $vehiculeId && ! $clientId) {
            return response()->json($this->emptySolvabilite());
        }

        $query = FactureVente::where('organization_id', $orgId)
            ->whereIn('statut_facture', [StatutFactureVente::IMPAYEE->value, StatutFactureVente::PARTIEL->value])
            ->with('encaissements')
            ->orderByDesc('created_at');

        if ($vehiculeId) {
            $query->where('vehicule_id', $vehiculeId);
        } else {
            $query->whereHas('commande', fn ($q) => $q->where('client_id', $clientId));
        }

        $factures = $query->get();

        if ($factures->isEmpty()) {
            return response()->json($this->emptySolvabilite());
        }

        $totalRemaining = $factures->sum(fn ($f) => $f->montant_restant);
        $totalEncaisse = $factures->sum(fn ($f) => $f->montant_encaisse);
        $hasImpayee = $factures->contains(fn ($f) => $f->statut_facture === StatutFactureVente::IMPAYEE);
        $derniere = $factures->first();

        return response()->json([
            'has_debt' => true,
            'status' => $hasImpayee ? 'impaye' : 'partiel',
            'unpaid_invoices_count' => $factures->count(),
            'total_remaining' => (int) round($totalRemaining),
            'total_encaisse' => (int) round($totalEncaisse),
            'last_invoice_reference' => $derniere->reference,
            'last_invoice_date' => $derniere->created_at?->format('Y-m-d'),
            'factures' => $factures->map(fn ($f) => [
                'commande_id' => $f->commande_vente_id,
                'reference' => $f->reference,
                'date' => $f->created_at?->format('Y-m-d'),
                'montant' => (int) round((float) $f->montant_net),
                'encaisse' => (int) round($f->montant_encaisse),
                'restant' => (int) round($f->montant_restant),
                'statut' => $f->statut_facture->value,
                'statut_label' => $f->statut_facture->label(),
            ])->values(),
        ]);
    }

    private function emptySolvabilite(): array
    {
        return [
            'has_debt' => false,
            'status' => 'aucun',
            'unpaid_invoices_count' => 0,
            'total_remaining' => 0,
            'total_encaisse' => 0,
            'last_invoice_reference' => null,
            'last_invoice_date' => null,
            'factures' => [],
        ];
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CommandeVente::class);

        $user = auth()->user();
        $orgId = $user->organization_id;

        $periode = $request->input('periode', 'all');
        $statuts = array_values(array_filter((array) $request->input('statuts', [])));
        $statutFacture = $request->input('statut_facture');
        $statutCommission = $request->input('statut_commission');
        $siteIds = array_values(array_filter((array) $request->input('site_ids', [])));
        $dateDebut = $request->input('date_debut');
        $dateFin = $request->input('date_fin');
        $vehicule = $request->input('vehicule');
        $proprietaire = $request->input('proprietaire');
        $livreur = $request->input('livreur');
        $numeroCommande = $request->input('numero_commande');
        $client = $request->input('client');

        $query = CommandeVente::with([
            'vehicule.proprietaire',
            'vehicule.equipe.livreurs',
            'client',
            'site',
            'facture.encaissements.creator',
        ])
            ->where('organization_id', $orgId)
            ->orderByDesc('created_at');

        if ($user->isAdmin()) {
            if (! empty($siteIds)) {
                $query->whereIn('site_id', $siteIds);
            }
        } else {
            $userSiteIds = $user->sites()->pluck('sites.id');
            if ($userSiteIds->isNotEmpty()) {
                $query->whereIn('site_id', $userSiteIds);
            }
        }

        if ($dateDebut || $dateFin) {
            if ($dateDebut) {
                $query->whereDate('created_at', '>=', $dateDebut);
            }
            if ($dateFin) {
                $query->whereDate('created_at', '<=', $dateFin);
            }
        } else {
            match ($periode) {
                'today' => $query->whereDate('created_at', Carbon::today()),
                'week' => $query->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]),
                'month' => $query->whereYear('created_at', Carbon::now()->year)->whereMonth('created_at', Carbon::now()->month),
                default => null,
            };
        }

        if (! empty($statuts)) {
            $query->whereIn('statut', $statuts);
        }

        if ($statutFacture) {
            $query->whereHas('facture', fn ($q) => $q->where('statut_facture', $statutFacture));
        }

        if ($statutCommission) {
            $query->whereHas('commissions', fn ($q) => $q->where('statut', $statutCommission));
        }

        if ($numeroCommande) {
            $query->where('reference', 'like', "%{$numeroCommande}%");
        }

        if ($vehicule) {
            $query->whereHas('vehicule', function ($q) use ($vehicule) {
                $q->where('nom_vehicule', 'like', "%{$vehicule}%")
                    ->orWhere('immatriculation', 'like', "%{$vehicule}%");
            });
        }

        if ($vehiculeNom = $request->input('vehicule_nom')) {
            $query->whereHas('vehicule', fn ($q) => $q->where('nom_vehicule', 'like', "%{$vehiculeNom}%"));
        }

        if ($vehiculeImmat = $request->input('vehicule_immatriculation')) {
            $query->whereHas('vehicule', fn ($q) => $q->where('immatriculation', 'like', "%{$vehiculeImmat}%"));
        }

        if ($proprietaire) {
            $query->whereHas('vehicule.proprietaire', function ($q) use ($proprietaire) {
                $q->where('nom', 'like', "%{$proprietaire}%")
                    ->orWhere('prenom', 'like', "%{$proprietaire}%")
                    ->orWhere('telephone', 'like', "%{$proprietaire}%");
            });
        }

        if ($proprietaireNom = $request->input('proprietaire_nom')) {
            $query->whereHas('vehicule.proprietaire', fn ($q) => $q->where('nom', 'like', "%{$proprietaireNom}%")
                ->orWhere('prenom', 'like', "%{$proprietaireNom}%"));
        }

        if ($proprietaireTel = $request->input('proprietaire_telephone')) {
            $query->whereHas('vehicule.proprietaire', fn ($q) => $q->where('telephone', 'like', "%{$proprietaireTel}%"));
        }

        if ($livreur) {
            $query->whereHas('vehicule.equipe.livreurs', function ($q) use ($livreur) {
                $q->where('livreurs.nom', 'like', "%{$livreur}%")
                    ->orWhere('livreurs.prenom', 'like', "%{$livreur}%")
                    ->orWhere('livreurs.telephone', 'like', "%{$livreur}%");
            });
        }

        if ($livreurNom = $request->input('livreur_nom')) {
            $query->whereHas('vehicule.equipe.livreurs', fn ($q) => $q->where('livreurs.nom', 'like', "%{$livreurNom}%"));
        }

        if ($livreurPrenom = $request->input('livreur_prenom')) {
            $query->whereHas('vehicule.equipe.livreurs', fn ($q) => $q->where('livreurs.prenom', 'like', "%{$livreurPrenom}%"));
        }

        if ($livreurTel = $request->input('livreur_telephone')) {
            $query->whereHas('vehicule.equipe.livreurs', fn ($q) => $q->where('livreurs.telephone', 'like', "%{$livreurTel}%"));
        }

        if ($livreurRole = $request->input('livreur_role')) {
            $query->whereHas('vehicule.equipe.membres', fn ($q) => $q->where('role', $livreurRole));
        }

        if ($client) {
            $query->whereHas('client', function ($q) use ($client) {
                $q->where('nom', 'like', "%{$client}%")
                    ->orWhere('prenom', 'like', "%{$client}%")
                    ->orWhere('telephone', 'like', "%{$client}%");
            });
        }

        if ($clientNom = $request->input('client_nom')) {
            $query->whereHas('client', fn ($q) => $q->where('nom', 'like', "%{$clientNom}%")
                ->orWhere('prenom', 'like', "%{$clientNom}%"));
        }

        if ($clientTel = $request->input('client_telephone')) {
            $query->whereHas('client', fn ($q) => $q->where('telephone', 'like', "%{$clientTel}%"));
        }

        $commandes = $query->get();
        $nonAnnulees = $commandes->filter(fn ($c) => ! $c->isAnnulee());
        $cloturees = $commandes->filter(fn ($c) => $c->isCloturee());

        $totaux = [
            'total_montant' => (float) $nonAnnulees->sum('total_commande'),
            'nb_total' => $nonAnnulees->count(),
            'total_a_encaisser' => (float) $commandes
                ->filter(fn ($c) => $c->facture && ! $c->facture->isAnnulee())
                ->sum(fn ($c) => (float) $c->facture->montant_restant),
            'deja_paye' => (float) $commandes
                ->filter(fn ($c) => $c->facture && ! $c->facture->isAnnulee())
                ->sum(fn ($c) => (float) $c->facture->montant_encaisse),
            'nb_cloturees' => $cloturees->count(),
            'montant_cloturees' => (float) $cloturees->sum('total_commande'),
        ];

        $mapped = $commandes->map(fn (CommandeVente $c) => $this->mapCommandeForIndex($c, $user));

        $sites = $user->isAdmin()
            ? Site::where('organization_id', $orgId)->orderBy('nom')->get()
                ->map(fn ($s) => ['id' => $s->id, 'nom' => $s->nom])->values()
            : [];

        return Inertia::render('Ventes/Index', [
            'commandes' => $mapped->values(),
            'totaux' => $totaux,
            'periode' => $periode,
            'statuts_actifs' => $statuts,
            'statuts' => StatutCommandeVente::options(),
            'sites' => $sites,
            'is_admin' => $user->isAdmin(),
            'filters' => [
                'site_ids' => $siteIds,
                'date_debut' => $dateDebut,
                'date_fin' => $dateFin,
                'statut_facture' => $statutFacture,
                'statut_commission' => $statutCommission,
                'vehicule' => $vehicule,
                'proprietaire' => $proprietaire,
                'livreur' => $livreur,
                'numero_commande' => $numeroCommande,
                'client' => $client,
            ],
        ]);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function create(): Response
    {
        $this->authorize('create', CommandeVente::class);

        $orgId = auth()->user()->organization_id;

        return Inertia::render('Ventes/Create', [
            'produits' => $this->produitsActifs($orgId),
            'vehicules' => $this->vehiculesActifs($orgId),
            'clients' => $this->clientsActifs($orgId),
            'user_site' => $this->getUserSite(),
            'can_modifier_qte' => auth()->user()->can('ventes.qte.update'),
            'autoriser_saisie_dessous_qte_max' => Parametre::isVentesAutorisationSaisieDessousQteMax($orgId),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', CommandeVente::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $userSite = $this->getUserSiteModel();

        $data = $request->validate($this->commandeValidationRules(), $this->commandeValidationMessages());

        $this->ensureVehiculeOrClientSelected($data);
        $this->ensureQuantiteMatchesVehiculeCapacity($data);
        $this->enforcePrixVentePolicy($data, null);

        [$lignesData, $totalCommande] = $this->buildLignesDataAndTotal($data['lignes']);

        $commande = CommandeVente::create([
            'organization_id' => $orgId,
            'site_id' => $userSite->id,
            'vehicule_id' => $data['vehicule_id'] ?? null,
            'client_id' => $data['client_id'] ?? null,
            'total_commande' => $totalCommande,
            'created_by' => auth()->id(),
        ]);

        foreach ($lignesData as $ligneDatum) {
            $commande->lignes()->create($ligneDatum);
        }

        $commande->load(['lignes.produit', 'vehicule', 'client']);
        $this->auditService->record($commande, AuditEvent::CREATED, auth()->user(), null, $this->commandeSnapshot($commande));

        if ($commande->vehicule_id && $commande->lignes->isNotEmpty()) {
            CommandeVenteService::confirmer($commande);
            CommandeVenteActiviteService::log($commande, 'creation_confirmee');

            return redirect()->route('ventes.show', $commande)->with('success', 'Commande créée et confirmée. En attente de chargement.');
        }

        // Vente directe client — passe en FACTURATION + crée la facture
        CommandeVenteService::creerFactureDirecte($commande);
        CommandeVenteActiviteService::log($commande, 'creation_directe');

        return redirect()->route('ventes.show', $commande)->with('success', 'Commande créée. Facture générée — en attente d\'encaissement.');
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function show(CommandeVente $vente): Response
    {
        $this->authorize('view', $vente);

        $commande = $vente;
        $commande->load(['vehicule.proprietaire', 'vehicule.typeVehicule', 'vehicule.equipe.livreurs', 'client', 'site', 'lignes.produit', 'createdBy', 'facture.encaissements.creator', 'commissions', 'activites.user']);

        $commande->cloturerSiComplete();
        $commande->refresh();

        $user = auth()->user();
        $facture = $commande->facture;

        $vehicule = $commande->vehicule;
        $equipe = $vehicule?->equipe;
        $chauffeur = $equipe?->livreurs->first(fn ($l) => ($l->pivot->role ?? null) === 'chauffeur');
        $convoyeurs = $equipe ? $equipe->livreurs->filter(fn ($l) => ($l->pivot->role ?? null) !== 'chauffeur') : collect();

        $lignes = $commande->lignes->map(fn ($l) => [
            'id' => $l->id,
            'produit_id' => $l->produit_id,
            'produit_nom' => $l->produit?->nom,
            'quantite_demandee' => $l->quantite_demandee,
            'quantite_chargee' => $l->quantite_chargee,
            'quantite_livree' => $l->quantite_livree,
            'type_ecart' => $l->type_ecart?->value,
            'type_ecart_label' => $l->type_ecart?->label(),
            'commentaire_ecart' => $l->commentaire_ecart,
            'ecart_chargement' => $l->ecart_chargement,
            'prix_usine_snapshot' => (float) $l->prix_usine_snapshot,
            'prix_vente_snapshot' => (float) $l->prix_vente_snapshot,
            'total_ligne' => (float) $l->total_ligne,
        ]);

        $historiques = AuditLog::where('organization_id', $commande->organization_id)
            ->where('auditable_type', CommandeVente::class)
            ->where('auditable_id', $commande->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event_code' => $log->event_code,
                'event_label' => $log->event_label,
                'actor_name' => $log->actor_name_snapshot ?? 'Système',
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'created_at' => $log->created_at->format('d/m/Y H:i'),
            ]);

        $activites = $commande->activites->map(fn ($a) => [
            'id' => $a->id,
            'action' => $a->action,
            'action_label' => $a->action_label,
            'user_name' => $a->user?->name ?? 'Système',
            'created_at' => $a->created_at->format('d/m/Y H:i'),
            'details' => $a->details,
        ]);

        return Inertia::render('Ventes/Show', [
            'historiques' => $historiques,
            'activites' => $activites,
            'commande' => [
                'id' => $commande->id,
                'reference' => $commande->reference,
                'statut' => $commande->statut?->value,
                'statut_label' => $commande->statut_label,
                'statut_color' => $commande->statut?->color(),
                'total_commande' => (float) $commande->total_commande,
                'vehicule_nom' => $commande->vehicule?->nom_vehicule,
                'vehicule_detail' => $vehicule ? [
                    'nom' => $vehicule->nom_vehicule,
                    'immatriculation' => $vehicule->immatriculation,
                    'type' => $vehicule->typeVehicule?->nom,
                    'capacite_packs' => $vehicule->capacite_packs,
                    'proprietaire_nom' => $vehicule->proprietaire
                        ? trim($vehicule->proprietaire->prenom.' '.$vehicule->proprietaire->nom)
                        : null,
                    'proprietaire_telephone' => $vehicule->proprietaire?->telephone,
                    'proprietaire_code_phone_pays' => $vehicule->proprietaire?->code_phone_pays,
                ] : null,
                'livreur_nom' => $chauffeur ? trim($chauffeur->prenom.' '.$chauffeur->nom) : null,
                'livreur_telephone' => $chauffeur?->telephone,
                'equipe_detail' => $equipe ? [
                    'nom' => $equipe->nom,
                    'taux_commission_proprietaire' => $equipe->taux_commission_proprietaire !== null
                        ? (float) $equipe->taux_commission_proprietaire
                        : null,
                    'chauffeur' => $chauffeur ? [
                        'nom' => trim($chauffeur->prenom.' '.$chauffeur->nom),
                        'telephone' => $chauffeur->telephone,
                    ] : null,
                    'convoyeurs' => $convoyeurs->map(fn ($l) => [
                        'nom' => trim($l->prenom.' '.$l->nom),
                        'telephone' => $l->telephone,
                    ])->values(),
                ] : null,
                'client_nom' => $commande->client ? trim($commande->client->prenom.' '.$commande->client->nom) : null,
                'client_detail' => $commande->client ? [
                    'nom' => trim($commande->client->prenom.' '.$commande->client->nom),
                    'telephone' => $commande->client->telephone,
                    'code_phone_pays' => $commande->client->code_phone_pays,
                    'ville' => $commande->client->ville,
                    'adresse' => $commande->client->adresse,
                    'cashback_eligible' => (bool) $commande->client->cashback_eligible,
                ] : null,
                'site_nom' => $commande->site?->nom,
                'motif_annulation' => $commande->motif_annulation,
                'annulee_at' => $commande->annulee_at?->toISOString(),
                'a_charger_at' => $commande->a_charger_at?->format(self::DATE_DISPLAY_FORMAT),
                'chargement_demarre_at' => $commande->chargement_demarre_at?->format(self::DATE_DISPLAY_FORMAT),
                'chargement_valide_at' => $commande->chargement_valide_at?->format(self::DATE_DISPLAY_FORMAT),
                'livree_at' => $commande->livree_at?->format(self::DATE_DISPLAY_FORMAT),
                'closed_at' => $commande->closed_at?->format(self::DATE_DISPLAY_FORMAT),
                'is_brouillon' => $commande->isBrouillon(),
                'is_a_charger' => $commande->isACharger(),
                'is_chargement_en_cours' => $commande->isChargementEnCours(),
                'is_livraison_en_cours' => $commande->isLivraisonEnCours(),
                'is_livree' => $commande->isLivree(),
                'is_facturation' => $commande->isFacturation(),
                'is_cloturee' => $commande->isCloturee(),
                'is_annulee' => $commande->isAnnulee(),
                'can_modifier' => $commande->isBrouillon() && $user->can('update', $commande),
                'can_confirmer' => $commande->isBrouillon() && $user->can('confirmer', $commande),
                'can_demarrer_chargement' => $commande->isACharger() && $user->can('demarrerChargement', $commande),
                'can_valider_chargement' => $commande->isChargementEnCours() && $user->can('validerChargement', $commande),
                'can_annuler' => $commande->statut->isAnnulable()
                    && (! $facture || (float) $facture->montant_encaisse === 0.0)
                    && $user->can('annuler', $commande),
                'can_encaisser' => $facture && ! $facture->isAnnulee()
                    && (float) $facture->montant_restant > 0
                    && $commande->isEncaissable()
                    && $user->can('update', $commande),
                'created_at' => $commande->created_at?->format(self::DATE_DISPLAY_FORMAT),
                'created_by' => $commande->createdBy?->name,
                'lignes' => $lignes,
            ],
            'facture' => $facture ? [
                'id' => $facture->id,
                'reference' => $facture->reference,
                'montant_net' => (float) $facture->montant_net,
                'montant_encaisse' => (float) $facture->montant_encaisse,
                'montant_restant' => (float) $facture->montant_restant,
                'statut' => $facture->statut_facture?->value,
                'statut_label' => $facture->statut_label,
                'encaissements' => $facture->encaissements->map(fn ($e) => [
                    'id' => $e->id,
                    'montant' => (float) $e->montant,
                    'date_encaissement' => $e->date_encaissement?->format(self::DATE_DISPLAY_FORMAT),
                    'heure' => $e->created_at?->format('H:i'),
                    'mode_paiement' => $e->mode_paiement?->value,
                    'mode_paiement_label' => $e->mode_paiement?->label(),
                    'note' => $e->note,
                    'created_by' => $e->creator?->name,
                ])->values(),
            ] : null,
            'commission_statut' => $this->getCommissionStatutGlobal($commande),
        ]);
    }

    // ── Edit ─────────────────────────────────────────────────────────────────

    public function edit(CommandeVente $vente): Response
    {
        $this->authorize('update', $vente);
        abort_if(! $vente->isBrouillon(), 403, 'Seule une commande en brouillon peut être modifiée.');

        $orgId = auth()->user()->organization_id;
        $vente->load(['lignes']);

        return Inertia::render('Ventes/Edit', [
            'commande' => [
                'id' => $vente->id,
                'reference' => $vente->reference,
                'vehicule_id' => $vente->vehicule_id,
                'client_id' => $vente->client_id,
                'lignes' => $vente->lignes->map(fn ($l) => [
                    'produit_id' => $l->produit_id,
                    'qte' => (int) $l->quantite_demandee,
                    'prix_vente' => (float) $l->prix_vente_snapshot,
                ]),
            ],
            'produits' => $this->produitsActifs($orgId),
            'vehicules' => $this->vehiculesActifs($orgId),
            'clients' => $this->clientsActifs($orgId),
            'user_site' => $this->getUserSite(),
            'can_modifier_qte' => auth()->user()->can('ventes.qte.update'),
            'autoriser_saisie_dessous_qte_max' => Parametre::isVentesAutorisationSaisieDessousQteMax($orgId),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function update(Request $request, CommandeVente $vente): RedirectResponse
    {
        $this->authorize('update', $vente);
        abort_if(! $vente->isBrouillon(), 403, 'Seule une commande en brouillon peut être modifiée.');

        $data = $request->validate($this->commandeValidationRules(), $this->commandeValidationMessages());

        $this->ensureVehiculeOrClientSelected($data);
        $this->ensureQuantiteMatchesVehiculeCapacity($data);
        $this->enforcePrixVentePolicy($data, $vente);

        $vente->load(['lignes.produit', 'vehicule', 'client']);
        $oldSnapshot = $this->commandeSnapshot($vente);

        [$lignesData, $totalCommande] = $this->buildLignesDataAndTotal($data['lignes']);

        $vente->update([
            'vehicule_id' => $data['vehicule_id'] ?? null,
            'client_id' => $data['client_id'] ?? null,
            'total_commande' => $totalCommande,
        ]);

        $vente->lignes()->delete();
        foreach ($lignesData as $ligneDatum) {
            $vente->lignes()->create($ligneDatum);
        }

        $vente->refresh()->load(['lignes.produit', 'vehicule', 'client']);
        $newSnapshot = $this->commandeSnapshot($vente);

        [$oldDiff, $newDiff] = $this->auditService->diff($oldSnapshot, $newSnapshot);
        if ($oldDiff !== null || $newDiff !== null) {
            $this->auditService->record($vente, AuditEvent::UPDATED, auth()->user(), $oldDiff, $newDiff);
        }

        return redirect()->route('ventes.show', $vente)->with('success', 'Commande mise à jour.');
    }

    // ── Valider : BROUILLON → A_CHARGER ──────────────────────────────────────

    public function valider(CommandeVente $commande_vente): RedirectResponse
    {
        $this->authorize('confirmer', $commande_vente);

        $oldStatut = $commande_vente->statut->value;

        try {
            CommandeVenteService::confirmer($commande_vente);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $this->auditService->record(
            $commande_vente,
            AuditEvent::VALIDATED,
            auth()->user(),
            ['statut' => $oldStatut],
            ['statut' => StatutCommandeVente::A_CHARGER->value],
        );

        CommandeVenteActiviteService::log($commande_vente, 'confirmee');

        if ($commande_vente->vehicule_id) {
            NotifierLivreursCommandeVenteJob::dispatch($commande_vente->id, $commande_vente->reference);
        }

        return back()->with('success', 'Commande confirmée. En attente de chargement.');
    }

    // ── Annuler ───────────────────────────────────────────────────────────────

    public function annuler(Request $request, CommandeVente $commande_vente): RedirectResponse
    {
        if (auth()->user()->cannot('annuler', $commande_vente)) {
            abort(403, "Vous n'êtes pas autorisé à annuler cette commande.");
        }

        $validCodes = implode(',', MotifAnnulation::validValues());

        $data = $request->validate([
            'motif_annulation_code' => ['required', 'string', "in:{$validCodes}"],
            'motif_annulation_detail' => ['nullable', 'string', 'max:2000', 'required_if:motif_annulation_code,autre'],
        ], [
            'motif_annulation_code.required' => "Le motif d'annulation est obligatoire.",
            'motif_annulation_code.in' => 'Le motif sélectionné est invalide.',
            'motif_annulation_detail.required_if' => "Veuillez préciser la raison de l'annulation.",
            'motif_annulation_detail.max' => 'La précision ne peut pas dépasser 2000 caractères.',
        ]);

        $motif = MotifAnnulation::from($data['motif_annulation_code'])
            ->toMotifString($data['motif_annulation_detail'] ?? '');

        $oldStatut = $commande_vente->statut->value;

        try {
            CommandeVenteService::annuler($commande_vente, $motif);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        $this->auditService->record(
            $commande_vente,
            AuditEvent::CANCELLED,
            auth()->user(),
            ['statut' => $oldStatut, 'motif_annulation' => null],
            ['statut' => StatutCommandeVente::ANNULEE->value, 'motif_annulation' => $motif],
        );

        CommandeVenteActiviteService::log($commande_vente, 'annulee', ['motif' => $motif]);

        return back()->with('success', 'Commande annulée.');
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    public function destroy(CommandeVente $vente): RedirectResponse
    {
        $this->authorize('delete', $vente);
        abort_unless($vente->isAnnulee(), 403, 'Seules les commandes annulées peuvent être supprimées.');

        $this->auditService->record(
            $vente,
            AuditEvent::DELETED,
            auth()->user(),
            ['reference' => $vente->reference, 'statut' => $vente->statut->value],
            null,
        );

        $vente->delete();

        return redirect()->route('ventes.index')->with('success', 'Commande supprimée.');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function getCommissionStatutGlobal(CommandeVente $commande): ?array
    {
        $commissions = $commande->commissions;
        if ($commissions->isEmpty()) {
            return null;
        }

        if ($commissions->every(fn ($c) => $c->statut === StatutCommission::CREEE)) {
            return ['value' => 'creee', 'label' => 'Créée'];
        }
        if ($commissions->every(fn ($c) => $c->statut === StatutCommission::PAYE)) {
            return ['value' => 'paye', 'label' => 'Payée'];
        }
        if ($commissions->some(fn ($c) => $c->statut === StatutCommission::PAYE || $c->statut === StatutCommission::PARTIEL)) {
            return ['value' => 'partiel', 'label' => 'Partiellement payée'];
        }

        return ['value' => 'impaye', 'label' => 'Impayée'];
    }

    private function mapCommandeForIndex(CommandeVente $c, mixed $user): array
    {
        return [
            'id' => $c->id,
            'reference' => $c->reference,
            'statut' => $c->statut?->value,
            'statut_label' => $c->statut_label,
            'statut_color' => $c->statut?->color(),
            'total_commande' => (float) $c->total_commande,
            'vehicule_nom' => $c->vehicule?->nom_vehicule,
            'vehicule_immatriculation' => $c->vehicule?->immatriculation,
            'chauffeur_nom' => $c->vehicule?->equipe?->livreurs
                ?->first(fn ($l) => ($l->pivot->role ?? null) === 'chauffeur')
                ?->nom_complet,
            'client_nom' => $c->client ? trim($c->client->prenom.' '.$c->client->nom) : null,
            'client_telephone' => $c->client?->telephone,
            'site_nom' => $c->site?->nom,
            'facture_id' => $c->facture?->id,
            'facture_statut' => $c->facture?->statut_facture?->value,
            'facture_statut_label' => $c->facture?->statut_facture?->label(),
            'facture_montant_encaisse' => $c->facture ? (float) $c->facture->montant_encaisse : null,
            'facture_montant_restant' => $c->facture ? (float) $c->facture->montant_restant : null,
            'encaissements' => $c->facture ? $c->facture->encaissements->map(fn ($e) => [
                'id' => $e->id,
                'montant' => (float) $e->montant,
                'date_encaissement' => $e->date_encaissement?->format(self::DATE_DISPLAY_FORMAT),
                'heure' => $e->created_at?->format('H:i'),
                'mode_paiement_label' => $e->mode_paiement?->label(),
                'created_by' => $e->creator?->name,
            ])->values() : [],
            'created_at' => $c->created_at?->format(self::DATE_DISPLAY_FORMAT),
            'is_annulee' => $c->isAnnulee(),
            'is_brouillon' => $c->isBrouillon(),
            'is_facturation' => $c->isFacturation(),
            'can_modifier' => $c->isBrouillon() && $user->can('update', $c),
            'can_confirmer' => $c->isBrouillon() && $user->can('confirmer', $c),
            'can_annuler' => $c->statut->isAnnulable()
                && (! $c->facture || (float) $c->facture->montant_encaisse === 0.0)
                && $user->can('annuler', $c),
        ];
    }

    private function commandeValidationRules(): array
    {
        return [
            'vehicule_id' => 'nullable|exists:vehicules,id',
            'client_id' => 'nullable|exists:clients,id',
            'lignes' => 'required|array|min:1',
            'lignes.*.produit_id' => 'required|exists:produits,id',
            'lignes.*.qte' => 'required|integer|min:1',
            'lignes.*.prix_vente' => 'required|numeric|min:0',
        ];
    }

    private function commandeValidationMessages(): array
    {
        return [
            'lignes.required' => self::LIGNES_REQUIRED_MESSAGE,
            'lignes.min' => self::LIGNES_REQUIRED_MESSAGE,
            'lignes.*.produit_id.required' => 'Le produit est obligatoire pour chaque ligne.',
            'lignes.*.produit_id.exists' => 'Le produit sélectionné est introuvable.',
            'lignes.*.qte.required' => 'La quantité est obligatoire pour chaque ligne.',
            'lignes.*.qte.min' => 'La quantité doit être supérieure à 0.',
            'lignes.*.prix_vente.required' => 'Le prix de vente est obligatoire pour chaque ligne.',
            'lignes.*.prix_vente.min' => 'Le prix de vente ne peut pas être négatif.',
        ];
    }

    private function ensureVehiculeOrClientSelected(array $data): void
    {
        if (! empty($data['vehicule_id']) || ! empty($data['client_id'])) {
            return;
        }

        throw ValidationException::withMessages([
            'vehicule_id' => 'Veuillez sélectionner un véhicule ou un client.',
            'client_id' => 'Veuillez sélectionner un véhicule ou un client.',
        ]);
    }

    private function ensureQuantiteMatchesVehiculeCapacity(array $data): void
    {
        if (empty($data['vehicule_id'])) {
            return;
        }

        $vehicule = Vehicule::query()->select(['id', 'capacite_packs'])->find($data['vehicule_id']);
        if (! $vehicule) {
            return;
        }

        if ($vehicule->capacite_packs === null) {
            throw ValidationException::withMessages([
                'vehicule_id' => 'Le véhicule sélectionné n\'a pas de capacité définie.',
            ]);
        }

        $qteTotale = collect($data['lignes'] ?? [])->sum(fn (array $ligne): int => (int) ($ligne['qte'] ?? 0));
        $capacite = (int) $vehicule->capacite_packs;

        if ($qteTotale > $capacite && ! auth()->user()->can('ventes.qte.update')) {
            throw ValidationException::withMessages([
                'lignes' => "La quantité totale ({$qteTotale} packs) dépasse la capacité du véhicule ({$capacite} packs maximum).",
            ]);
        }

        $orgId = auth()->user()->organization_id;
        if (! Parametre::isVentesAutorisationSaisieDessousQteMax($orgId) && $qteTotale < $capacite) {
            throw ValidationException::withMessages([
                'lignes' => "La quantité totale ({$qteTotale} packs) est inférieure à la capacité du véhicule ({$capacite} packs). Le chargement complet est obligatoire.",
            ]);
        }
    }

    private function enforcePrixVentePolicy(array $data, ?CommandeVente $commande): void
    {
        if (auth()->user()->can(self::UNIT_PRICE_UPDATE_PERMISSION)) {
            return;
        }

        $lignes = collect($data['lignes'] ?? []);
        if ($lignes->isEmpty()) {
            return;
        }

        $produitIds = $lignes->pluck('produit_id')->filter()->unique()->values()->all();
        $prixParProduit = Produit::query()
            ->whereIn('id', $produitIds)
            ->pluck('prix_vente', 'id')
            ->map(fn (mixed $prix): float => (float) $prix)
            ->toArray();

        $existingPrixParProduit = $this->existingPrixVenteByProduit($commande);

        foreach ($data['lignes'] as $index => $ligne) {
            $produitId = $ligne['produit_id'] ?? null;
            if (! $produitId) {
                continue;
            }

            $prixRecu = (float) ($ligne['prix_vente'] ?? 0);
            $prixAttendu = $existingPrixParProduit[$produitId] ?? ($prixParProduit[$produitId] ?? $prixRecu);

            if (abs($prixRecu - $prixAttendu) > 0.00001) {
                throw ValidationException::withMessages([
                    "lignes.{$index}.prix_vente" => 'Vous n\'etes pas autorisé à modifier le prix unitaire.',
                ]);
            }
        }
    }

    private function existingPrixVenteByProduit(?CommandeVente $commande): array
    {
        if (! $commande) {
            return [];
        }

        $commande->loadMissing('lignes');

        return $commande->lignes
            ->groupBy('produit_id')
            ->map(fn ($lignes): float => (float) $lignes->first()->prix_vente_snapshot)
            ->toArray();
    }

    private function buildLignesDataAndTotal(array $lignes): array
    {
        $lignesData = [];
        $totalCommande = 0;

        foreach ($lignes as $ligne) {
            $produit = Produit::findOrFail($ligne['produit_id']);
            $qte = (int) $ligne['qte'];
            $prixVente = (float) $ligne['prix_vente'];
            $totalLigne = $qte * $prixVente;

            $lignesData[] = [
                'produit_id' => $produit->id,
                'quantite_demandee' => $qte,
                'prix_usine_snapshot' => (float) $produit->prix_usine,
                'prix_vente_snapshot' => $prixVente,
                'total_ligne' => $totalLigne,
            ];

            $totalCommande += $totalLigne;
        }

        return [$lignesData, $totalCommande];
    }

    private function commandeSnapshot(CommandeVente $commande): array
    {
        return [
            'vehicule_id' => $commande->vehicule_id,
            'vehicule_nom' => $commande->vehicule?->nom_vehicule,
            'client_id' => $commande->client_id,
            'client_nom' => $commande->client ? trim($commande->client->prenom.' '.$commande->client->nom) : null,
            'total_commande' => (float) $commande->total_commande,
            'statut' => $commande->statut?->value,
            'lignes' => $commande->lignes->map(fn ($l) => [
                'produit_id' => $l->produit_id,
                'produit_nom' => $l->produit?->nom,
                'quantite_demandee' => (int) $l->quantite_demandee,
                'prix_vente_snapshot' => (float) $l->prix_vente_snapshot,
                'total_ligne' => (float) $l->total_ligne,
            ])->values()->all(),
        ];
    }

    private function getUserSite(): array
    {
        $site = $this->getUserSiteModel();

        return [
            'id' => $site->id,
            'nom' => $site->nom,
            'label' => ($site->type?->label() ?? '').' de '.$site->nom,
        ];
    }

    private function getUserSiteModel(): mixed
    {
        $site = auth()->user()
            ->sites()
            ->wherePivot('is_default', true)
            ->first(['sites.id', 'sites.nom', 'sites.type'])
            ?? auth()->user()->sites()->first(['sites.id', 'sites.nom', 'sites.type']);

        abort_if(! $site, 403, "Votre compte n'est rattaché à aucun site. Contactez votre administrateur.");

        return $site;
    }

    private function produitsActifs(string $orgId): Collection
    {
        return Produit::where('organization_id', $orgId)
            ->where('statut', ProduitStatut::ACTIF)
            ->whereIn('type', ProduitType::vendableValues())
            ->orderBy('nom')
            ->get()
            ->map(fn (Produit $p) => [
                'id' => $p->id,
                'nom' => $p->nom,
                'prix_vente' => (int) $p->prix_vente,
                'prix_usine' => (int) $p->prix_usine,
            ]);
    }

    private function vehiculesActifs(string $orgId): Collection
    {
        return Vehicule::with([
            'equipe.livreurs' => fn ($q) => $q->wherePivot('role', 'chauffeur'),
            'equipe.membres.livreur',
        ])
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->where('categorie', 'externe')
            ->orderBy('nom_vehicule')
            ->get()
            ->map(fn (Vehicule $v) => [
                'id' => $v->id,
                'nom_vehicule' => $v->nom_vehicule,
                'immatriculation' => $v->immatriculation,
                'capacite_packs' => $v->capacite_packs !== null ? (int) $v->capacite_packs : null,
                'livreur_nom' => ($l = $v->equipe?->livreurs->first())
                    ? trim($l->prenom.' '.$l->nom)
                    : null,
                'livreur_telephone' => $v->equipe?->membres
                    ->firstWhere('role', 'chauffeur')
                    ?->livreur?->telephone,
            ]);
    }

    private function clientsActifs(string $orgId): Collection
    {
        return Client::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('nom')
            ->get()
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'nom' => $c->nom,
                'prenom' => $c->prenom,
                'telephone' => $c->telephone,
            ]);
    }
}
