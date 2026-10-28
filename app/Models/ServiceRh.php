<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Service ou département de l'organisation (ex : « Logistique », « Commercial ») — référentiel RH
 * strictement isolé par organisation, sur le modèle de FonctionRh. Jamais supprimé en usage normal :
 * seulement désactivé pour de nouvelles affectations.
 */
class ServiceRh extends Model
{
    use HasUlids;

    protected $table = 'services_rh';

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
        return $this->hasMany(Employe::class, 'service_rh_id');
    }
}
