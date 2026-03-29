<?php

namespace App\Models;

use App\Enums\TypeVehicule;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicule extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'nom_vehicule',
        'marque',
        'modele',
        'immatriculation',
        'type_vehicule',
        'capacite_packs',
        'proprietaire_id',
        'livreur_principal_id',
        'pris_en_charge_par_usine',
        'taux_commission_livreur',
        'taux_commission_proprietaire',
        'commission_active',
        'photo_path',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'pris_en_charge_par_usine' => 'boolean',
            'commission_active' => 'boolean',
            'type_vehicule' => TypeVehicule::class,
            'capacite_packs' => 'integer',
            'taux_commission_livreur' => 'decimal:2',
            'taux_commission_proprietaire' => 'decimal:2',
        ];
    }

    protected $appends = ['photo_url', 'type_label'];

    public function getPhotoUrlAttribute(): ?string
    {
        if (empty($this->photo_path)) {
            return null;
        }

        return '/storage/'.$this->photo_path;
    }

    public function getTypeLabelAttribute(): string
    {
        return $this->type_vehicule instanceof TypeVehicule
            ? $this->type_vehicule->label()
            : '';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function proprietaire(): BelongsTo
    {
        return $this->belongsTo(Proprietaire::class);
    }

    public function livreurPrincipal(): BelongsTo
    {
        return $this->belongsTo(Livreur::class, 'livreur_principal_id');
    }
}
