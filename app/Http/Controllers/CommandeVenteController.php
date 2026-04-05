<?php

namespace App\Http\Controllers;

use App\Enums\ModePaiement;
use App\Enums\ProduitStatut;
use App\Enums\ProduitType;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Produit;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommissionGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommandeVenteController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', CommandeVente::class);

        $orgId = auth()->user()->organization_id;

        $commandes = CommandeVente::with(['vehicule', 'client', 'facture'])
            ->where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (CommandeVente $c) => [
                'id' => $c->id,
                'reference' => $c->reference,
                'statut' => $c->statut?->value,
                'statut_label' => $c->statut_label,
                'total_commande' => (float) $c->total_commande,
                'vehicule_nom' => $c->vehicule?->nom_vehicule,
                'client_nom' => $c->client ? trim($c->client->prenom.' '.$c->client->nom) : null,
                'facture_statut' => $c->facture?->statut_facture?->value,
                'facture_statut_label' => $c->facture?->statut_facture?->label(),
                'facture_montant_restant' => $c->facture ? (float) $c->facture->montant_restant : null,
                'created_at' => $c->created_at?->format('d/m/Y'),
                'is_annulee' => $c->isAnnulee(),
            ]);

        return Inertia::render('Ventes/Index', [
            'commandes' => $commandes,
        ]);
    }

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

        $sites = Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get()
            ->map(fn (Site $s) => [
                'id' => $s->id,
                'nom' => $s->nom,
            ]);

        return Inertia::render('Ventes/Create', [
            'produits' => $produits,
            'vehicules' => $vehicules,
            'clients' => $clients,
            'sites' => $sites,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', CommandeVente::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, 'Votre compte n\'est associé à aucune organisation.');

        $data = $request->validate([
            'site_id' => 'required|exists:sites,id',
            'vehicule_id' => 'nullable|exists:vehicules,id',
            'client_id' => 'nullable|exists:clients,id',
            'lignes' => 'required|array|min:1',
            'lignes.*.produit_id' => 'required|exists:produits,id',
            'lignes.*.qte' => 'required|integer|min:1',
            'lignes.*.prix_vente' => 'required|numeric|min:0',
        ], [
            'site_id.required' => 'Le site est obligatoire.',
            'site_id.exists' => 'Le site sélectionné est introuvable.',
            'lignes.required' => 'Au moins une ligne de commande est requise.',
            'lignes.min' => 'Au moins une ligne de commande est requise.',
            'lignes.*.produit_id.required' => 'Le produit est obligatoire pour chaque ligne.',
            'lignes.*.produit_id.exists' => 'Le produit sélectionné est introuvable.',
            'lignes.*.qte.required' => 'La quantité est obligatoire pour chaque ligne.',
            'lignes.*.qte.min' => 'La quantité doit être supérieure à 0.',
            'lignes.*.prix_vente.required' => 'Le prix de vente est obligatoire pour chaque ligne.',
            'lignes.*.prix_vente.min' => 'Le prix de vente ne peut pas être négatif.',
        ]);

        // Véhicule ou client obligatoire
        if (empty($data['vehicule_id']) && empty($data['client_id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'vehicule_id' => 'Veuillez sélectionner un véhicule ou un client.',
                'client_id' => 'Veuillez sélectionner un véhicule ou un client.',
            ]);
        }

        // Préparer les lignes avec snapshots
        $lignesData = [];
        $totalCommande = 0;

        foreach ($data['lignes'] as $ligne) {
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

        // Créer la commande
        $commande = CommandeVente::create([
            'organization_id' => $orgId,
            'site_id' => $data['site_id'] ?? null,
            'vehicule_id' => $data['vehicule_id'] ?? null,
            'client_id' => $data['client_id'] ?? null,
            'total_commande' => $totalCommande,
            'created_by' => auth()->id(),
        ]);

        // Créer les lignes
        foreach ($lignesData as $ligneDatum) {
            $commande->lignes()->create($ligneDatum);
        }

        // Créer automatiquement la facture
        FactureVente::create([
            'organization_id' => $orgId,
            'site_id' => $commande->site_id,
            'vehicule_id' => $commande->vehicule_id,
            'commande_vente_id' => $commande->id,
            'montant_brut' => $totalCommande,
            'montant_net' => $totalCommande,
        ]);

        $commande->loadMissing('vehicule');
        if ($commande->vehicule) {
            CommissionGenerator::generateForCommandeIfMissing($commande, null, 'commande_creee');
        }

        return redirect()->route('ventes.show', $commande)
            ->with('success', 'Commande créée avec succès.');
    }

    public function show(CommandeVente $vente): Response
    {
        $this->authorize('view', $vente);

        $commande_vente = $vente;
        $commande_vente->load(['vehicule', 'client', 'site', 'lignes.produit', 'facture.encaissements.creator', 'createdBy']);

        $lignes = $commande_vente->lignes->map(fn ($l) => [
            'id' => $l->id,
            'produit_id' => $l->produit_id,
            'produit_nom' => $l->produit?->nom,
            'qte' => $l->qte,
            'prix_usine_snapshot' => (float) $l->prix_usine_snapshot,
            'prix_vente_snapshot' => (float) $l->prix_vente_snapshot,
            'total_ligne' => (float) $l->total_ligne,
        ]);

        $facture = null;
        if ($commande_vente->facture) {
            $f = $commande_vente->facture;
            $encaissements = $f->encaissements->map(fn ($e) => [
                'id' => $e->id,
                'montant' => (float) $e->montant,
                'date_encaissement' => $e->date_encaissement?->toDateString(),
                'mode_paiement' => $e->mode_paiement?->value,
                'mode_paiement_label' => $e->mode_paiement?->label(),
                'note' => $e->note,
                'created_by' => $e->creator?->name,
                'created_at' => $e->created_at?->toISOString(),
            ]);

            $facture = [
                'id' => $f->id,
                'reference' => $f->reference,
                'montant_brut' => (float) $f->montant_brut,
                'montant_net' => (float) $f->montant_net,
                'montant_encaisse' => (float) $f->montant_encaisse,
                'montant_restant' => (float) $f->montant_restant,
                'statut_facture' => $f->statut_facture?->value,
                'statut_label' => $f->statut_label,
                'is_annulee' => $f->isAnnulee(),
                'is_payee' => $f->isPayee(),
                'encaissements' => $encaissements,
            ];
        }

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
                'is_annulee' => $commande_vente->isAnnulee(),
                'created_at' => $commande_vente->created_at?->format('d/m/Y'),
                'created_by' => $commande_vente->createdBy?->name,
                'lignes' => $lignes,
            ],
            'facture' => $facture,
            'modes_paiement' => ModePaiement::options(),
        ]);
    }

    public function annuler(Request $request, CommandeVente $commande_vente): RedirectResponse
    {
        $this->authorize('update', $commande_vente);

        $data = $request->validate([
            'motif_annulation' => 'required|string|max:2000',
        ], [
            'motif_annulation.required' => 'Le motif d\'annulation est obligatoire.',
        ]);

        abort_if($commande_vente->isAnnulee(), 422, 'Cette commande est déjà annulée.');

        // Bloquer si un encaissement a déjà été enregistré
        $montantEncaisse = $commande_vente->facture?->encaissements()->sum('montant') ?? 0;
        abort_if($montantEncaisse > 0, 422, 'Impossible d\'annuler une commande ayant déjà fait l\'objet d\'un encaissement. Supprimez d\'abord les encaissements.');

        $commande_vente->update([
            'statut' => StatutCommandeVente::ANNULEE,
            'motif_annulation' => $data['motif_annulation'],
            'annulee_at' => now(),
            'annulee_par' => auth()->id(),
        ]);

        if ($commande_vente->facture) {
            $commande_vente->facture->update([
                'statut_facture' => StatutFactureVente::ANNULEE,
            ]);
        }

        return back()->with('success', 'Commande annulée avec succès.');
    }

    public function destroy(CommandeVente $vente): RedirectResponse
    {
        $this->authorize('delete', $vente);
        abort_unless($vente->isAnnulee(), 403, 'Seules les commandes annulées peuvent être supprimées.');

        $vente->delete();

        return redirect()->route('ventes.index')
            ->with('success', 'Commande supprimée.');
    }
}

