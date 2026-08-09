<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OptionCatalogueValeur extends Model
{
    use HasUlids;

    protected $fillable = [
        'option_catalogue_id',
        'valeur',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function optionCatalogue(): BelongsTo
    {
        return $this->belongsTo(OptionCatalogue::class);
    }
}
