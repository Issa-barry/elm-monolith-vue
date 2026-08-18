<?php

namespace App\Models;

use App\Enums\CategorieStatut;
use App\Models\Concerns\NormalizesLabel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class Categorie extends Model
{
    use HasFactory, HasUlids, NormalizesLabel, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'parent_id',
        'nom',
        'reference',
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
            if (empty($c->reference)) {
                $c->reference = self::genererReferenceUnique($c->organization_id, $c->nom);
            }
        });

        static::updating(function (Categorie $c) {
            if (Auth::check()) {
                $c->updated_by = Auth::id();
            }
        });
    }

    /**
     * Référence machine stable, indépendante du `nom` (librement renommable) — sert de clé
     * robuste à l'import flotte (cf. ImportFlotteParser::resoudreCategoriesCapacite()) pour
     * cibler une catégorie même après renommage, et s'affiche à l'utilisateur (ex: "Réf.
     * BOUTEILLE_EAU") pour préparer un fichier d'import. Jamais régénérée sur update (immuable
     * une fois créée) — même pattern que ProduitType::genererCodeUnique(), en MAJUSCULES.
     */
    private static function genererReferenceUnique(string $organizationId, string $nom): string
    {
        $base = Str::upper(Str::slug($nom, '_')) ?: 'CATEGORIE';
        $reference = $base;
        $i = 2;

        while (static::withTrashed()->where('organization_id', $organizationId)->where('reference', $reference)->exists()) {
            $reference = "{$base}_{$i}";
            $i++;
        }

        return $reference;
    }

    // ── Mutateurs ─────────────────────────────────────────────────────────────

    public function setNomAttribute(mixed $value): void
    {
        $this->attributes['nom'] = static::normalizeLabel($value);
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

    /**
     * Plafond de chargement configuré sur un véhicule pour cette catégorie — voir
     * VehiculeCapaciteService. Une catégorie du catalogue peut servir à la fois de
     * classification produit et de référence de capacité ; les deux usages partagent
     * volontairement le même champ, il n'existe plus de notion de "groupe de capacité".
     */
    public function vehiculeCapacites(): HasMany
    {
        return $this->hasMany(VehiculeCapacite::class, 'categorie_id');
    }

    // ── Accesseurs ────────────────────────────────────────────────────────────

    public function getIsUsedAttribute(): bool
    {
        return $this->produits()->exists()
            || $this->enfants()->exists()
            || $this->vehiculeCapacites()->exists();
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
