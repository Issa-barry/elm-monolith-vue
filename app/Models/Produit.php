<?php

namespace App\Models;

use App\Enums\ProduitStatut;
use App\Enums\ProduitType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use App\Models\Parametre;

class Produit extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'nom',
        'code_interne',
        'code_fournisseur',
        'prix_usine',
        'prix_vente',
        'prix_achat',
        'cout',
        'qte_stock',
        'seuil_alerte_stock',
        'type',
        'statut',
        'description',
        'image_url',
        'is_critique',
        'archived_at',
        'created_by',
        'updated_by',
        'deleted_by',
        'archived_by',
    ];

    protected $casts = [
        'prix_usine'        => 'integer',
        'prix_vente'        => 'integer',
        'prix_achat'        => 'integer',
        'cout'              => 'integer',
        'qte_stock'         => 'integer',
        'seuil_alerte_stock'=> 'integer',
        'is_critique'       => 'boolean',
        'archived_at'       => 'datetime',
        'type'              => ProduitType::class,
        'statut'            => ProduitStatut::class,
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
            // Génération automatique du code-barres Code 128 (13 chiffres unique)
            if (empty($p->code_interne)) {
                do {
                    $p->code_interne = date('Ymd') . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
                } while (static::withTrashed()->where('code_interne', $p->code_interne)->exists());
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
        $this->attributes['nom'] = mb_strtoupper(mb_substr($v, 0, 1)) . mb_strtolower(mb_substr($v, 1));
    }

    public function setCodeInterneAttribute(mixed $value): void
    {
        $this->attributes['code_interne'] = ($value !== null && $value !== '')
            ? mb_strtoupper(trim(preg_replace('/\s+/', '', $value)))
            : null;
    }

    public function setCodeFournisseurAttribute(mixed $value): void
    {
        $this->attributes['code_fournisseur'] = ($value !== null && $value !== '')
            ? mb_strtoupper(trim(preg_replace('/\s+/', '', $value)))
            : null;
    }

    // ── Accesseurs ────────────────────────────────────────────────────────────

    public function getIsArchivedAttribute(): bool
    {
        return $this->statut === ProduitStatut::ARCHIVE;
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
        $seuil = $this->seuil_alerte_stock ?? Parametre::getSeuilStockFaible((int) $this->organization_id);
        return $seuil > 0 && $this->qte_stock <= $seuil;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

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
}
