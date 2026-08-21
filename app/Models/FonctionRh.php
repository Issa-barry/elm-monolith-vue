<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fonction RH (métier de la personne) — jamais une source de permissions, cf. CLAUDE de session
 * "gestion des fonctions". Strictement isolée par organisation (`organization_id` obligatoire) :
 * aucune fonction système/partagée, aucune suggestion de profil d'accès — chaque organisation
 * construit son propre vocabulaire métier de zéro (décision finale du 2026-08-21).
 */
class FonctionRh extends Model
{
    use HasUlids;

    protected $table = 'fonctions_rh';

    protected $fillable = [
        'organization_id',
        'libelle',
        'code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function employes(): HasMany
    {
        return $this->hasMany(Employe::class, 'fonction_rh_id');
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(EmployeAffectation::class, 'fonction_rh_id');
    }
}
