<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TypeVehicule extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'type_vehicules';

    protected $fillable = [
        'organization_id',
        'nom',
        'capacite_defaut',
        'unite_capacite',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'capacite_defaut' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function vehicules(): HasMany
    {
        return $this->hasMany(Vehicule::class, 'type_vehicule_id');
    }
}
