<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class CashbackVersement extends Model
{
    protected $table = 'cashback_versements';

    protected $fillable = [
        'cashback_transaction_id',
        'montant',
        'mode_paiement',
        'date_versement',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'integer',
            'date_versement' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $v) => $v->created_by ??= Auth::id());
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(CashbackTransaction::class, 'cashback_transaction_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
