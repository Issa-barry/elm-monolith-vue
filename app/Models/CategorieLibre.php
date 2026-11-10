<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * Catégorie libre du référentiel Contacts (ADR 0060, lot 6a) : étiquette sans effet métier (ex.
 * Partenaire) posée sur une fiche Personne ou Entreprise. À ne pas confondre avec les fonctions
 * (App\Enums\ContactCategorie), les catégories de produits ou de véhicules, ni les natures client.
 * Le code ne change jamais après la création ; une catégorie ne se supprime pas, elle se désactive.
 */
class CategorieLibre extends Model
{
    use HasUlids;

    protected $table = 'contact_categories';

    protected $fillable = ['organization_id', 'code', 'libelle', 'is_active', 'ordre'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'ordre' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $categorie) {
            if ($categorie->isDirty(['code', 'organization_id'])) {
                throw ValidationException::withMessages(['code' => 'Le code d\'une catégorie ne change jamais après sa création.']);
            }
        });

        static::deleting(function () {
            throw ValidationException::withMessages(['code' => 'Une catégorie ne se supprime pas : désactivez-la.']);
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeActives(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
