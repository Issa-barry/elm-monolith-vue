<?php

namespace App\Models;

use App\Enums\StatutReservationStock;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockReservation extends Model
{
    use HasUlids;

    protected $table = 'stock_reservations';

    protected $fillable = [
        'organization_id',
        'site_id',
        'produit_variante_id',
        'quantite',
        'statut',
        'source_type',
        'source_id',
        'created_by',
        'reserved_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'quantite' => 'integer',
            'statut' => StatutReservationStock::class,
            'reserved_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function variante(): BelongsTo
    {
        return $this->belongsTo(ProduitVariante::class, 'produit_variante_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->statut === StatutReservationStock::ACTIVE;
    }
}
