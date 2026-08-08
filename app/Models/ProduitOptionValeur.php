<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProduitOptionValeur extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'produit_option_id',
        'valeur',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function option(): BelongsTo
    {
        return $this->belongsTo(ProduitOption::class, 'produit_option_id');
    }
}
