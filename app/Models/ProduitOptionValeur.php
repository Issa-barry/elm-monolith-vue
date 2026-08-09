<?php

namespace App\Models;

use App\Models\Concerns\NormalizesLabel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProduitOptionValeur extends Model
{
    use HasUlids, NormalizesLabel;

    protected $fillable = [
        'organization_id',
        'produit_option_id',
        'valeur',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function setValeurAttribute(mixed $value): void
    {
        $this->attributes['valeur'] = static::normalizeLabel($value);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(ProduitOption::class, 'produit_option_id');
    }
}
