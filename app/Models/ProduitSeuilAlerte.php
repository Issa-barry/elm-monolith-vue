<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuration d'un produit pour un COUPLE (produit, site) — deux notions INDÉPENDANTES
 * cohabitent sur la même ligne, cf. migrations create_produit_seuils_alerte_table (29/08/2026),
 * add_actif_to_produit_seuils_alerte_table (01/09/2026) et
 * add_disponible_to_produit_seuils_alerte_table (02/09/2026 après-midi) :
 *
 *   - `disponible` — DISPONIBILITÉ : ce produit est-il vendu/géré sur ce site ? Défaut TRUE :
 *     disponible PARTOUT tant qu'aucune restriction explicite n'a été enregistrée (mode "Tous
 *     les sites"). Un site non disponible n'affiche jamais de rupture "métier" ni ne génère
 *     d'alerte, quel que soit son stock physique (cf. StockStatutService::disponiblePourSite()).
 *   - `actif` — ALERTE : faut-il surveiller/notifier ce couple ? Défaut FALSE, jamais implicite
 *     (cf. StockStatutService::alerteActivePourSite()) — un site DISPONIBLE mais sans alerte
 *     affiche quand même son état réel (le stock physique reste réel), simplement sans
 *     notification/email.
 *   - `seuil_alerte_stock` — seuil spécifique, nullable même quand `actif` est vrai : absent =
 *     repli sur le seuil global de l'organisation (Parametre::getSeuilStockFaible()), jamais 0
 *     implicite (cf. StockStatutService::seuilEffectifPourSite()).
 *
 * Ces trois champs s'écrivent indépendamment (cf. ProduitSeuilAlerteService::definir()/
 * definirDisponibilite()) : modifier l'un ne doit jamais écraser silencieusement les autres.
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
        'disponible',
        'actif',
        'seuil_alerte_stock',
    ];

    protected $casts = [
        'disponible' => 'boolean',
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
