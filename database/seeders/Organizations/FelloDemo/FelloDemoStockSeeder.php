<?php

namespace Database\Seeders\Organizations\FelloDemo;

use App\Enums\MotifAjustementStock;
use App\Models\MouvementStock;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Site;
use App\Models\User;
use App\Models\VarianteStock;
use Illuminate\Database\Seeder;

/**
 * Stock initial par boutique — volontairement différent entre Madina (plus
 * gros volumes) et Cosa (stock réduit, quelques produits absents ou au
 * contraire plus forts localement), pour illustrer la gestion de stock par
 * site dans la démo.
 *
 * Depuis la refonte variant-first, le stock est porté par la variante (ici la variante
 * par défaut de chaque produit simple — aucun produit de cette liste n'a de déclinaisons).
 *
 * Le seuil d'alerte (10, cf. FelloDemoCatalogSeeder) vit désormais sur le PRODUIT, pas sur
 * VarianteStock — ce seeder ne fait que ventiler la quantité par site, l'état
 * disponible/faible/rupture est recalculé automatiquement à l'affichage (StockStatutService).
 */
class FelloDemoStockSeeder extends Seeder
{
    /** @var array<string, array{0: int, 1: int}> nom_produit => [qte_madina, qte_cosa] */
    private const STOCKS = [
        'T-shirt coton homme' => [45, 18],
        'T-shirt premium femme' => [38, 0],
        'T-shirt manches courtes enfant' => [52, 22],
        'Chemise manches longues' => [30, 10],
        'Chemise wax' => [22, 26],
        'Chemise slim fit homme' => [28, 6],
        'Jean slim homme' => [35, 15],
        'Pantalon classique femme' => [40, 0],
        'Short bermuda homme' => [48, 20],
        'Robe wax courte' => [18, 9],
        'Robe longue élégante' => [12, 4],
        'Ensemble femme' => [15, 7],
        'Sneakers homme' => [20, 12],
        'Sandales femme' => [25, 28],
        'Basket running femme' => [8, 0],
        'Ceinture cuir' => [55, 19],
        'Sac à main' => [24, 11],
        'Casquette' => [60, 24],
        'Foulard' => [42, 16],
        'Chaussettes lot de 3' => [58, 30],
        'Montre bracelet homme' => [5, 3],
    ];

    public function run(): void
    {
        $org = Organization::where('slug', 'fello-demo')->firstOrFail();
        $madina = Site::where('organization_id', $org->id)->where('nom', 'Boutique Madina')->firstOrFail();
        $cosa = Site::where('organization_id', $org->id)->where('nom', 'Boutique Cosa')->firstOrFail();
        // mouvements_stock.created_by est obligatoire (NOT NULL) — l'admin de
        // démo est crédité de la mise en stock initiale.
        $admin = User::where('organization_id', $org->id)->where('telephone', '+224600000101')->firstOrFail();

        foreach (self::STOCKS as $nom => [$qteMadina, $qteCosa]) {
            $produit = Produit::where('organization_id', $org->id)->where('nom', $nom)->first();
            if (! $produit) {
                $this->command->warn("Produit introuvable, stock ignoré : {$nom}");

                continue;
            }

            $variante = $produit->variantePrincipale()->first();
            if (! $variante) {
                $this->command->warn("Aucune variante pour le produit, stock ignoré : {$nom}");

                continue;
            }

            $this->ventilerStock($variante->id, $madina, $qteMadina, $org->id, $admin->id);
            $this->ventilerStock($variante->id, $cosa, $qteCosa, $org->id, $admin->id);

            $produit->resynchroniserQteStock();
        }

        $this->command->info('✓ Stock initial ventilé sur Boutique Madina et Boutique Cosa.');
    }

    private function ventilerStock(string $varianteId, Site $site, int $qte, string $orgId, string $adminId): void
    {
        $stock = VarianteStock::updateOrCreate(
            ['produit_variante_id' => $varianteId, 'site_id' => $site->id],
            [
                'organization_id' => $orgId,
                'qte_stock' => $qte,
            ]
        );

        $dejaTrace = MouvementStock::where('source_type', VarianteStock::class)
            ->where('source_id', $stock->id)
            ->where('type', 'entree')
            ->exists();

        if ($qte > 0 && ! $dejaTrace) {
            MouvementStock::create([
                'organization_id' => $orgId,
                'site_id' => $site->id,
                'produit_variante_id' => $varianteId,
                'type' => 'entree',
                'quantite' => $qte,
                'stock_avant' => 0,
                'stock_apres' => $qte,
                'source_type' => VarianteStock::class,
                'source_id' => $stock->id,
                'notes' => MotifAjustementStock::AUTRE->toNotesString('Stock initial démo Fello Demo'),
                'created_by' => $adminId,
            ]);
        }
    }
}
