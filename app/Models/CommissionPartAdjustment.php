<?php

namespace App\Models;

use App\Enums\MotifAjustementCommission;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CommissionPartAdjustment extends Model
{
    use HasUlids;

    const UPDATED_AT = null;

    protected $fillable = [
        'commission_part_type',
        'commission_part_id',
        'ancien_montant',
        'nouveau_montant',
        'motif',
        'commentaire',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'ancien_montant' => 'decimal:2',
            'nouveau_montant' => 'decimal:2',
            'motif' => MotifAjustementCommission::class,
            'created_at' => 'datetime',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function commissionPart(): MorphTo
    {
        return $this->morphTo();
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getEcartAttribute(): float
    {
        return (float) $this->nouveau_montant - (float) $this->ancien_montant;
    }
}
