<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProduitVariante extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    public const COMBO_HASH_DEFAUT = 'default';

    protected $fillable = [
        'organization_id',
        'produit_id',
        'sku',
        'code_barres',
        'code_fournisseur',
        'prix_usine',
        'prix_vente',
        'prix_achat',
        'cout',
        'seuil_alerte_stock',
        'is_default',
        'is_active',
        'combo_hash',
        'position',
    ];

    protected $casts = [
        'prix_usine' => 'integer',
        'prix_vente' => 'integer',
        'prix_achat' => 'integer',
        'cout' => 'integer',
        'seuil_alerte_stock' => 'integer',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    // ── Boot ──────────────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (ProduitVariante $v) {
            // Génération auto du SKU (même algorithme que l'ancien Produit::booted(),
            // déplacé ici puisque le SKU est désormais porté par la variante).
            if (empty($v->sku)) {
                $orgId = $v->organization_id;
                do {
                    $candidat = date('Ymd').str_pad((string) mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
                } while (static::withTrashed()->where('organization_id', $orgId)->where('sku', $candidat)->exists());
                $v->sku = $candidat;
            }
            if (empty($v->combo_hash)) {
                $v->combo_hash = self::COMBO_HASH_DEFAUT;
            }
        });
    }

    // ── Mutateurs ─────────────────────────────────────────────────────────────

    public function setSkuAttribute(mixed $value): void
    {
        $this->attributes['sku'] = ($value !== null && $value !== '')
            ? mb_strtoupper(trim(preg_replace('/\s+/', '', $value)))
            : null;
    }

    public function setCodeFournisseurAttribute(mixed $value): void
    {
        $this->attributes['code_fournisseur'] = ($value !== null && $value !== '')
            ? mb_strtoupper(trim(preg_replace('/\s+/', '', $value)))
            : null;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function valeurs(): BelongsToMany
    {
        return $this->belongsToMany(
            ProduitOptionValeur::class,
            'produit_variante_valeurs',
            'produit_variante_id',
            'produit_option_valeur_id'
        )->withPivot('produit_option_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(VarianteStock::class);
    }

    public function medias(): HasMany
    {
        return $this->hasMany(ProduitMedia::class);
    }

    // ── Accesseurs ────────────────────────────────────────────────────────────

    /**
     * Libellé lisible de la combinaison (ex: "Noir / M"). Vide pour la variante par défaut.
     */
    public function getLibelleAttribute(): string
    {
        if ($this->combo_hash === self::COMBO_HASH_DEFAUT) {
            return '';
        }

        // position est scopée PAR option (recommence à 0 pour chaque option) : trier
        // uniquement sur cette colonne mélange les options entre elles dès que deux valeurs
        // de deux options différentes partagent la même position (ex: "Noir" et "S" en 0).
        // Il faut d'abord ordonner par la position de l'OPTION, puis par celle de la valeur.
        $valeurs = $this->valeurs()->with('option')->get()->all();
        usort($valeurs, fn ($a, $b) => [$a->option->position, $a->position] <=> [$b->option->position, $b->position]);

        return collect($valeurs)->pluck('valeur')->implode(' / ');
    }

    /**
     * Calcule le combo_hash à partir d'une liste d'IDs de valeurs d'option (triée pour être
     * indépendante de l'ordre de saisie) — utilisé par VarianteService avant insertion pour
     * détecter les doublons de combinaison et laisser la contrainte unique(produit_id, combo_hash) trancher.
     *
     * @param  array<string>  $valeurIds
     */
    public static function calculerComboHash(array $valeurIds): string
    {
        if (empty($valeurIds)) {
            return self::COMBO_HASH_DEFAUT;
        }
        sort($valeurIds);

        return hash('sha256', implode('|', $valeurIds));
    }
}
