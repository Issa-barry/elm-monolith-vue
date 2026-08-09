<?php

namespace App\Models;

use App\Models\Concerns\NormalizesLabel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * Bibliothèque d'options réutilisables ("Couleur", "Pointure"...) — un modèle/catalogue
 * de suggestions, pas les options réellement portées par un produit (cf. ProduitOption,
 * qui reste une copie indépendante créée à partir de ce catalogue).
 */
class OptionCatalogue extends Model
{
    use HasFactory, HasUlids, NormalizesLabel, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'nom',
        'is_system',
        'position',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'position' => 'integer',
        'is_system' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (OptionCatalogue $o) {
            if (Auth::check()) {
                $o->created_by = Auth::id();
                $o->updated_by = Auth::id();
            }
            if (empty($o->organization_id)) {
                $o->organization_id = Auth::user()?->organization_id;
            }
        });

        static::updating(function (OptionCatalogue $o) {
            if (Auth::check()) {
                $o->updated_by = Auth::id();
            }
        });
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

    public function valeurs(): HasMany
    {
        return $this->hasMany(OptionCatalogueValeur::class)->orderBy('position');
    }
}
