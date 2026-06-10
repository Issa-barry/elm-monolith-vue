<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\ProduitStatut;
use App\Enums\ProduitType;
use App\Models\AuditLog;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Services\AuditLogService;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProduitController extends Controller
{
    public function __construct(private readonly AuditLogService $auditService) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Produit::class);

        $produits = Produit::where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom')
            ->get()
            ->map(fn (Produit $p) => [
                'id' => $p->id,
                'organization_id' => $p->organization_id,
                'nom' => $p->nom,
                'code_interne' => $p->code_interne,
                'code_fournisseur' => $p->code_fournisseur,
                'type' => $p->type?->value,
                'type_label' => $p->type?->label(),
                'statut' => $p->statut?->value,
                'statut_label' => $p->statut?->label(),
                'image_url' => $p->image_url,
                'prix_usine' => $p->prix_usine,
                'prix_vente' => $p->prix_vente,
                'prix_achat' => $p->prix_achat,
                'cout' => $p->cout,
                'qte_stock' => $p->qte_stock,
                'seuil_alerte_stock' => $p->seuil_alerte_stock,
                'description' => $p->description,
                'is_alerte' => $p->is_alerte,
                'last_stockout_notified_at' => $p->last_stockout_notified_at ? (string) $p->last_stockout_notified_at : null,
                'archived_at' => $p->archived_at?->toISOString(),
                'created_by' => $p->created_by,
                'updated_by' => $p->updated_by,
                'deleted_by' => $p->deleted_by,
                'archived_by' => $p->archived_by,
                'created_at' => $p->created_at?->toISOString(),
                'updated_at' => $p->updated_at?->toISOString(),
                'deleted_at' => $p->deleted_at?->toISOString(),
                'in_stock' => $p->in_stock,
                'is_low_stock' => $p->is_low_stock,
                'has_stock' => $p->type?->hasStock() ?? true,
            ]);

        return Inertia::render('Produits/Index', [
            'produits' => $produits,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Produit::class);

        return Inertia::render('Produits/Create', [
            'types' => ProduitType::options(),
            'statuts' => ProduitStatut::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Produit::class);

        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'code_fournisseur' => 'nullable|string|max:100',
            'type' => 'required|in:'.implode(',', ProduitType::values()),
            'statut' => 'required|in:'.implode(',', ProduitStatut::values()),
            'prix_usine' => 'nullable|integer|min:0',
            'prix_vente' => 'nullable|integer|min:0',
            'prix_achat' => 'nullable|integer|min:0',
            'cout' => 'nullable|integer|min:0',
            'qte_stock' => 'nullable|integer|min:0',
            'seuil_alerte_stock' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'is_alerte' => 'boolean',
            'image' => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $path = (new ImageService)->storeAsWebp($request->file('image'), 'produits');
            $data['image_url'] = '/storage/'.$path;
        }
        unset($data['image']);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, 'Votre compte n\'est associé à aucune organisation.');

        $produit = Produit::create([
            ...$data,
            'organization_id' => $orgId,
        ]);

        $this->auditService->record(
            $produit,
            AuditEvent::CREATED,
            auth()->user(),
            null,
            $this->produitSnapshot($produit),
        );

        return redirect()->route('produits.index')
            ->with('success', 'Produit créé avec succès.');
    }

    public function show(Produit $produit): Response
    {
        $this->authorize('view', $produit);

        $mouvements = MouvementStock::where('produit_id', $produit->id)
            ->with('createur:id,prenom,nom')
            ->orderByDesc('created_at')
            ->take(100)
            ->get()
            ->map(fn (MouvementStock $m) => [
                'id' => $m->id,
                'type' => $m->type,
                'quantite' => $m->quantite,
                'stock_avant' => $m->stock_avant,
                'stock_apres' => $m->stock_apres,
                'notes' => $m->notes,
                'created_at' => $m->created_at?->toISOString(),
                'createur_nom' => $m->createur
                    ? trim(($m->createur->prenom ?? '').' '.($m->createur->nom ?? ''))
                    : null,
                'is_initial' => false,
            ])
            ->toArray();

        // Stock initial dérivé de l'audit de création
        $creation = AuditLog::where('auditable_type', Produit::class)
            ->where('auditable_id', $produit->id)
            ->where('event_code', 'CREATED')
            ->first();

        if ($creation && isset($creation->new_values['qte_stock']) && (float) $creation->new_values['qte_stock'] > 0) {
            $mouvements[] = [
                'id' => 'initial-'.$produit->id,
                'type' => 'entree',
                'quantite' => (int) $creation->new_values['qte_stock'],
                'stock_avant' => 0,
                'stock_apres' => (int) $creation->new_values['qte_stock'],
                'notes' => 'Stock initial — création du produit',
                'created_at' => $creation->created_at?->toISOString(),
                'createur_nom' => $creation->actor_name_snapshot,
                'is_initial' => true,
            ];
        }

        $mouvements = collect($mouvements);

        $historiques = $this->loadHistoriques($produit);

        return Inertia::render('Produits/Show', [
            'produit' => [
                'id' => $produit->id,
                'nom' => $produit->nom,
                'code_interne' => $produit->code_interne,
                'code_fournisseur' => $produit->code_fournisseur,
                'image_url' => $produit->image_url,
                'type' => $produit->type?->value,
                'type_label' => $produit->type?->label(),
                'statut' => $produit->statut?->value,
                'statut_label' => $produit->statut?->label(),
                'prix_usine' => $produit->prix_usine,
                'prix_vente' => $produit->prix_vente,
                'prix_achat' => $produit->prix_achat,
                'cout' => $produit->cout,
                'qte_stock' => $produit->qte_stock,
                'seuil_alerte_stock' => $produit->seuil_alerte_stock,
                'description' => $produit->description,
                'is_alerte' => $produit->is_alerte,
                'in_stock' => $produit->in_stock,
                'is_low_stock' => $produit->is_low_stock,
                'has_stock' => $produit->type?->hasStock() ?? true,
                'created_at' => $produit->created_at?->toISOString(),
                'updated_at' => $produit->updated_at?->toISOString(),
            ],
            'mouvements' => $mouvements,
            'historiques' => $historiques,
        ]);
    }

    public function historique(Produit $produit): JsonResponse
    {
        $this->authorize('view', $produit);

        return response()->json($this->loadHistoriques($produit));
    }

    public function edit(Produit $produit): Response
    {
        $this->authorize('update', $produit);

        return Inertia::render('Produits/Edit', [
            'produit' => [
                'id' => $produit->id,
                'nom' => $produit->nom,
                'code_interne' => $produit->code_interne,
                'code_fournisseur' => $produit->code_fournisseur,
                'image_url' => $produit->image_url,
                'type' => $produit->type?->value,
                'statut' => $produit->statut?->value,
                'prix_usine' => $produit->prix_usine,
                'prix_vente' => $produit->prix_vente,
                'prix_achat' => $produit->prix_achat,
                'cout' => $produit->cout,
                'qte_stock' => $produit->qte_stock,
                'seuil_alerte_stock' => $produit->seuil_alerte_stock,
                'description' => $produit->description,
                'is_alerte' => $produit->is_alerte,
            ],
            'types' => ProduitType::options(),
            'statuts' => ProduitStatut::options(),
        ]);
    }

    public function update(Request $request, Produit $produit): RedirectResponse
    {
        $this->authorize('update', $produit);

        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'code_fournisseur' => 'nullable|string|max:100',
            'type' => 'required|in:'.implode(',', ProduitType::values()),
            'statut' => 'required|in:'.implode(',', ProduitStatut::values()),
            'prix_usine' => 'nullable|integer|min:0',
            'prix_vente' => 'nullable|integer|min:0',
            'prix_achat' => 'nullable|integer|min:0',
            'cout' => 'nullable|integer|min:0',
            'qte_stock' => 'nullable|integer|min:0',
            'seuil_alerte_stock' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'is_alerte' => 'boolean',
            'image' => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $imageService = new ImageService;
            $imageService->delete(str_replace('/storage/', '', $produit->image_url ?? ''));
            $path = $imageService->storeAsWebp($request->file('image'), 'produits');
            $data['image_url'] = '/storage/'.$path;
        }
        unset($data['image']);

        $oldSnapshot = $this->produitSnapshot($produit);
        $produit->update($data);
        $produit->refresh();
        $newSnapshot = $this->produitSnapshot($produit);

        [$oldDiff, $newDiff] = $this->produitDiff($oldSnapshot, $newSnapshot);
        if ($oldDiff !== null || $newDiff !== null) {
            $this->auditService->record(
                $produit,
                AuditEvent::UPDATED,
                auth()->user(),
                $oldDiff,
                $newDiff,
            );
        }

        return redirect()->route('produits.index')
            ->with('success', 'Produit mis à jour avec succès.');
    }

    public function destroy(Produit $produit): RedirectResponse
    {
        $this->authorize('delete', $produit);

        $this->auditService->record(
            $produit,
            AuditEvent::DELETED,
            auth()->user(),
            $this->produitSnapshot($produit),
            null,
        );

        if ($produit->image_url) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $produit->image_url));
        }

        $produit->delete();

        return redirect()->route('produits.index')
            ->with('success', 'Produit supprimé.');
    }

    public function ajusterStock(Request $request, Produit $produit): RedirectResponse
    {
        $this->authorize('update', $produit);

        abort_unless((bool) $produit->type?->hasStock(), 422, 'Ce produit ne gère pas de stock.');

        $data = $request->validate([
            'augmenter' => 'nullable|integer|min:1',
            'diminuer' => 'nullable|integer|min:1',
            'motif' => 'nullable|string|max:500',
        ], [
            'augmenter.integer' => 'La quantité doit être un nombre entier.',
            'augmenter.min' => 'La quantité doit être supérieure à 0.',
            'diminuer.integer' => 'La quantité doit être un nombre entier.',
            'diminuer.min' => 'La quantité doit être supérieure à 0.',
        ]);

        $hasAugmenter = ! empty($data['augmenter']);
        $hasDiminuer = ! empty($data['diminuer']);

        if ($hasAugmenter && $hasDiminuer) {
            throw ValidationException::withMessages([
                'augmenter' => 'Renseignez uniquement l\'un des deux champs.',
            ]);
        }

        if (! $hasAugmenter && ! $hasDiminuer) {
            throw ValidationException::withMessages([
                'augmenter' => 'Veuillez renseigner la quantité à augmenter ou à diminuer.',
            ]);
        }

        $stockAvant = (int) ($produit->qte_stock ?? 0);

        if ($hasDiminuer && (int) $data['diminuer'] > $stockAvant) {
            throw ValidationException::withMessages([
                'diminuer' => "La quantité à retirer ({$data['diminuer']}) est supérieure au stock disponible ({$stockAvant}).",
            ]);
        }

        $user = auth()->user();
        $site = $user->sites()->wherePivot('is_default', true)->first() ?? $user->sites()->first();
        abort_if(! $site, 422, 'Aucun site associé à votre compte.');

        if ($hasAugmenter) {
            $stockApres = $stockAvant + (int) $data['augmenter'];
            $type = 'entree';
            $quantite = (int) $data['augmenter'];
        } else {
            $stockApres = $stockAvant - (int) $data['diminuer'];
            $type = 'sortie';
            $quantite = (int) $data['diminuer'];
        }

        DB::transaction(function () use ($produit, $type, $quantite, $stockAvant, $stockApres, $data, $site, $user) {
            $produit->update(['qte_stock' => $stockApres]);

            MouvementStock::create([
                'organization_id' => $produit->organization_id,
                'site_id' => $site->id,
                'produit_id' => $produit->id,
                'type' => $type,
                'quantite' => $quantite,
                'stock_avant' => $stockAvant,
                'stock_apres' => $stockApres,
                'notes' => $data['motif'] ?? null,
                'created_by' => $user->id,
            ]);

            $this->auditService->record(
                $produit,
                AuditEvent::STOCK_ADJUSTED,
                $user,
                ['qte_stock' => $stockAvant],
                array_filter([
                    'qte_stock' => $stockApres,
                    'motif' => $data['motif'] ?? null,
                ], fn ($v) => $v !== null),
            );
        });

        return back()->with('success', 'Stock mis à jour avec succès.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function loadHistoriques(Produit $produit): array
    {
        return AuditLog::where('organization_id', $produit->organization_id)
            ->where('auditable_type', Produit::class)
            ->where('auditable_id', $produit->id)
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
            ])
            ->all();
    }

    private function produitSnapshot(Produit $produit): array
    {
        return array_filter([
            'nom' => $produit->nom,
            'type' => $produit->type?->label(),
            'statut' => $produit->statut?->label(),
            'prix_vente' => $produit->prix_vente,
            'prix_achat' => $produit->prix_achat,
            'prix_usine' => $produit->prix_usine,
            'cout' => $produit->cout,
            'qte_stock' => $produit->qte_stock,
            'seuil_alerte_stock' => $produit->seuil_alerte_stock,
            'is_alerte' => $produit->is_alerte,
            'description' => $produit->description,
            'code_fournisseur' => $produit->code_fournisseur,
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function produitDiff(array $before, array $after): array
    {
        $allKeys = array_unique(array_merge(array_keys($before), array_keys($after)));
        $oldDiff = [];
        $newDiff = [];

        $normalize = fn ($v) => is_numeric($v) ? rtrim(number_format((float) $v, 2, '.', ''), '0') : (string) ($v ?? '');

        foreach ($allKeys as $key) {
            $oldVal = $before[$key] ?? null;
            $newVal = $after[$key] ?? null;
            if ($normalize($oldVal) !== $normalize($newVal)) {
                $oldDiff[$key] = $oldVal;
                $newDiff[$key] = $newVal;
            }
        }

        return [empty($oldDiff) ? null : $oldDiff, empty($newDiff) ? null : $newDiff];
    }
}
