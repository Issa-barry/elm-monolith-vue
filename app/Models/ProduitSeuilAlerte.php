<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Seuil d'alerte de stock faible spécifique à un COUPLE (produit, site) — cf. migration
 * create_produit_seuils_alerte_table et StockStatutService::seuilEffectifPourSite(), seule
 * lectrice de ce seuil. Absence de ligne pour un site = repli sur le seuil global de
 * l'organisation (Parametre::getSeuilStockFaible()), jamais 0 implicite.
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
        'seuil_alerte_stock',
    ];

    protected $casts = [
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
