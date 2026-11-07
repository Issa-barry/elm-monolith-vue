<?php

namespace App\Models;

use App\Enums\ContactCategorie;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * Sous-catégorie d'une fonction du référentiel Contacts (ADR 0060). Une sous-catégorie n'est jamais
 * supprimée : désactivée, elle n'est plus proposée mais reste affichée sur les fiches qui la portent.
 * Une sous-catégorie système (natures client, Consultant — D18) ne change ni de code, ni de
 * catégorie, et ne se désactive pas : seul son libellé et son ordre sont modifiables.
 */
class ContactSousCategorie extends Model
{
    use HasUlids;

    protected $table = 'contact_sous_categories';

    protected $fillable = [
        'organization_id', 'categorie', 'code', 'libelle', 'is_active', 'ordre',
    ];

    protected function casts(): array
    {
        return [
            'categorie' => ContactCategorie::class,
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'ordre' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $sousCategorie) {
            if (! $sousCategorie->getOriginal('is_system')) {
                return;
            }

            if ($sousCategorie->isDirty(['code', 'categorie', 'is_system'])
                || ($sousCategorie->isDirty('is_active') && ! $sousCategorie->is_active)) {
                throw ValidationException::withMessages([
                    'code' => 'Cette sous-catégorie est utilisée par les règles de prix ou de commission : seul son libellé peut être modifié.',
                ]);
            }
        });

        static::deleting(function () {
            throw ValidationException::withMessages([
                'code' => 'Une sous-catégorie ne se supprime pas : désactivez-la.',
            ]);
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

    public function scopePourCategorie(Builder $query, ContactCategorie $categorie): Builder
    {
        return $query->where('categorie', $categorie->value);
    }
}
