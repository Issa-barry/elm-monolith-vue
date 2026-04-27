<?php

namespace App\Models;

use App\Enums\TypeVehicule;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicule extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'nom_vehicule',
        'marque',
        'modele',
        'immatriculation',
        'type_vehicule',
        'categorie',
        'capacite_packs',
        'proprietaire_id',
        'pris_en_charge_par_usine',
        'photo_path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'pris_en_charge_par_usine' => 'boolean',
            'type_vehicule' => TypeVehicule::class,
            'capacite_packs' => 'integer',
        ];
    }

    protected $appends = ['photo_url', 'type_label'];

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? '/storage/'.$this->photo_path : null;
    }

    public function getTypeLabelAttribute(): string
    {
        return $this->type_vehicule instanceof TypeVehicule
            ? $this->type_vehicule->label()
            : '';
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function proprietaire(): BelongsTo
    {
        return $this->belongsTo(Proprietaire::class);
    }

    public function equipe(): HasOne
    {
        return $this->hasOne(EquipeLivraison::class, 'vehicule_id');
    }

    public function frais(): HasMany
    {
        return $this->hasMany(VehiculeFrais::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(CommissionLogistique::class);
    }

    // ── Métier ────────────────────────────────────────────────────────────────

    /**
     * Somme taux propriétaire + taux membres équipe.
     * Doit être égale à 100 pour que les commissions soient générées.
     */
    public function sommeTauxTotale(): float
    {
        if (! $this->equipe) {
            return 0.0;
        }

        return (float) $this->equipe->taux_commission_proprietaire + $this->equipe->sommeTaux();
    }
}
