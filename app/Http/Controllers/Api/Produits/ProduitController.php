<?php

namespace App\Http\Controllers\Api\Produits;

use App\Enums\AuditEvent;
use App\Enums\MotifAjustementStock;
use App\Enums\ProduitStatut;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Produits\AjusterStockRequest;
use App\Http\Requests\Api\Produits\StoreProduitRequest;
use App\Http\Requests\Api\Produits\UpdateProduitRequest;
use App\Http\Resources\Api\ProduitResource;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\VarianteStock;
use App\Services\AuditLogService;
use App\Services\MediaService;
use App\Services\ProduitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProduitController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditService,
        private readonly ProduitService $produitService,
        private readonly MediaService $mediaService,
    ) {}

    public function index(Request $r): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Produit::class);

        $produits = Produit::where('organization_id', $r->user()->organization_id)
            ->with(['categorie:id,nom', 'variantes', 'medias' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('position')])
            ->orderBy('nom')
            ->get();

        $varianteIds = $produits->flatMap(fn (Produit $p) => $p->variantes->pluck('id'))->all();
        $usedVarianteIds = collect()
            ->merge(DB::table('commande_vente_lignes')->whereIn('variante_id', $varianteIds)->pluck('variante_id'))
            ->merge(DB::table('commande_achat_lignes')->whereIn('variante_id', $varianteIds)->whereNotNull('variante_id')->pluck('variante_id'))
            ->unique()
            ->flip()
            ->all();

        $produits->each(function (Produit $p) use ($usedVarianteIds) {
            $p->is_used_loaded = collect($p->variantes->pluck('id'))->contains(fn ($vid) => isset($usedVarianteIds[$vid]));
        });

        return ProduitResource::collection($produits);
    }

    public function show(Request $r, Produit $produit): JsonResponse
    {
        $this->authorize('view', $produit);

        $produit->load(['categorie', 'variantes', 'medias']);

        return response()->json(new ProduitResource($produit));
    }

    public function store(StoreProduitRequest $r): JsonResponse
    {
        $data = $r->validated();
        $images = $r->file('images', []);

        $orgId = $r->user()->organization_id;
        abort_if(! $orgId, 403, 'Votre compte n\'est associé à aucune organisation.');

        // Transaction englobante : si l'upload d'images échoue (limite dépassée), le produit
        // qui vient d'être créé ne doit pas rester orphelin en base.
        $produit = DB::transaction(function () use ($data, $orgId, $images) {
            $produit = $this->produitService->creer([...$data, 'organization_id' => $orgId]);

            if (! empty($images)) {
                $this->mediaService->ajouter($produit, $images);
            }

            return $produit;
        });

        $this->auditService->record(
            $produit,
            AuditEvent::CREATED,
            $r->user(),
            null,
            $this->produitSnapshot($produit->fresh(['variantes'])),
        );

        return response()->json(new ProduitResource($produit->fresh(['variantes', 'categorie', 'medias'])), 201);
    }

    public function update(UpdateProduitRequest $r, Produit $produit): JsonResponse
    {
        $data = $r->validated();
        $images = $r->file('images', []);

        $oldSnapshot = $this->produitSnapshot($produit->fresh(['variantes']));

        $produit = DB::transaction(function () use ($produit, $data, $images) {
            $produit = $this->produitService->mettreAJourSimple($produit, $data);

            if (! empty($images)) {
                $this->mediaService->ajouter($produit, $images);
            }

            return $produit;
        });

        $newSnapshot = $this->produitSnapshot($produit->fresh(['variantes']));

        [$oldDiff, $newDiff] = $this->produitDiff($oldSnapshot, $newSnapshot);
        if ($oldDiff !== null || $newDiff !== null) {
            $this->auditService->record($produit, AuditEvent::UPDATED, $r->user(), $oldDiff, $newDiff);
        }

        return response()->json(new ProduitResource($produit->fresh(['variantes', 'categorie', 'medias'])));
    }

    public function archiver(Request $r, Produit $produit): JsonResponse
    {
        $this->authorize('update', $produit);

        $this->auditService->record(
            $produit,
            AuditEvent::UPDATED,
            $r->user(),
            ['statut' => $produit->statut?->value],
            ['statut' => ProduitStatut::ARCHIVE->value],
        );

        $produit->update(['statut' => ProduitStatut::ARCHIVE]);

        return response()->json(new ProduitResource($produit->fresh(['variantes', 'categorie', 'medias'])));
    }

    public function destroy(Request $r, Produit $produit): JsonResponse
    {
        $this->authorize('delete', $produit);

        $this->auditService->record(
            $produit,
            AuditEvent::DELETED,
            $r->user(),
            $this->produitSnapshot($produit->fresh(['variantes'])),
            null,
        );

        foreach ($produit->medias as $media) {
            $this->mediaService->supprimer($media);
        }

        $produit->delete();

        return response()->json(null, 204);
    }

    public function ajusterStock(AjusterStockRequest $r, Produit $produit): JsonResponse
    {
        $this->authorize('update', $produit);

        abort_unless((bool) $produit->type?->hasStock(), 422, 'Ce produit ne gère pas de stock.');

        $data = $r->validated();

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

        $variante = $produit->variantePrincipale()->first();
        abort_if(! $variante, 422, 'Ce produit n\'a aucune variante.');

        $user = $r->user();
        $site = $user->sites()->wherePivot('is_default', true)->first() ?? $user->sites()->first();
        abort_if(! $site, 422, 'Aucun site associé à votre compte.');

        // Ventilé par site (comme le flux backoffice) au lieu d'écrire Produit::qte_stock
        // directement — c'était une des deux incohérences relevées avant refonte (l'API
        // ne passait pas par le stock par site).
        $existingCount = VarianteStock::where('produit_variante_id', $variante->id)->count();
        $varianteStock = VarianteStock::firstOrCreate(
            ['produit_variante_id' => $variante->id, 'site_id' => $site->id],
            [
                'organization_id' => $produit->organization_id,
                'qte_stock' => $existingCount === 0 ? (int) ($produit->qte_stock ?? 0) : 0,
            ]
        );

        $stockAvant = $varianteStock->qte_stock;

        if ($hasDiminuer && (int) $data['diminuer'] > $stockAvant) {
            throw ValidationException::withMessages([
                'diminuer' => "La quantité à retirer ({$data['diminuer']}) est supérieure au stock disponible ({$stockAvant}).",
            ]);
        }

        $notes = MotifAjustementStock::from($data['motif_type'])->toNotesString($data['motif_detail'] ?? '');

        if ($hasAugmenter) {
            $stockApres = $stockAvant + (int) $data['augmenter'];
            $type = 'entree';
            $quantite = (int) $data['augmenter'];
        } else {
            $stockApres = $stockAvant - (int) $data['diminuer'];
            $type = 'sortie';
            $quantite = (int) $data['diminuer'];
        }

        DB::transaction(function () use ($produit, $varianteStock, $variante, $type, $quantite, $stockAvant, $stockApres, $notes, $site, $user) {
            $varianteStock->update(['qte_stock' => $stockApres]);
            $produit->resynchroniserQteStock();

            MouvementStock::create([
                'organization_id' => $produit->organization_id,
                'site_id' => $site->id,
                'produit_variante_id' => $variante->id,
                'type' => $type,
                'quantite' => $quantite,
                'stock_avant' => $stockAvant,
                'stock_apres' => $stockApres,
                'notes' => $notes,
                'created_by' => $user->id,
            ]);

            $this->auditService->record(
                $produit,
                AuditEvent::STOCK_ADJUSTED,
                $user,
                ['qte_stock' => $stockAvant],
                ['qte_stock' => $stockApres, 'motif' => $notes],
            );
        });

        return response()->json(new ProduitResource($produit->fresh(['variantes', 'categorie', 'medias'])));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function produitSnapshot(Produit $produit): array
    {
        $variante = $produit->variantes->firstWhere('is_default', true) ?? $produit->variantes->first();

        return array_filter([
            'nom' => $produit->nom,
            'type' => $produit->type?->label(),
            'statut' => $produit->statut?->label(),
            'prix_vente' => $variante?->prix_vente,
            'prix_achat' => $variante?->prix_achat,
            'prix_usine' => $variante?->prix_usine,
            'cout' => $variante?->cout,
            'qte_stock' => $produit->qte_stock,
            'seuil_alerte_stock' => $variante?->seuil_alerte_stock,
            'is_alerte' => $produit->is_alerte,
            'description' => $produit->description,
            'code_fournisseur' => $variante?->code_fournisseur,
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
