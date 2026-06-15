<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DepenseImputation extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'depense_id',
        'imputation_type',
        'beneficiaire_type',
        'beneficiaire_id',
        'montant',
        'periode_type',
        'periode_debut',
        'periode_fin',
        'statut',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'periode_debut' => 'date',
            'periode_fin' => 'date',
        ];
    }

    public function depense(): BelongsTo
    {
        return $this->belongsTo(Depense::class);
    }
}
