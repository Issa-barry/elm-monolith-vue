<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionPaymentItem extends Model
{
    use HasUlids;

    protected $table = 'commission_payment_items';

    protected $fillable = [
        'payment_id',
        'part_id',
        'amount_allocated',
    ];

    protected function casts(): array
    {
        return [
            'amount_allocated' => 'decimal:2',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function payment(): BelongsTo
    {
        return $this->belongsTo(CommissionPayment::class, 'payment_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(CommissionLogistiquePart::class, 'part_id');
    }
}
