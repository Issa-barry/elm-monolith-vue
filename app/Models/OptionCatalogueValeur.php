<?php

namespace App\Models;

use App\Models\Concerns\NormalizesLabel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OptionCatalogueValeur extends Model
{
    use HasUlids, NormalizesLabel;

    protected $fillable = [
        'option_catalogue_id',
        'valeur',
        'hex',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function setValeurAttribute(mixed $value): void
    {
        $this->attributes['valeur'] = static::normalizeLabel($value);
    }

    public function optionCatalogue(): BelongsTo
    {
        return $this->belongsTo(OptionCatalogue::class);
    }
}
