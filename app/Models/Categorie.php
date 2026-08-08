<?php

namespace App\Models;

use App\Enums\CategorieStatut;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Categorie extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'parent_id',
        'nom',
        'description',
        'statut',
        'position',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'statut' => CategorieStatut::class,
        'position' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Categorie $c) {
            if (Auth::check()) {
                $c->created_by = Auth::id();
                $c->updated_by = Auth::id();
            }
            if (empty($c->organization_id)) {
                $c->organization_id = Auth::user()?->organization_id;
            }
            if (empty($c->statut)) {
                $c->statut = CategorieStatut::ACTIF;
            }
        });

        static::updating(function (Categorie $c) {
            if (Auth::check()) {
                $c->updated_by = Auth::id();
            }
        });
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Categorie::class, 'parent_id');
    }

    public function enfants(): HasMany
    {
        return $this->hasMany(Categorie::class, 'parent_id');
    }

    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class, 'categorie_id');
    }

    // ── Accesseurs ────────────────────────────────────────────────────────────

    public function getIsUsedAttribute(): bool
    {
        return $this->produits()->exists() || $this->enfants()->exists();
    }

    /**
     * IDs de tous les descendants (récursif) — utilisé pour empêcher qu'une catégorie
     * soit rattachée comme parent d'un de ses propres descendants (cycle).
     *
     * @return string[]
     */
    public function descendantIds(): array
    {
        $ids = [];
        $aTraiter = $this->enfants()->pluck('id')->all();

        while (! empty($aTraiter)) {
            $id = array_pop($aTraiter);
            $ids[] = $id;
            $aTraiter = array_merge($aTraiter, static::where('parent_id', $id)->pluck('id')->all());
        }

        return $ids;
    }
}
