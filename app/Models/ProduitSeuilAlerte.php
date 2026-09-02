<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuration de l'alerte de stock faible pour un COUPLE (produit, site) — cf. migrations
 * create_produit_seuils_alerte_table (29/08/2026) et add_actif_to_produit_seuils_alerte_table
 * (01/09/2026). Absence de ligne pour un site = alerte INACTIVE sur ce site, jamais implicite
 * (cf. StockStatutService::alerteActivePourSite()) — un produit non concerné par un site (ex. non
 * vendu dans cette agence) ne doit générer aucune alerte tant qu'un administrateur ne l'a pas
 * explicitement activée pour CE site. `seuil_alerte_stock` reste nullable même quand `actif` est
 * vrai : absent = repli sur le seuil global de l'organisation (Parametre::getSeuilStockFaible()),
 * jamais 0 implicite (cf. StockStatutService::seuilEffectifPourSite()).
 */
class ProduitSeuilAlerte extends Model
{
    use HasUlids;

    // Nom de table non déductible par la convention Eloquent par défaut (Str::plural()
    // pluraliserait "produit_seuil_alertes", pas "produit_seuils_alerte").
    protected $table = 'produit_seuils_alerte';

    protected $fillable = [
        'organization_id',
        'produit_id',
        'site_id',
        'actif',
        'seuil_alerte_stock',
    ];

    protected $casts = [
        'actif' => 'boolean',
        'seuil_alerte_stock' => 'integer',
    ];

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
