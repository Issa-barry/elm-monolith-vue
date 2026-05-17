<?php

namespace App\Models;

use App\Enums\StatutContrat;
use App\Enums\StatutEmploye;
use App\Enums\TypeEmploye;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employe extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'matricule',
        'nom',
        'prenom',
        'email',
        'telephone',
        'type_employe',
        'site_id',
        'statut',
    ];

    protected function casts(): array
    {
        return [
            'type_employe' => TypeEmploye::class,
            'statut' => StatutEmploye::class,
        ];
    }

    public function getNomCompletAttribute(): string
    {
        return trim("{$this->prenom} {$this->nom}");
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function contrats(): HasMany
    {
        return $this->hasMany(Contrat::class);
    }

    public function contratActif(): HasOne
    {
        return $this->hasOne(Contrat::class)
            ->where('statut_contrat', StatutContrat::ACTIF->value)
            ->latest();
    }
}
