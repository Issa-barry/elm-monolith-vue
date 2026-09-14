<?php

namespace App\Http\Controllers\Produits;

use App\Enums\StockStatut;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\ProduitVariante;
use App\Models\Site;
use App\Models\VarianteStock;
use App\Services\DroitAjustementStockService;
use App\Services\MouvementStockMotifService;
use App\Services\StockStatutService;
use App\Support\Produits\ProduitFormOptions;
use App\Support\Produits\ProduitModificationsHistory;
use App\Support\Produits\ProduitVarianteOptionsFormatter;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ShowProduitController extends Controller
{
    public function __construct(
        private readonly DroitAjustementStockService $droitService,
        private readonly StockStatutService $stockStatutService,
    ) {}

    public function __invoke(Produit $produit): Response
    {
        $this->authorize('view', $produit);

        $produit->load(['categorie', 'fournisseur', 'produitType', 'variantes.valeurs.option', 'variantes.media', 'medias', 'seuilsAlerte']);
        $orgId = $produit->organization_id;
        $user = auth()->user();

        $allSites = Site::where('organization_id', $orgId)->orderBy('nom')->get(['id', 'nom', 'code']);

        $canAjuster = $this->droitService->canAjuster($user, $orgId);
        $sitesAutorisesRaw = $canAjuster
            ? ($this->droitService->sitesAutorises($user, $orgId) ?? $allSites)
            : collect();

        $canAugmenter = $this->droitService->canAugmenter($user, $orgId);
        $canDiminuer = $this->droitService->canDiminuer($user, $orgId);

        $varianteIds = $produit->variantes->pluck('id')->all();

        $varianteStocksRaw = VarianteStock::whereIn('produit_variante_id', $varianteIds)
            ->with('site:id,nom,code')
            ->get();

        // Grain variante+site — consommé par AjusterStockModal.vue pour afficher/pré-remplir
        // le stock réel de la variante sélectionnée (stocksParSite ci-dessous est un agrégat
        // toutes variantes confondues, insuffisant dès qu'on ajuste une variante précise).
        $varianteStocksParSiteEtVariante = $varianteStocksRaw
            ->map(fn (VarianteStock $s) => [
                'variante_id' => $s->produit_variante_id,
                'site_id' => $s->site_id,
                'qte_stock' => $s->qte_stock,
            ])
            ->values();

        $totalStock = $varianteStocksRaw->isNotEmpty() ? $varianteStocksRaw->sum('qte_stock') : (int) ($produit->qte_stock ?? 0);

        // Seuil résolu PAR SITE, évalué pour CHAQUE couple variante × site individuellement —
        // jamais sur le total agrégé (cf. décision produit : un stock élevé ailleurs — autre
        // variante, autre site — ne doit jamais masquer une alerte locale), ni sur la
        // configuration d'un AUTRE site. `statut` reste TOUJOURS l'état physique réel (fonction
        // pure statutPour(), cf. StockStatutService) — `disponible_sur_site`/`alerte_active` sont
        // exposés séparément pour piloter l'affichage ("Non disponible" à la place du statut
        // coloré) et le comptage d'alerte, jamais pour masquer le stock physique réel.
        $hasStock = $produit->produitType?->gere_stock ?? true;
        $varianteLibelleParId = $produit->variantes->pluck('libelle', 'id');

        $varianteStocksDetail = $varianteStocksRaw->map(function (VarianteStock $s) use ($hasStock, $produit, $varianteLibelleParId) {
            $seuil = $this->stockStatutService->seuilEffectifPourSite($produit, $s->site_id);
            $disponibleSurSite = $this->stockStatutService->disponiblePourSite($produit, $s->site_id);
            $alerteActive = $this->stockStatutService->alerteActivePourSite($produit, $s->site_id);
            $statut = $hasStock ? $this->stockStatutService->statutPour($s->qte_stock, $seuil) : StockStatut::DISPONIBLE;

            return [
                'variante_id' => $s->produit_variante_id,
                'variante_libelle' => $varianteLibelleParId->get($s->produit_variante_id, ''),
                'site_id' => $s->site_id,
                'site_code' => $s->site?->code,
                'site_nom' => $s->site?->nom,
                'qte_stock' => $s->qte_stock,
                'seuil_effectif' => $seuil,
                'disponible_sur_site' => $disponibleSurSite,
                'alerte_active' => $alerteActive,
                'statut' => $statut->value,
                'statut_label' => $statut->label(),
            ];
        })->values();

        $stocksParSite = $varianteStocksRaw
            ->groupBy('site_id')
            ->map(function (Collection $parSite, $siteId) use ($hasStock, $produit) {
                $premier = $parSite->first();
                $seuil = $this->stockStatutService->seuilEffectifPourSite($produit, $siteId);
                $disponibleSurSite = $this->stockStatutService->disponiblePourSite($produit, $siteId);
                $alerteActive = $this->stockStatutService->alerteActivePourSite($produit, $siteId);
                // Pire statut réel parmi les variantes de ce site (stock négatif > rupture >
                // stock faible > disponible) — un résumé par site reste utile, mais ne doit
                // jamais faire disparaître le pire cas derrière une moyenne/somme.
                $statuts = $hasStock
                    ? $parSite->map(fn (VarianteStock $s) => $this->stockStatutService->statutPour($s->qte_stock, $seuil))
                    : collect();
                $pire = match (true) {
                    $statuts->contains(StockStatut::STOCK_NEGATIF) => StockStatut::STOCK_NEGATIF,
                    $statuts->contains(StockStatut::RUPTURE) => StockStatut::RUPTURE,
                    $statuts->contains(StockStatut::STOCK_FAIBLE) => StockStatut::STOCK_FAIBLE,
                    default => StockStatut::DISPONIBLE,
                };

                return [
                    'site_id' => $siteId,
                    'site_code' => $premier->site?->code,
                    'site_nom' => $premier->site?->nom,
                    'qte_stock' => $parSite->sum('qte_stock'),
                    'seuil_effectif' => $seuil,
                    'disponible_sur_site' => $disponibleSurSite,
                    'alerte_active' => $alerteActive,
                    'statut' => $pire->value,
                    'statut_label' => $pire->label(),
                    'updated_at' => $parSite->max('updated_at'),
                ];
            })
            ->values();

        $mouvementsBaseQuery = MouvementStock::whereIn('produit_variante_id', $varianteIds);
        $motifsDisponibles = MouvementStockMotifService::optionsDisponibles(clone $mouvementsBaseQuery);

        $mouvementsCollection = (clone $mouvementsBaseQuery)
            ->with(['createur:id,personne_id', 'createur.personne', 'site:id,nom,code', 'variante:id,combo_hash'])
            ->orderByDesc('created_at')
            ->take(100)
            ->get();

        $mouvements = MouvementStockMotifService::annoter($mouvementsCollection)
            ->map(fn (MouvementStock $m) => [
                'id' => $m->id,
                'type' => $m->type,
                'quantite' => $m->quantite,
                'stock_avant' => $m->stock_avant,
                'stock_apres' => $m->stock_apres,
                'notes' => $m->notes,
                'motif_type' => $m->motif_type,
                'motif_label' => $m->motif_label,
                'site_nom' => $m->site?->nom,
                'site_code' => $m->site?->code,
                'created_at' => $m->created_at?->toISOString(),
                'createur_nom' => $m->createur
                    ? trim(($m->createur->prenom ?? '').' '.($m->createur->nom ?? ''))
                    : null,
                'is_initial' => false,
            ])
            ->toArray();

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
                'motif_type' => 'stock_initial',
                'motif_label' => 'Stock initial',
                'site_nom' => null,
                'site_code' => null,
                'created_at' => $creation->created_at?->toISOString(),
                'createur_nom' => $creation->actor_name_snapshot,
                'is_initial' => true,
            ];
            $motifsDisponibles[] = ['value' => 'stock_initial', 'label' => 'Stock initial'];
        }

        $variantePrincipale = $produit->variantes->firstWhere('is_default', true) ?? $produit->variantes->first();

        // Badges d'en-tête ("Rupture" / "Stock faible — N alerte(s)") : surface d'ALERTE, filtrée
        // aux couples variante × site à la fois disponibles ET avec alerte active — un site en
        // rupture réelle mais non disponible ou sans alerte n'y apparaît jamais (cf.
        // stocks_par_site/variante_stocks_detail ci-dessus pour l'état réel, toujours affiché).
        $detailAlerte = $varianteStocksDetail->filter(fn (array $d) => $d['disponible_sur_site'] && $d['alerte_active']);
        if ($hasStock && $detailAlerte->isNotEmpty()) {
            $isRupture = $detailAlerte->contains(fn (array $d) => in_array($d['statut'], [StockStatut::RUPTURE->value, StockStatut::STOCK_NEGATIF->value], true));
            $isLowStock = $detailAlerte->contains(fn (array $d) => $d['statut'] === StockStatut::STOCK_FAIBLE->value);
            $nombreAlertesStock = $detailAlerte->filter(fn (array $d) => $d['statut'] !== StockStatut::DISPONIBLE->value)->count();
        } else {
            $isRupture = false;
            $isLowStock = false;
            $nombreAlertesStock = 0;
        }

        return Inertia::render('Produits/Show', [
            'produit' => [
                'id' => $produit->id,
                'nom' => $produit->nom,
                'categorie' => $produit->categorie ? ['id' => $produit->categorie->id, 'nom' => $produit->categorie->nom] : null,
                'fournisseur' => $produit->fournisseur ? [
                    'id' => $produit->fournisseur->id,
                    'nom_complet' => $produit->fournisseur->nom_complet,
                    'phone' => $produit->fournisseur->phone,
                ] : null,
                'sku' => $variantePrincipale?->sku,
                'code_barres' => $variantePrincipale?->code_barres,
                'image_url' => $produit->image_url,
                'produit_type_id' => $produit->produit_type_id,
                'type_nom' => $produit->produitType?->nom,
                'prix_usine_requis' => (bool) $produit->produitType?->prix_usine_requis,
                // cf. ProduitFormOptions::types() : achetable/vendable pilotent la
                // visibilité de prix_achat/prix_vente dans VarianteEditModal.vue.
                'achetable' => (bool) ($produit->produitType?->achetable ?? true),
                'vendable' => (bool) ($produit->produitType?->vendable ?? true),
                'statut' => $produit->statut?->value,
                'statut_label' => $produit->statut?->label(),
                'prix_usine' => $variantePrincipale?->prix_usine,
                'prix_usine_tricycle' => $variantePrincipale?->prix_usine_tricycle,
                'prix_externe' => $variantePrincipale?->prix_externe,
                'prix_revendeur' => $variantePrincipale?->prix_revendeur,
                'prix_distributeur' => $variantePrincipale?->prix_distributeur,
                'prix_vente' => $variantePrincipale?->prix_vente,
                'prix_achat' => $variantePrincipale?->prix_achat,
                'cout' => $variantePrincipale?->cout,
                'qte_stock' => $totalStock,
                // Résumé "X/Y agences" du bandeau Alerte — scopé aux agences DISPONIBLES : une
                // agence où ce produit n'est pas vendu/géré n'a pas sa place dans ce ratio.
                'nombre_sites_alerte_active' => $stocksParSite->filter(fn (array $s) => $s['disponible_sur_site'] && $s['alerte_active'])->count(),
                'nombre_sites_stock' => $stocksParSite->filter(fn (array $s) => $s['disponible_sur_site'])->count(),
                'description' => $produit->description,
                'in_stock' => ! $hasStock || ! $isRupture,
                'is_low_stock' => $isLowStock,
                'is_out_of_stock' => $isRupture,
                'nombre_alertes_stock' => $nombreAlertesStock,
                'has_stock' => $hasStock,
                'created_at' => $produit->created_at?->toISOString(),
                'updated_at' => $produit->updated_at?->toISOString(),
                'stocks_par_site' => $stocksParSite,
                'variante_stocks_detail' => $varianteStocksDetail,
                'medias' => $produit->medias->map(fn ($m) => [
                    'id' => $m->id,
                    'url' => $m->url,
                    'thumb_url' => $m->thumb_url,
                    'is_primary' => $m->is_primary,
                    'position' => $m->position,
                ]),
                'variantes' => $produit->variantes->map(fn (ProduitVariante $v) => [
                    'id' => $v->id,
                    'libelle' => $v->libelle,
                    'sku' => $v->sku,
                    'code_barres' => $v->code_barres,
                    'prix_usine' => $v->prix_usine,
                    'prix_usine_tricycle' => $v->prix_usine_tricycle,
                    'prix_vente' => $v->prix_vente,
                    'prix_achat' => $v->prix_achat,
                    'cout' => $v->cout,
                    'is_default' => $v->is_default,
                    'is_active' => $v->is_active,
                    'options' => ProduitVarianteOptionsFormatter::pour($v),
                    'media_id' => $v->media_id,
                    'image_url' => $v->effective_image_url,
                ]),
            ],
            'mouvements' => collect($mouvements),
            'motifs_disponibles' => $motifsDisponibles,
            'historiques' => ProduitModificationsHistory::pour($produit),
            'can_ajuster_stock' => $canAjuster,
            'can_augmenter_stock' => $canAugmenter,
            'can_diminuer_stock' => $canDiminuer,
            'sites_autorises' => $sitesAutorisesRaw->values(),
            'variante_stocks' => $varianteStocksParSiteEtVariante,
            'limites' => ProduitFormOptions::limites($orgId),
        ]);
    }
}
