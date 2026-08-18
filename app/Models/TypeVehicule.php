<?php

namespace App\Models;

use App\Enums\CategorieTarifaireVehicule;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Classification pure d'un véhicule (Tricycle, Camion...) — décision produit du 17/08/2026 :
 * ne porte plus aucune capacité (ni défaut, ni héritage). La capacité de chargement appartient
 * exclusivement au véhicule lui-même, voir Vehicule::capacites() / VehiculeCapaciteService.
 */
class TypeVehicule extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'type_vehicules';

    protected $fillable = [
        'organization_id',
        'nom',
        'description',
        'categorie_tarifaire',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'categorie_tarifaire' => CategorieTarifaireVehicule::class,
        ];
    }

    /**
     * `capacite_defaut` reste NOT NULL en base (colonne morte, cf. docblock de classe) — sur
     * MySQL la migration l'a rendue nullable, mais SQLite (tests automatisés) n'a pas
     * d'ALTER COLUMN et garde donc la contrainte d'origine. Placeholder posé ici, une seule
     * fois, plutôt que dans chaque appelant (contrôleur/factory/seeder) — jamais lu ailleurs.
     */
    protected static function booted(): void
    {
        static::creating(function (self $type) {
            $type->capacite_defaut ??= 0;
        });
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
