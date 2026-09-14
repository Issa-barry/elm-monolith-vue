<?php

namespace App\Http\Controllers\Produits;

use App\Enums\AuditEvent;
use App\Enums\MotifAjustementStock;
use App\Http\Controllers\Controller;
use App\Models\Produit;
use App\Models\ProduitVariante;
use App\Models\Site;
use App\Services\AuditLogService;
use App\Services\DroitAjustementStockService;
use App\Services\MouvementStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AjusterStockProduitController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditService,
        private readonly DroitAjustementStockService $droitService,
    ) {}

    public function __invoke(Request $request, Produit $produit): RedirectResponse
    {
        $this->authorize('ajusterStock', $produit);
        abort_unless((bool) $produit->produitType?->gere_stock, 422, 'Ce produit ne gère pas de stock.');

        $data = $request->validate([
            'site_id' => ['required', 'exists:sites,id'],
            'variante_id' => ['nullable', 'exists:produit_variantes,id'],
            'augmenter' => ['nullable', 'integer', 'min:1'],
            'diminuer' => ['nullable', 'integer', 'min:1'],
            'motif_type' => ['required', Rule::in(MotifAjustementStock::validValues())],
            'motif_detail' => [
                'required_if:motif_type,autre',
                'nullable',
                'string',
                'max:500',
                function ($attribute, $value, $fail) {
                    if ($value !== null && trim($value) === '') {
                        $fail('Veuillez préciser le motif.');
                    }
                },
            ],
        ], [
            'site_id.required' => 'Le site est obligatoire.',
            'site_id.exists' => 'Le site sélectionné est invalide.',
            'augmenter.integer' => 'La quantité doit être un nombre entier.',
            'augmenter.min' => 'La quantité doit être supérieure à 0.',
            'diminuer.integer' => 'La quantité doit être un nombre entier.',
            'diminuer.min' => 'La quantité doit être supérieure à 0.',
            'motif_type.required' => 'Le motif est obligatoire.',
            'motif_type.in' => 'Le motif sélectionné est invalide.',
            'motif_detail.required_if' => 'Veuillez préciser le motif.',
            'motif_detail.max' => 'Le détail du motif ne peut pas dépasser 500 caractères.',
        ]);

        $site = Site::where('id', $data['site_id'])->where('organization_id', $produit->organization_id)->firstOrFail();

        // Produit à vraies déclinaisons : la variante est obligatoire, jamais un défaut implicite
        // silencieux — sinon un appel sans variante_id ajusterait la variante par défaut cachée
        // au lieu de la déclinaison réellement visée par l'utilisateur.
        if (empty($data['variante_id']) && $produit->variantes()->count() > 1) {
            throw ValidationException::withMessages([
                'variante_id' => 'Ce produit a plusieurs variantes : précisez laquelle ajuster.',
            ]);
        }

        $variante = $data['variante_id'] ?? null
            ? ProduitVariante::where('id', $data['variante_id'])->where('produit_id', $produit->id)->firstOrFail()
            : ($produit->variantePrincipale()->first() ?? abort(422, 'Ce produit n\'a aucune variante.'));

        $user = auth()->user();
        $hasAugmenter = ! empty($data['augmenter']);
        $hasDiminuer = ! empty($data['diminuer']);

        $direction = $hasAugmenter ? 'augmenter' : 'diminuer';
        if (! $this->droitService->canAjusterSurSite($user, $produit->organization_id, $site->id, $direction)) {
            abort(403, 'Vous n\'êtes pas autorisé à '.$direction.' le stock de cette agence.');
        }

        if ($hasAugmenter && $hasDiminuer) {
            throw ValidationException::withMessages(['augmenter' => 'Renseignez uniquement l\'un des deux champs.']);
        }
        if (! $hasAugmenter && ! $hasDiminuer) {
            throw ValidationException::withMessages(['augmenter' => 'Veuillez renseigner la quantité à augmenter ou à diminuer.']);
        }

        $direction = $hasAugmenter ? 'entree' : 'sortie';
        if (! in_array($data['motif_type'], MotifAjustementStock::validValuesForDirection($direction), true)) {
            throw ValidationException::withMessages(['motif_type' => 'Ce motif n\'est pas valide pour ce type d\'ajustement.']);
        }

        $stockAvant = MouvementStockService::quantiteDisponible($variante->id, $site->id);
        $notes = MotifAjustementStock::from($data['motif_type'])->toNotesString($data['motif_detail'] ?? '');

        if ($hasDiminuer && (int) $data['diminuer'] > $stockAvant) {
            throw ValidationException::withMessages([
                'diminuer' => "La quantité à retirer ({$data['diminuer']}) est supérieure au stock disponible sur ce site ({$stockAvant}).",
            ]);
        }

        $quantite = $hasAugmenter ? (int) $data['augmenter'] : (int) $data['diminuer'];
        $type = $hasAugmenter ? 'entree' : 'sortie';

        DB::transaction(function () use ($produit, $variante, $site, $type, $quantite, $notes, $user) {
            $mouvement = MouvementStockService::appliquer(
                varianteId: $variante->id,
                siteId: $site->id,
                orgId: $produit->organization_id,
                type: $type,
                quantite: $quantite,
                userId: $user->id,
                notes: $notes,
            );

            $this->auditService->record(
                $produit,
                AuditEvent::STOCK_ADJUSTED,
                $user,
                ['qte_stock' => $mouvement->stock_avant, 'site' => $site->nom],
                [
                    'qte_stock' => $mouvement->stock_apres,
                    'site' => $site->nom,
                    'motif' => $notes,
                    'role' => $user->roles->first()?->name,
                ],
            );
        });

        return back()->with('success', 'Stock mis à jour avec succès.');
    }
}
