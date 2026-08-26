<?php

namespace App\Models;

use App\Enums\CategorieDepense;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DepenseType extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $table = 'depense_types';

    protected $fillable = [
        'organization_id',
        'code',
        'libelle',
        'description',
        'categorie',
        'commentaire_obligatoire',
        'justificatif_obligatoire',
        'type_paie',
        'is_active',
        'compte_comptable_id',
    ];

    protected function casts(): array
    {
        return [
            'categorie' => CategorieDepense::class,
            'commentaire_obligatoire' => 'boolean',
            'justificatif_obligatoire' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('libelle');
    }

    /**
     * Code d'affichage stable pour un frontend (ex: filtre par icône) même
     * quand `code` n'est pas renseigné — replié sur `libelle` normalisé.
     * Partagé entre VehiculeFraisController et DepensesController (API) pour
     * ne pas dupliquer cette normalisation à chaque nouvel endpoint dépenses.
     */
    public static function normalizedCode(?string $code, ?string $libelle): string
    {
        $raw = $code ?? $libelle ?? 'autre';

        return strtolower(
            str_replace(['é', 'è', 'ê', 'à', 'â', 'î', 'ô', 'û', ' '], ['e', 'e', 'e', 'a', 'a', 'i', 'o', 'u', '_'], $raw)
        );
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function depenses(): HasMany
    {
        return $this->hasMany(Depense::class);
    }

    public function compteComptable(): BelongsTo
    {
        return $this->belongsTo(CompteComptable::class, 'compte_comptable_id');
    }
}
