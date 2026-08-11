<?php

namespace App\Models;

use App\Enums\ProduitTypeStatut;
use App\Models\Concerns\NormalizesLabel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Type de produit — CRUD par organisation, remplace l'ancien enum figé App\Enums\ProduitType.
 * Porte les capacités structurelles qui pilotaient auparavant le code en dur (gère_stock,
 * vendable/achetable, prix requis, champ de référence pour le garde-fou de marge). Une
 * organisation peut créer ses propres types ; les 4 types historiques (Matériel, Service,
 * Fabricable, Achat/Vente) sont provisionnés automatiquement à la création de l'organisation
 * (cf. ProduitTypeDefaultSeeder) pour ne jamais laisser une organisation sans aucun type
 * disponible — un produit ne peut pas exister sans type.
 */
class ProduitType extends Model
{
    use HasFactory, HasUlids, NormalizesLabel, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'nom',
        'code',
        'gere_stock',
        'vendable',
        'achetable',
        'prix_achat_requis',
        'prix_usine_requis',
        'prix_vente_requis',
        'champ_prix_reference',
        'statut',
        'position',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'gere_stock' => 'boolean',
        'vendable' => 'boolean',
        'achetable' => 'boolean',
        'prix_achat_requis' => 'boolean',
        'prix_usine_requis' => 'boolean',
        'prix_vente_requis' => 'boolean',
        'statut' => ProduitTypeStatut::class,
        'position' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProduitType $t) {
            if (Auth::check()) {
                $t->created_by = Auth::id();
                $t->updated_by = Auth::id();
            }
            if (empty($t->organization_id)) {
                $t->organization_id = Auth::user()?->organization_id;
            }
            if (empty($t->statut)) {
                $t->statut = ProduitTypeStatut::ACTIF;
            }
            if (empty($t->code)) {
                $t->code = self::genererCodeUnique($t->organization_id, $t->nom);
            }
        });

        static::updating(function (ProduitType $t) {
            if (Auth::check()) {
                $t->updated_by = Auth::id();
            }
        });
    }

    private static function genererCodeUnique(string $organizationId, string $nom): string
    {
        $base = Str::slug($nom, '_') ?: 'type';
        $code = $base;
        $i = 2;

        while (static::withTrashed()->where('organization_id', $organizationId)->where('code', $code)->exists()) {
            $code = "{$base}_{$i}";
            $i++;
        }

        return $code;
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

    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class);
    }

    // ── Accesseurs ────────────────────────────────────────────────────────────

    public function getIsUsedAttribute(): bool
    {
        return $this->produits()->exists();
    }

    public function isVendable(): bool
    {
        return $this->vendable;
    }

    public function isAchetable(): bool
    {
        return $this->achetable;
    }

    /**
     * Liste des champs de prix obligatoires pour ce type, dérivée des booléens structurels —
     * remplace l'ancien App\Enums\ProduitType::requiredPrices() codé en dur. Conserve la même
     * forme de retour pour que ProduitService (validation) n'ait pas à changer de logique.
     *
     * @return string[]
     */
    public function requiredPrices(): array
    {
        $champs = [];
        if ($this->prix_achat_requis) {
            $champs[] = 'prix_achat';
        }
        if ($this->prix_usine_requis) {
            $champs[] = 'prix_usine';
        }
        if ($this->prix_vente_requis) {
            $champs[] = 'prix_vente';
        }

        return $champs;
    }

    public function champPrixReference(): ?string
    {
        return $this->champ_prix_reference;
    }
}
