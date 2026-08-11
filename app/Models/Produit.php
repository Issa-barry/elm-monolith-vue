<?php

namespace App\Models;

use App\Enums\ProduitStatut;
use App\Models\Concerns\NormalizesLabel;
use App\Services\StockStatutService;
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
    use HasFactory, HasUlids, NormalizesLabel, SoftDeletes;

    public ?bool $is_used_loaded = null;

    protected $fillable = [
        'organization_id',
        'categorie_id',
        'fournisseur_id',
        'produit_type_id',
        'nom',
        'statut',
        'description',
        'qte_stock',
        'alerte_stock_active',
        'seuil_alerte_stock',
        'archived_at',
        'created_by',
        'updated_by',
        'deleted_by',
        'archived_by',
    ];

    protected $casts = [
        'qte_stock' => 'integer',
        'alerte_stock_active' => 'boolean',
        'seuil_alerte_stock' => 'integer',
        'archived_at' => 'datetime',
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
        $this->attributes['nom'] = static::normalizeLabel($value);
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
        if (! $this->produitType?->gere_stock) {
            return true;
        }

        return $this->qte_stock > 0;
    }

    /**
     * Vrai si au moins un couple variante × site de ce produit est actuellement en stock
     * faible ou en rupture — délègue à StockStatutService (source unique de la règle, cf.
     * son docblock) plutôt que de réimplémenter la comparaison qte/seuil ici. Nécessite
     * $this chargé avec ['produitType', 'variantes.stocks'] pour éviter un N+1 ; sinon
     * déclenche un chargement paresseux (acceptable pour un accès isolé, ex: Show d'un
     * seul produit — à éviter en boucle sur une liste, cf. ProduitController@index qui
     * calcule ceci autrement pour cette raison).
     */
    public function getIsLowStockAttribute(): bool
    {
        return app(StockStatutService::class)
            ->detailParVarianteEtSite($this)
            ->contains(fn (array $d) => $d['statut'] === \App\Enums\StockStatut::STOCK_FAIBLE->value);
    }

    public function getIsOutOfStockAttribute(): bool
    {
        if (! $this->produitType?->gere_stock) {
            return false;
        }

        return app(StockStatutService::class)
            ->detailParVarianteEtSite($this)
            ->contains(fn (array $d) => $d['statut'] === \App\Enums\StockStatut::RUPTURE->value);
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

    public function produitType(): BelongsTo
    {
        return $this->belongsTo(ProduitType::class);
    }

    /**
     * Fournisseur principal (0 ou 1) — entité dédiée (table fournisseurs), distincte de
     * Prestataire (main-d'œuvre externe : machiniste, mécanicien, consultant).
     */
    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class, 'fournisseur_id');
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
