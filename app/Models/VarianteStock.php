<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VarianteStock extends Model
{
    use HasUlids;

    protected $table = 'variante_stocks';

    protected $fillable = [
        'organization_id',
        'produit_variante_id',
        'site_id',
        'qte_stock',
        'seuil_alerte_stock',
        'is_alerte',
    ];

    protected $casts = [
        'qte_stock' => 'integer',
        'seuil_alerte_stock' => 'integer',
        'is_alerte' => 'boolean',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProduitVariante::class, 'produit_variante_id');
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
