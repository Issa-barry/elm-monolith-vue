<?php

namespace App\Models;

use App\Enums\TypeVariablePaie;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaieVariable extends Model
{
    use HasUlids;

    protected $fillable = [
        'paie_ligne_id',
        'depense_id',
        'type',
        'libelle',
        'montant',
        'note',
    ];

    protected $casts = [
        'type' => TypeVariablePaie::class,
        'montant' => 'decimal:2',
    ];

    public function ligne(): BelongsTo
    {
        return $this->belongsTo(PaieLigne::class, 'paie_ligne_id');
    }

    public function depense(): BelongsTo
    {
        return $this->belongsTo(Depense::class);
    }
}
