<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashbackSolde extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'client_id',
        'cumul_achats',
        'cashback_en_attente',
        'total_cashback_gagne',
        'total_cashback_verse',
    ];

    protected function casts(): array
    {
        return [
            'cumul_achats' => 'integer',
            'cashback_en_attente' => 'integer',
            'total_cashback_gagne' => 'integer',
            'total_cashback_verse' => 'integer',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CashbackTransaction::class, 'client_id', 'client_id')
            ->where('organization_id', $this->organization_id);
    }
}
