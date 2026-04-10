<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehiculeFrais extends Model
{
    protected $table = 'vehicule_frais';

    protected $fillable = ['vehicule_id', 'montant', 'type', 'commentaire'];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
        ];
    }

    public function vehicule(): BelongsTo
    {
        return $this->belongsTo(Vehicule::class);
    }
}
