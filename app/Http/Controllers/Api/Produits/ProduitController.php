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
use App\Services\AuditLogService;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProduitController extends Controller
{
    public function __construct(private readonly AuditLogService $auditService) {}

    public function index(Request $r): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorize('viewAny', Produit::class);

        $produits = Produit::where('organization_id', $r->user()->organization_id)
            ->orderBy('nom')
            ->get();

        $produitIds = $produits->pluck('id')->all();
        $usedIds = collect()
            ->merge(DB::table('commande_vente_lignes')->whereIn('produit_id', $produitIds)->pluck('produit_id'))
            ->merge(DB::table('commande_achat_lignes')->whereIn('produit_id', $produitIds)->whereNotNull('produit_id')->pluck('produit_id'))
            ->unique()
            ->flip()
            ->all();

        $produits->each(fn (Produit $p) => $p->is_used_loaded = isset($usedIds[$p->id]));

        return ProduitResource::collection($produits);
    }

    public function show(Request $r, Produit $produit): JsonResponse
    {
        $this->authorize('view', $produit);

        return response()->json(new ProduitResource($produit));
    }

    public function store(StoreProduitRequest $r): JsonResponse
    {
        $data = $r->validated();

        if ($r->hasFile('image')) {
            $path = (new ImageService)->storeAsWebp($r->file('image'), 'produits');
            $data['image_url'] = '/storage/'.$path;
        }
        unset($data['image']);

        $orgId = $r->user()->organization_id;
        abort_if(! $orgId, 403, 'Votre compte n\'est associé à aucune organisation.');

        $produit = Produit::create([
            ...$data,
            'organization_id' => $orgId,
        ]);

        $this->auditService->record(
            $produit,
            AuditEvent::CREATED,
            $r->user(),
            null,
            $this->produitSnapshot($produit),
        );

        return response()->json(new ProduitResource($produit), 201);
    }

    public function update(UpdateProduitRequest $r, Produit $produit): JsonResponse
    {
        $data = $r->validated();

        if ($r->hasFile('image')) {
            $imageService = new ImageService;
            $imageService->delete(str_replace('/storage/', '', $produit->image_url ?? ''));
            $path = $imageService->storeAsWebp($r->file('image'), 'produits');
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
                $r->user(),
                $oldDiff,
                $newDiff,
            );
        }

        return response()->json(new ProduitResource($produit));
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

        return response()->json(new ProduitResource($produit->fresh()));
    }

    public function destroy(Request $r, Produit $produit): JsonResponse
    {
        $this->authorize('delete', $produit);

        $this->auditService->record(
            $produit,
            AuditEvent::DELETED,
            $r->user(),
            $this->produitSnapshot($produit),
            null,
        );

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

        $stockAvant = (int) ($produit->qte_stock ?? 0);

        if ($hasDiminuer && (int) $data['diminuer'] > $stockAvant) {
            throw ValidationException::withMessages([
                'diminuer' => "La quantité à retirer ({$data['diminuer']}) est supérieure au stock disponible ({$stockAvant}).",
            ]);
        }

        $user = $r->user();
        $site = $user->sites()->wherePivot('is_default', true)->first() ?? $user->sites()->first();
        abort_if(! $site, 422, 'Aucun site associé à votre compte.');

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

        DB::transaction(function () use ($produit, $type, $quantite, $stockAvant, $stockApres, $notes, $site, $user) {
            $produit->update(['qte_stock' => $stockApres]);

            MouvementStock::create([
                'organization_id' => $produit->organization_id,
                'site_id' => $site->id,
                'produit_id' => $produit->id,
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

        $produit->refresh();

        return response()->json(new ProduitResource($produit));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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
