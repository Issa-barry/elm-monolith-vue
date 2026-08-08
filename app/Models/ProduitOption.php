<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProduitOption extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'produit_id',
        'nom',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    public function valeurs(): HasMany
    {
        return $this->hasMany(ProduitOptionValeur::class)->orderBy('position');
    }
}
