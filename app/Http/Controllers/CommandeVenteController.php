<?php

namespace App\Http\Controllers;

use App\Enums\ProduitStatut;
use App\Enums\ProduitType;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Produit;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CommandeVenteController extends Controller
{
    private const DATE_DISPLAY_FORMAT = 'd/m/Y';

    private const LIGNES_REQUIRED_MESSAGE = 'Au moins une ligne de commande est requise.';

    public function __construct(private readonly CommandeVenteService $service) {}

    // ── Index ─────────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CommandeVente::class);

        $orgId = auth()->user()->organization_id;
        $user = auth()->user();
        $periode = $request->input('periode', 'today');
        $statut = $request->input('statut', 'tous');

        $query = CommandeVente::with(['vehicule', 'client', 'site', 'facture.encaissements.creator'])
            ->where('organization_id', $orgId)
            ->orderByDesc('created_at');

        match ($periode) {
            'today' => $query->whereDate('created_at', Carbon::today()),
            'week' => $query->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]),
            'month' => $query->whereYear('created_at', Carbon::now()->year)->whereMonth('created_at', Carbon::now()->month),
            default => null,
        };

        if ($statut !== 'tous') {
            $query->where('statut', $statut);
        }

        $commandes = $query->get();

        $nonAnnulees = $commandes->filter(fn ($c) => ! $c->isAnnulee());
        $enCours = $commandes->filter(fn ($c) => $c->isEnCours());
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

        $mapped = $commandes->map(fn (CommandeVente $c) => [
            'id' => $c->id,
            'reference' => $c->reference,
            'statut' => $c->statut?->value,
            'statut_label' => $c->statut_label,
            'total_commande' => (float) $c->total_commande,
            'vehicule_nom' => $c->vehicule?->nom_vehicule,
            'client_nom' => $c->client ? trim($c->client->prenom.' '.$c->client->nom) : null,
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
            'is_en_cours' => $c->isEnCours(),
            'can_modifier' => $c->isBrouillon() && $user->can('update', $c),
            'can_valider' => $c->isBrouillon() && $user->can('update', $c),
            'can_annuler' => $c->isEnCours() && $user->can('annuler', $c),
        ]);

        return Inertia::render('Ventes/Index', [
            'commandes' => $mapped->values(),
            'totaux' => $totaux,
            'periode' => $periode,
            'statut' => $statut,
        ]);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function create(): Response
    {
        $this->authorize('create', CommandeVente::class);

        $orgId = auth()->user()->organization_id;

        $produits = Produit::where('organization_id', $orgId)
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

        $vehicules = Vehicule::with(['equipe.livreurs' => fn ($q) => $q->wherePivot('role', 'principal')])
            ->where('organization_id', $orgId)
            ->where('is_active', true)
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
            ]);

        $clients = Client::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('nom')
            ->get()
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'nom' => $c->nom,
                'prenom' => $c->prenom,
                'telephone' => $c->telephone,
            ]);

        // Site de l'utilisateur (rattachement obligatoire via user_sites)
        $userSite = auth()->user()
            ->sites()
            ->wherePivot('is_default', true)
            ->first(['sites.id', 'sites.nom', 'sites.type'])
            ?? auth()->user()->sites()->first(['sites.id', 'sites.nom', 'sites.type']);

        abort_if(
            ! $userSite,
            403,
            "Votre compte n'est rattaché à aucun site. Contactez votre administrateur."
        );

        return Inertia::render('Ventes/Create', [
            'produits' => $produits,
            'vehicules' => $vehicules,
            'clients' => $clients,
            'user_site' => [
                'id' => $userSite->id,
                'nom' => $userSite->nom,
                'label' => ($userSite->type?->label() ?? '').' de '.$userSite->nom,
            ],
        ]);
    }

    // ── Store : crée une commande en BROUILLON (sans facture) ─────────────────

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', CommandeVente::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        // Site récupéré depuis le profil de l'utilisateur (non modifiable par le client)
        $userSite = auth()->user()
            ->sites()
            ->wherePivot('is_default', true)
            ->first(['sites.id'])
            ?? auth()->user()->sites()->first(['sites.id']);

        abort_if(
            ! $userSite,
            403,
            "Votre compte n'est rattaché à aucun site."
        );

        $data = $request->validate(
            $this->commandeValidationRules(),
            $this->commandeValidationMessages(),
        );

        $this->ensureVehiculeOrClientSelected($data);

        [$lignesData, $totalCommande] = $this->buildLignesDataAndTotal($data['lignes']);

        // Création en BROUILLON — aucune facture, aucune commission
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

        return redirect()->route('ventes.show', $commande)
            ->with('success', 'Commande créée en brouillon.');
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function show(CommandeVente $vente): Response
    {
        $this->authorize('view', $vente);

        $commande_vente = $vente;
        $commande_vente->load(['vehicule', 'client', 'site', 'lignes.produit', 'createdBy', 'facture.encaissements.creator']);

        $user = auth()->user();

        $lignes = $commande_vente->lignes->map(fn ($l) => [
            'id' => $l->id,
            'produit_id' => $l->produit_id,
            'produit_nom' => $l->produit?->nom,
            'qte' => $l->qte,
            'prix_usine_snapshot' => (float) $l->prix_usine_snapshot,
            'prix_vente_snapshot' => (float) $l->prix_vente_snapshot,
            'total_ligne' => (float) $l->total_ligne,
        ]);

        $facture = $commande_vente->facture;

        return Inertia::render('Ventes/Show', [
            'commande' => [
                'id' => $commande_vente->id,
                'reference' => $commande_vente->reference,
                'statut' => $commande_vente->statut?->value,
                'statut_label' => $commande_vente->statut_label,
                'total_commande' => (float) $commande_vente->total_commande,
                'vehicule_nom' => $commande_vente->vehicule?->nom_vehicule,
                'client_nom' => $commande_vente->client ? trim($commande_vente->client->prenom.' '.$commande_vente->client->nom) : null,
                'site_nom' => $commande_vente->site?->nom,
                'motif_annulation' => $commande_vente->motif_annulation,
                'annulee_at' => $commande_vente->annulee_at?->toISOString(),
                'validated_at' => $commande_vente->validated_at?->format(self::DATE_DISPLAY_FORMAT),
                'is_brouillon' => $commande_vente->isBrouillon(),
                'is_en_cours' => $commande_vente->isEnCours(),
                'is_cloturee' => $commande_vente->isCloturee(),
                'is_annulee' => $commande_vente->isAnnulee(),
                'can_modifier' => $commande_vente->isBrouillon() && $user->can('update', $commande_vente),
                'can_valider' => $commande_vente->isBrouillon() && $user->can('update', $commande_vente),
                'can_annuler' => $commande_vente->isEnCours() && $user->can('annuler', $commande_vente),
                'can_encaisser' => $facture && ! $facture->isAnnulee() && (float) $facture->montant_restant > 0 && $user->can('update', $commande_vente),
                'created_at' => $commande_vente->created_at?->format(self::DATE_DISPLAY_FORMAT),
                'created_by' => $commande_vente->createdBy?->name,
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
        ]);
    }

    // ── Edit ─────────────────────────────────────────────────────────────────────

    public function edit(CommandeVente $vente): Response
    {
        $this->authorize('update', $vente);
        abort_if(! $vente->isBrouillon(), 403, 'Seule une commande en brouillon peut être modifiée.');

        $orgId = auth()->user()->organization_id;
        $vente->load(['lignes']);

        $produits = Produit::where('organization_id', $orgId)
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

        $vehicules = Vehicule::with(['equipe.livreurs' => fn ($q) => $q->wherePivot('role', 'principal')])
            ->where('organization_id', $orgId)
            ->where('is_active', true)
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
            ]);

        $clients = Client::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('nom')
            ->get()
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'nom' => $c->nom,
                'prenom' => $c->prenom,
                'telephone' => $c->telephone,
            ]);

        $userSite = auth()->user()
            ->sites()
            ->wherePivot('is_default', true)
            ->first(['sites.id', 'sites.nom', 'sites.type'])
            ?? auth()->user()->sites()->first(['sites.id', 'sites.nom', 'sites.type']);

        abort_if(! $userSite, 403, "Votre compte n'est rattaché à aucun site.");

        return Inertia::render('Ventes/Edit', [
            'commande' => [
                'id' => $vente->id,
                'reference' => $vente->reference,
                'vehicule_id' => $vente->vehicule_id,
                'client_id' => $vente->client_id,
                'lignes' => $vente->lignes->map(fn ($l) => [
                    'produit_id' => $l->produit_id,
                    'qte' => (int) $l->qte,
                    'prix_vente' => (float) $l->prix_vente_snapshot,
                ]),
            ],
            'produits' => $produits,
            'vehicules' => $vehicules,
            'clients' => $clients,
            'user_site' => [
                'id' => $userSite->id,
                'nom' => $userSite->nom,
                'label' => ($userSite->type?->label() ?? '').' de '.$userSite->nom,
            ],
        ]);
    }

    // ── Update : modification d'un BROUILLON ──────────────────────────────────

    public function update(Request $request, CommandeVente $vente): RedirectResponse
    {
        $this->authorize('update', $vente);
        abort_if(! $vente->isBrouillon(), 403, 'Seule une commande en brouillon peut être modifiée.');

        $data = $request->validate(
            $this->commandeValidationRules(),
            $this->commandeValidationMessages(),
        );

        $this->ensureVehiculeOrClientSelected($data);

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

        return redirect()->route('ventes.show', $vente)
            ->with('success', 'Commande mise à jour.');
    }

    // ── Valider : BROUILLON → EN_COURS ───────────────────────────────────────

    public function valider(CommandeVente $commande_vente): RedirectResponse
    {
        $this->authorize('update', $commande_vente);

        $this->service->valider($commande_vente);

        return back()->with('success', 'Commande validée. Facture créée.');
    }

    // ── Annuler : VALIDEE → ANNULEE (admin uniquement) ────────────────────────

    public function annuler(Request $request, CommandeVente $commande_vente): RedirectResponse
    {
        $this->authorize('annuler', $commande_vente);

        $data = $request->validate([
            'motif_annulation' => 'required|string|max:2000',
        ], [
            'motif_annulation.required' => "Le motif d'annulation est obligatoire.",
        ]);

        $this->service->annuler($commande_vente, $data['motif_annulation']);

        return back()->with('success', 'Commande et facture annulées.');
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    public function destroy(CommandeVente $vente): RedirectResponse
    {
        $this->authorize('delete', $vente);
        abort_unless($vente->isAnnulee(), 403, 'Seules les commandes annulées peuvent être supprimées.');

        $vente->delete();

        return redirect()->route('ventes.index')
            ->with('success', 'Commande supprimée.');
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

        $vehicule = Vehicule::query()
            ->select(['id', 'capacite_packs'])
            ->find($data['vehicule_id']);

        if (! $vehicule) {
            return;
        }

        if ($vehicule->capacite_packs === null) {
            throw ValidationException::withMessages([
                'vehicule_id' => 'Le véhicule sélectionné n\'a pas de capacité définie.',
            ]);
        }

        $qteTotale = collect($data['lignes'] ?? [])->sum(
            fn (array $ligne): int => (int) ($ligne['qte'] ?? 0),
        );
        $capacite = (int) $vehicule->capacite_packs;

        if ($qteTotale > $capacite) {
            throw ValidationException::withMessages([
                'lignes' => "La quantité totale ({$qteTotale} packs) dépasse la capacité du véhicule ({$capacite} packs maximum).",
            ]);
        }
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
                'qte' => $qte,
                'prix_usine_snapshot' => (float) $produit->prix_usine,
                'prix_vente_snapshot' => $prixVente,
                'total_ligne' => $totalLigne,
            ];

            $totalCommande += $totalLigne;
        }

        return [$lignesData, $totalCommande];
    }
}
