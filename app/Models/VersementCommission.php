<?php

namespace App\Models;

use App\Enums\ModePaiement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class VersementCommission extends Model
{
    use HasFactory;

    protected $table = 'versements_commissions';

    protected $fillable = [
        'commission_vente_id',
        'montant',
        'beneficiaire',
        'date_versement',
        'mode_paiement',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'date_versement' => 'date:Y-m-d',
            'mode_paiement' => ModePaiement::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (VersementCommission $v) => $v->created_by = Auth::id());

        static::created(fn (VersementCommission $v) => $v->commission->recalculStatut());
        static::deleted(fn (VersementCommission $v) => $v->commission->recalculStatut());
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(CommissionVente::class, 'commission_vente_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
