<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VersementCommissionLogistique extends Model
{
    use HasUlids;

    protected $table = 'versements_commission_logistique';

    protected $fillable = [
        'commission_logistique_part_id',
        'montant',
        'date_versement',
        'mode_paiement',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'date_versement' => 'date',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function part(): BelongsTo
    {
        return $this->belongsTo(CommissionLogistiquePart::class, 'commission_logistique_part_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
