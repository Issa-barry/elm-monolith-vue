<?php

namespace App\Http\Controllers;

use App\Enums\ProduitStatut;
use App\Enums\ProduitType;
use App\Enums\StatutCommandeAchat;
use App\Models\CommandeAchat;
use App\Models\Prestataire;
use App\Models\Produit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class CommandeAchatController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', CommandeAchat::class);

        $orgId = auth()->user()->organization_id;

        $commandes = CommandeAchat::with(['prestataire', 'lignes'])
            ->where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (CommandeAchat $c) => [
                'id' => $c->id,
                'reference' => $c->reference,
                'statut' => $c->statut?->value,
                'statut_label' => $c->statut_label,
                'total_commande' => (float) $c->total_commande,
                'prestataire_nom' => $c->prestataire?->nom,
                'note' => $c->note,
                'created_at' => $c->created_at?->format('d/m/Y'),
                'is_annulee' => $c->isAnnulee(),
                'is_receptionnee' => $c->isReceptionnee(),
                'qte_commandee' => $c->lignes->sum('qte'),
                'qte_recue' => $c->lignes->sum('qte_recue'),
            ]);

        return Inertia::render('Achats/Index', [
            'commandes' => $commandes,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', CommandeAchat::class);

        $orgId = auth()->user()->organization_id;

        $produits = Produit::where('organization_id', $orgId)
            ->where('statut', ProduitStatut::ACTIF)
            ->whereIn('type', ProduitType::achetableValues())
            ->orderBy('nom')
            ->get()
            ->map(fn (Produit $p) => [
                'id' => $p->id,
                'nom' => $p->nom,
                'prix_achat' => (int) $p->prix_achat,
                'qte_stock' => $p->qte_stock,
            ]);

        $prestataires = Prestataire::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get()
            ->map(fn (Prestataire $p) => [
                'id' => $p->id,
                'nom' => $p->nom,
            ]);

        return Inertia::render('Achats/Create', [
            'produits' => $produits,
            'prestataires' => $prestataires,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', CommandeAchat::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'prestataire_id' => 'nullable|exists:prestataires,id',
            'note' => 'nullable|string|max:1000',
            'lignes' => 'required|array|min:1',
            'lignes.*.produit_id' => 'required|exists:produits,id',
            'lignes.*.qte' => 'required|integer|min:1',
            'lignes.*.prix_achat' => 'required|numeric|min:0',
        ], [
            'lignes.required' => 'Au moins une ligne est requise.',
            'lignes.min' => 'Au moins une ligne est requise.',
            'lignes.*.produit_id.required' => 'Le produit est obligatoire.',
            'lignes.*.produit_id.exists' => 'Produit introuvable.',
            'lignes.*.qte.required' => 'La quantité est obligatoire.',
            'lignes.*.qte.min' => 'La quantité doit être supérieure à 0.',
            'lignes.*.prix_achat.required' => "Le prix d'achat est obligatoire.",
            'lignes.*.prix_achat.min' => "Le prix d'achat ne peut pas être négatif.",
        ]);

        $lignesData = [];
        $totalCommande = 0;

        foreach ($data['lignes'] as $ligne) {
            $qte = (int) $ligne['qte'];
            $prixAchat = (float) $ligne['prix_achat'];
            $totalLigne = $qte * $prixAchat;

            $lignesData[] = [
                'produit_id' => $ligne['produit_id'],
                'qte' => $qte,
                'prix_achat_snapshot' => $prixAchat,
                'total_ligne' => $totalLigne,
            ];

            $totalCommande += $totalLigne;
        }

        $commande = CommandeAchat::create([
            'organization_id' => $orgId,
            'prestataire_id' => $data['prestataire_id'] ?? null,
            'note' => $data['note'] ?? null,
            'total_commande' => $totalCommande,
        ]);

        foreach ($lignesData as $ligneDatum) {
            $commande->lignes()->create($ligneDatum);
        }

        return redirect()->route('achats.show', $commande)
            ->with('success', 'Bon de commande créé avec succès.');
    }

    public function show(CommandeAchat $achat): Response
    {
        $this->authorize('view', $achat);

        $achat->load(['prestataire', 'lignes.produit', 'createdBy']);

        $lignes = $achat->lignes->map(fn ($l) => [
            'id' => $l->id,
            'produit_id' => $l->produit_id,
            'produit_nom' => $l->produit?->nom,
            'qte' => $l->qte,
            'qte_recue' => $l->qte_recue,
            'prix_achat_snapshot' => (float) $l->prix_achat_snapshot,
            'total_ligne' => (float) $l->total_ligne,
        ]);

        return Inertia::render('Achats/Show', [
            'commande' => [
                'id' => $achat->id,
                'reference' => $achat->reference,
                'statut' => $achat->statut?->value,
                'statut_label' => $achat->statut_label,
                'total_commande' => (float) $achat->total_commande,
                'prestataire_nom' => $achat->prestataire?->nom,
                'note' => $achat->note,
                'motif_annulation' => $achat->motif_annulation,
                'annulee_at' => $achat->annulee_at?->toISOString(),
                'is_annulee' => $achat->isAnnulee(),
                'is_receptionnee' => $achat->isReceptionnee(),
                'created_at' => $achat->created_at?->format('d/m/Y'),
                'created_by' => $achat->createdBy
                    ? trim($achat->createdBy->prenom.' '.$achat->createdBy->nom)
                    : null,
                'lignes' => $lignes,
            ],
        ]);
    }

    public function receptionner(Request $request, CommandeAchat $achat): RedirectResponse
    {
        $this->authorize('update', $achat);

        abort_if($achat->isAnnulee(), 422, 'Impossible de réceptionner une commande annulée.');
        abort_if($achat->isReceptionnee(), 422, 'Cette commande a déjà été réceptionnée.');

        $achat->load('lignes.produit');

        $data = $request->validate([
            'lignes' => 'required|array',
            'lignes.*.id' => 'required|string',
            'lignes.*.qte_recue' => 'required|integer|min:0',
        ]);

        $qtesRecues = collect($data['lignes'])->keyBy('id');

        foreach ($achat->lignes as $ligne) {
            $qteRecue = (int) ($qtesRecues[$ligne->id]['qte_recue'] ?? $ligne->qte);
            $ligne->update(['qte_recue' => $qteRecue]);
            if ($ligne->produit && $qteRecue > 0) {
                $ligne->produit->increment('qte_stock', $qteRecue);
            }
        }

        $achat->update([
            'statut' => StatutCommandeAchat::RECEPTIONNEE,
        ]);

        return back()->with('success', 'Commande réceptionnée. Le stock a été mis à jour.');
    }

    public function annuler(Request $request, CommandeAchat $achat): RedirectResponse
    {
        $this->authorize('update', $achat);

        $data = $request->validate([
            'motif_annulation' => 'required|string|max:2000',
        ], [
            'motif_annulation.required' => "Le motif d'annulation est obligatoire.",
        ]);

        abort_if($achat->isAnnulee(), 422, 'Cette commande est déjà annulée.');
        abort_if($achat->isReceptionnee(), 422, "Impossible d'annuler une commande déjà réceptionnée.");

        $achat->update([
            'statut' => StatutCommandeAchat::ANNULEE,
            'motif_annulation' => $data['motif_annulation'],
            'annulee_at' => now(),
            'annulee_par' => auth()->id(),
        ]);

        return back()->with('success', 'Commande annulée.');
    }

    public function pdf(CommandeAchat $achat): HttpResponse
    {
        $this->authorize('view', $achat);

        $achat->load(['prestataire', 'lignes.produit', 'createdBy', 'organization']);

        $createdBy = $achat->createdBy
            ? trim($achat->createdBy->prenom.' '.$achat->createdBy->nom)
            : '—';

        $pdf = Pdf::loadView('pdf.bon_commande_achat', [
            'commande' => $achat,
            'organisation' => $achat->organization,
            'createdBy' => $createdBy,
        ])->setPaper('a4', 'portrait');

        $filename = $achat->reference.'.pdf';

        return $pdf->download($filename);
    }

    public function destroy(CommandeAchat $achat): RedirectResponse
    {
        $this->authorize('delete', $achat);
        abort_unless($achat->isAnnulee(), 403, 'Seules les commandes annulées peuvent être supprimées.');

        $achat->delete();

        return redirect()->route('achats.index')
            ->with('success', 'Commande supprimée.');
    }
}
