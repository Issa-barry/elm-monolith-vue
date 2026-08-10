<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehiculeCapacite extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'vehicule_id',
        'categorie_id',
        'capacite_max',
    ];

    protected function casts(): array
    {
        return [
            'capacite_max' => 'integer',
        ];
    }

    public function vehicule(): BelongsTo
    {
        return $this->belongsTo(Vehicule::class);
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(Categorie::class);
    }
}
