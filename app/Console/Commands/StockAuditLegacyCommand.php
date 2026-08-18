<?php

namespace App\Console\Commands;

use App\Models\Produit;
use Illuminate\Console\Command;

/**
 * Liste les produits dont l'agrégat legacy Produit::qte_stock est non nul alors
 * qu'aucune ligne VarianteStock n'existe encore (stock jamais ventilé par
 * agence). Ce stock ne peut pas être attribué automatiquement à une agence —
 * l'origine n'est pas déterminable (organisation multi-site, produit
 * multi-variantes) — cf. décision : aucune opération métier ne doit jamais
 * deviner implicitement l'agence propriétaire d'un ancien stock global.
 *
 * Purement informatif : ne modifie jamais rien. Sert à piloter la
 * réconciliation manuelle (saisie d'un stock initial par agence via l'écran
 * Ajuster le stock, désormais sûr — cf. MouvementStockService::appliquer()).
 */
class StockAuditLegacyCommand extends Command
{
    protected $signature = 'stock:audit-legacy {--organization= : ID d\'organisation ; toutes si omis}';

    protected $description = 'Liste les produits avec un stock legacy (qte_stock) non ventilé par agence, à réconcilier manuellement.';

    public function handle(): int
    {
        $query = Produit::query()
            ->where('qte_stock', '>', 0)
            ->whereDoesntHave('variantes.stocks');

        if ($orgId = $this->option('organization')) {
            $query->where('organization_id', $orgId);
        }

        $produits = $query->with('organization:id,nom')->orderBy('nom')->get();

        if ($produits->isEmpty()) {
            $this->info('Aucun produit avec un stock legacy non ventilé par agence.');

            return self::SUCCESS;
        }

        $this->warn("{$produits->count()} produit(s) avec un stock legacy non ventilé par agence :");
        $this->table(
            ['Organisation', 'Produit', 'qte_stock (agrégat)'],
            $produits->map(fn (Produit $p) => [
                $p->organization?->nom ?? $p->organization_id,
                $p->nom,
                $p->qte_stock,
            ]),
        );
        $this->line('Ce stock ne peut pas être attribué automatiquement à une agence : '.
            'réconcilier manuellement via "Ajuster le stock" sur chaque produit.');

        return self::SUCCESS;
    }
}
