<?php

namespace App\Models;

use App\Enums\ProduitStatut;
use App\Enums\ProduitType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Produit extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    public ?bool $is_used_loaded = null;

    protected $fillable = [
        'organization_id',
        'categorie_id',
        'nom',
        'type',
        'statut',
        'description',
        'qte_stock',
        'is_alerte',
        'archived_at',
        'created_by',
        'updated_by',
        'deleted_by',
        'archived_by',
    ];

    protected $casts = [
        'qte_stock' => 'integer',
        'is_alerte' => 'boolean',
        'archived_at' => 'datetime',
        'type' => ProduitType::class,
        'statut' => ProduitStatut::class,
    ];

    // ── Boot ──────────────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (Produit $p) {
            if (Auth::check()) {
                $p->created_by = Auth::id();
                $p->updated_by = Auth::id();
            }
            if (empty($p->organization_id)) {
                $p->organization_id = Auth::user()?->organization_id;
            }
        });

        static::updating(function (Produit $p) {
            if (Auth::check()) {
                $p->updated_by = Auth::id();
            }
            if ($p->isDirty('statut') && $p->statut === ProduitStatut::ARCHIVE && ! $p->archived_at) {
                $p->archived_at = now();
                $p->archived_by = Auth::id();
            }
            if ($p->isDirty('statut') && $p->statut !== ProduitStatut::ARCHIVE) {
                $p->archived_at = null;
                $p->archived_by = null;
            }
        });

        static::deleting(function (Produit $p) {
            if (Auth::check()) {
                $p->deleted_by = Auth::id();
                $p->saveQuietly();
            }
        });
    }

    // ── Mutateurs ─────────────────────────────────────────────────────────────

    public function setNomAttribute(mixed $value): void
    {
        if ($value === null || trim($value) === '') {
            $this->attributes['nom'] = $value;

            return;
        }
        $v = trim(preg_replace('/\s+/u', ' ', $value));
        $this->attributes['nom'] = mb_strtoupper(mb_substr($v, 0, 1)).mb_strtolower(mb_substr($v, 1));
    }

    // ── Accesseurs ────────────────────────────────────────────────────────────

    public function getIsArchivedAttribute(): bool
    {
        return $this->statut === ProduitStatut::ARCHIVE;
    }

    public function getIsUsedAttribute(): bool
    {
        if ($this->is_used_loaded !== null) {
            return $this->is_used_loaded;
        }

        $varianteIds = $this->relationLoaded('variantes')
            ? $this->variantes->pluck('id')
            : $this->variantes()->pluck('id');

        if ($varianteIds->isEmpty()) {
            return false;
        }

        return DB::table('commande_vente_lignes')->whereIn('variante_id', $varianteIds)->exists()
            || DB::table('commande_achat_lignes')->whereIn('variante_id', $varianteIds)->whereNotNull('variante_id')->exists();
    }

    public function getInStockAttribute(): bool
    {
        if ($this->type === ProduitType::SERVICE) {
            return true;
        }

        return $this->qte_stock > 0;
    }

    public function getIsLowStockAttribute(): bool
    {
        if (! $this->type?->hasStock() || $this->qte_stock <= 0) {
            return false;
        }
        // Respecte l'eager loading ('variantes' préchargée en amont) pour éviter une requête
        // par produit — sinon variantePrincipale()->first() interroge la DB à chaque appel.
        $variante = $this->relationLoaded('variantes')
            ? $this->variantes->firstWhere('is_default', true)
            : $this->variantePrincipale()->first();
        $seuil = $variante?->seuil_alerte_stock ?? Parametre::getSeuilStockFaible((int) $this->organization_id);

        return $seuil > 0 && $this->qte_stock <= $seuil;
    }

    /**
     * Image principale du produit (galerie produit_medias), pour compat des affichages
     * qui n'attendent qu'une seule image (listes, PDV, tickets).
     */
    public function getImageUrlAttribute(): ?string
    {
        $primaire = $this->relationLoaded('medias')
            ? $this->medias->firstWhere('is_primary', true) ?? $this->medias->first()
            : $this->medias()->orderByDesc('is_primary')->orderBy('position')->first();

        return $primaire?->url;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(Categorie::class);
    }

    public function variantes(): HasMany
    {
        return $this->hasMany(ProduitVariante::class);
    }

    /**
     * Query builder vers la variante par défaut (is_default = true). Appeler ->first() —
     * volontairement pas de HasOne dédiée pour ne pas dupliquer variantes().
     */
    public function variantePrincipale(): HasMany
    {
        return $this->variantes()->where('is_default', true);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProduitOption::class)->orderBy('position');
    }

    public function medias(): HasMany
    {
        return $this->hasMany(ProduitMedia::class)->orderBy('position');
    }

    public function stocks(): HasManyThrough
    {
        return $this->hasManyThrough(
            VarianteStock::class,
            ProduitVariante::class,
            'produit_id',
            'produit_variante_id'
        );
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActifs($q)
    {
        return $q->where('statut', ProduitStatut::ACTIF);
    }

    public function scopeNonArchives($q)
    {
        return $q->where('statut', '!=', ProduitStatut::ARCHIVE);
    }

    // ── Méthodes métier ───────────────────────────────────────────────────────

    public function changerStatut(ProduitStatut $nouveau): bool
    {
        if (! $this->statut->canTransitionTo($nouveau)) {
            return false;
        }
        $this->statut = $nouveau;

        return $this->save();
    }

    /**
     * Recalcule produits.qte_stock (cache dénormalisé) depuis variante_stocks — même pattern
     * qu'avant (ProduitController resynchronisait Produit::qte_stock après chaque mouvement),
     * juste déplacé d'un cran puisque le stock réel vit désormais par variante.
     */
    public function resynchroniserQteStock(): void
    {
        $total = (int) $this->stocks()->sum('qte_stock');
        $this->updateQuietly(['qte_stock' => $total]);
    }
}
