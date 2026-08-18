<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Capacité maximale de chargement d'UN véhicule pour une catégorie produit donnée (ex :
 * "Sachet eau" = 80, "Bouteille" = 160) — seule et unique source de vérité de la capacité
 * (décision produit du 17/08/2026 : plus aucun héritage/repli depuis TypeVehicule, qui reste
 * une pure classification). La référence est directement la Categorie du catalogue produit —
 * pas de notion intermédiaire de "groupe de capacité". Voir VehiculeCapaciteService.
 */
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
