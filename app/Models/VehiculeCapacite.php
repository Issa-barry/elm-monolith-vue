<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Capacité maximale de chargement d'UN véhicule pour un groupe de capacité donné (ex : "Sachets"
 * = 1700, "Bouteilles" = 3400) — seule et unique source de vérité de la capacité (décision
 * produit du 17/08/2026 : plus aucun héritage/repli depuis TypeVehicule, qui redevient une pure
 * classification). Voir VehiculeCapaciteService.
 */
class VehiculeCapacite extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'vehicule_id',
        'groupe_capacite_id',
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

    public function groupeCapacite(): BelongsTo
    {
        return $this->belongsTo(GroupeCapacite::class);
    }
}
