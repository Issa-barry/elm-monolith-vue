<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProduitStock extends Model
{
    use HasUlids;

    protected $table = 'produit_stocks';

    protected $fillable = [
        'organization_id',
        'produit_id',
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
