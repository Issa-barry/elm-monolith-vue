<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaiementCommissionVenteItem extends Model
{
    use HasUlids;

    protected $table = 'paiements_commissions_ventes_items';

    protected $fillable = [
        'paiement_id',
        'commission_part_id',
        'amount_allocated',
    ];

    protected function casts(): array
    {
        return [
            'amount_allocated' => 'decimal:2',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function paiement(): BelongsTo
    {
        return $this->belongsTo(PaiementCommissionVente::class, 'paiement_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(CommissionPart::class, 'commission_part_id');
    }
}
