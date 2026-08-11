<?php

namespace App\Http\Controllers;

use App\Enums\ProduitStatut;
use App\Enums\ProduitType;
use App\Enums\StatutCommandeAchat;
use App\Models\CommandeAchat;
use App\Models\CommandeAchatLigne;
use App\Models\Fournisseur;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\ProduitVariante;
use App\Models\Site;
use App\Models\VarianteStock;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CommandeAchatController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', CommandeAchat::class);

        $orgId = auth()->user()->organization_id;

        $commandes = CommandeAchat::with(['fournisseur', 'lignes'])
            ->where('organization_id', $orgId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (CommandeAchat $c) => [
                'id' => $c->id,
                'reference' => $c->reference,
                'statut' => $c->statut?->value,
                'statut_label' => $c->statut_label,
                'total_commande' => (float) $c->total_commande,
                'fournisseur_nom' => $c->fournisseur?->nom_complet,
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
            ->with('variantes')
            ->orderBy('nom')
            ->get()
            ->map(function (Produit $p) {
                $variante = $p->variantes->firstWhere('is_default', true) ?? $p->variantes->first();

                return [
                    'id' => $p->id,
                    'nom' => $p->nom,
                    'prix_achat' => (int) ($variante?->prix_achat ?? 0),
                    'qte_stock' => $p->qte_stock,
                ];
            });

        $fournisseurs = Fournisseur::where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('nom')
            ->get()
            ->map(fn (Fournisseur $f) => [
                'id' => $f->id,
                'nom' => $f->nom_complet,
            ]);

        return Inertia::render('Achats/Create', [
            'produits' => $produits,
            'fournisseurs' => $fournisseurs,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', CommandeAchat::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'fournisseur_id' => 'nullable|exists:fournisseurs,id',
            'note' => 'nullable|string|max:1000',
            'lignes' => 'required|array|min:1',
            'lignes.*.produit_id' => 'required|exists:produits,id',
            'lignes.*.variante_id' => 'nullable|exists:produit_variantes,id',
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
            $variante = $this->resolveVariante($ligne);
            $qte = (int) $ligne['qte'];
            $prixAchat = (float) $ligne['prix_achat'];
            $totalLigne = $qte * $prixAchat;

            $lignesData[] = [
                'variante_id' => $variante->id,
                'qte' => $qte,
                'prix_achat_snapshot' => $prixAchat,
                'total_ligne' => $totalLigne,
                'libelle_snapshot' => $variante->libelle !== ''
                    ? "{$variante->produit->nom} — {$variante->libelle}"
                    : $variante->produit->nom,
            ];

            $totalCommande += $totalLigne;
        }

        $commande = CommandeAchat::create([
            'organization_id' => $orgId,
            'fournisseur_id' => $data['fournisseur_id'] ?? null,
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

        $achat->load(['fournisseur', 'lignes.variante.produit', 'createdBy']);

        $lignes = $achat->lignes->map(fn ($l) => [
            'id' => $l->id,
            'variante_id' => $l->variante_id,
            'produit_nom' => $l->libelle_snapshot ?? $l->variante?->produit?->nom,
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
                'fournisseur_nom' => $achat->fournisseur?->nom_complet,
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

        $achat->load('lignes.variante.produit');

        $data = $request->validate([
            // Optionnel : par défaut, le site par défaut de l'utilisateur qui réceptionne
            // (aucun sélecteur de site dans le formulaire actuel — Phase 3).
            'site_id' => 'nullable|exists:sites,id',
            'lignes' => 'required|array',
            'lignes.*.id' => 'required|string',
            'lignes.*.qte_recue' => 'required|integer|min:0',
        ]);

        $site = $this->resolveReceptionSite($data['site_id'] ?? null, $achat->organization_id);
        $qtesRecues = collect($data['lignes'])->keyBy('id');
        $userId = auth()->id();

        DB::transaction(function () use ($achat, $qtesRecues, $site, $userId) {
            foreach ($achat->lignes as $ligne) {
                $qteRecue = (int) ($qtesRecues[$ligne->id]['qte_recue'] ?? $ligne->qte);
                $ligne->update(['qte_recue' => $qteRecue]);

                if ($ligne->variante && $qteRecue > 0) {
                    $this->entrerStockReception($ligne, $site, $achat->organization_id, $qteRecue, $userId);
                }
            }

            $achat->update([
                'statut' => StatutCommandeAchat::RECEPTIONNEE,
            ]);
        });

        return back()->with('success', 'Commande réceptionnée. Le stock a été mis à jour.');
    }

    /**
     * Ventile l'entrée de stock sur le site de réception via VarianteStock + trace un
     * MouvementStock — avant refonte, receptionner() incrémentait Produit::qte_stock
     * directement, sans passer par le stock par site ni laisser de trace de mouvement
     * (seul chemin d'ajustement du système à contourner ces deux garanties).
     */
    private function entrerStockReception(CommandeAchatLigne $ligne, Site $site, string $orgId, int $qte, ?string $userId): void
    {
        $variante = $ligne->variante;

        $varianteStock = VarianteStock::firstOrCreate(
            ['produit_variante_id' => $variante->id, 'site_id' => $site->id],
            ['organization_id' => $orgId]
        );

        $stockAvant = $varianteStock->qte_stock;
        $stockApres = $stockAvant + $qte;
        $varianteStock->update(['qte_stock' => $stockApres]);

        $variante->produit?->resynchroniserQteStock();

        MouvementStock::create([
            'organization_id' => $orgId,
            'site_id' => $site->id,
            'produit_variante_id' => $variante->id,
            'type' => 'entree',
            'quantite' => $qte,
            'stock_avant' => $stockAvant,
            'stock_apres' => $stockApres,
            'source_type' => CommandeAchatLigne::class,
            'source_id' => $ligne->id,
            'created_by' => $userId,
        ]);
    }

    private function resolveReceptionSite(?string $siteId, string $orgId): Site
    {
        if ($siteId) {
            return Site::where('id', $siteId)->where('organization_id', $orgId)->firstOrFail();
        }

        $user = auth()->user();
        $site = $user->sites()->wherePivot('is_default', true)->first() ?? $user->sites()->first();
        abort_if(! $site, 422, "Aucun site n'est associé à votre compte pour réceptionner ce stock.");

        return $site;
    }

    private function resolveVariante(array $ligne): ProduitVariante
    {
        if (! empty($ligne['variante_id'])) {
            return ProduitVariante::findOrFail($ligne['variante_id']);
        }

        $produit = Produit::with('variantes')->findOrFail($ligne['produit_id']);

        if ($produit->variantes->count() === 1) {
            return $produit->variantes->first();
        }

        if ($produit->variantes->count() > 1) {
            throw ValidationException::withMessages([
                'lignes' => "Le produit « {$produit->nom} » a plusieurs déclinaisons — précisez la variante à acheter.",
            ]);
        }

        throw ValidationException::withMessages([
            'lignes' => "Le produit « {$produit->nom} » n'a aucune variante disponible.",
        ]);
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

        $achat->load(['fournisseur', 'lignes.variante.produit', 'createdBy', 'organization']);

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
