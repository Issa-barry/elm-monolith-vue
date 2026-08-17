<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Groupe de capacité / groupe de chargement (ex: "Sachets", "Bouteilles") — unité de
 * regroupement du moteur de capacité véhicule (VehiculeCapaciteService), délibérément distincte
 * de Categorie (classification du catalogue produit) — voir migration create_groupes_capacite_table.
 */
class GroupeCapacite extends Model
{
    use HasFactory, HasUlids;

    // Nom de table françisé (groupes_capacite), pas la pluralisation Eloquent par défaut
    // (aurait donné groupe_capacites) — doit matcher exactement la migration.
    protected $table = 'groupes_capacite';

    protected $fillable = [
        'organization_id',
        'nom',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class);
    }

    public function vehiculeCapacites(): HasMany
    {
        return $this->hasMany(VehiculeCapacite::class);
    }

    public function getIsUsedAttribute(): bool
    {
        return $this->produits()->exists() || $this->vehiculeCapacites()->exists();
    }
}
