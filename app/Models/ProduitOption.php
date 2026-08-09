<?php

namespace App\Models;

use App\Models\Concerns\NormalizesLabel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProduitOption extends Model
{
    use HasUlids, NormalizesLabel;

    protected $fillable = [
        'organization_id',
        'produit_id',
        'nom',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function setNomAttribute(mixed $value): void
    {
        $this->attributes['nom'] = static::normalizeLabel($value);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    public function valeurs(): HasMany
    {
        return $this->hasMany(ProduitOptionValeur::class)->orderBy('position');
    }
}
