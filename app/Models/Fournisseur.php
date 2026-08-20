<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fournisseur extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'reference',
        'personne_id',
        'entreprise_tierce_id',
        'notes',
        'is_active',
    ];

    // Identité (nom/prenom/raison_sociale/email/phone/adresse...) portée par Personne (cas
    // physique) ou EntrepriseTierce (cas moral) — jamais de colonne équivalente ici, cf.
    // accesseurs ci-dessous. Exactement l'un des deux est renseigné.
    protected $appends = [
        'nom_complet', 'nom', 'prenom', 'raison_sociale', 'email',
        'phone', 'code_phone_pays', 'code_pays', 'pays', 'ville', 'adresse',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Fournisseur $f) {
            if (empty($f->reference)) {
                $f->reference = self::generateReference();
            }
        });
    }

    // Préfixe 'F' (vs 'P' pour Prestataire) — permet de distinguer les deux séries de
    // références au premier coup d'œil malgré le même format lettres/chiffres.
    public static function generateReference(): string
    {
        do {
            $letters = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 2));
            $digits = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
            $ref = 'F'.$letters.$digits;
        } while (self::withTrashed()->where('reference', $ref)->exists());

        return $ref;
    }

    // ── Accesseurs — proxy en lecture seule vers Personne ou EntrepriseTierce ───────────────

    public function getNomCompletAttribute(): ?string
    {
        return $this->entrepriseTierce?->raison_sociale ?? $this->personne?->nom_complet;
    }

    public function getNomAttribute(): ?string
    {
        return $this->personne?->nom;
    }

    public function getPrenomAttribute(): ?string
    {
        return $this->personne?->prenom;
    }

    public function getRaisonSocialeAttribute(): ?string
    {
        return $this->entrepriseTierce?->raison_sociale;
    }

    public function getEmailAttribute(): ?string
    {
        return $this->personne?->email ?? $this->entrepriseTierce?->email;
    }

    public function getPhoneAttribute(): ?string
    {
        return $this->personne?->telephone ?? $this->entrepriseTierce?->telephone;
    }

    public function getCodePhonePaysAttribute(): ?string
    {
        return $this->personne?->code_phone_pays ?? $this->entrepriseTierce?->code_phone_pays;
    }

    public function getCodePaysAttribute(): ?string
    {
        return $this->personne?->code_pays ?? $this->entrepriseTierce?->code_pays;
    }

    public function getPaysAttribute(): ?string
    {
        return $this->personne?->pays ?? $this->entrepriseTierce?->pays;
    }

    public function getVilleAttribute(): ?string
    {
        return $this->personne?->ville ?? $this->entrepriseTierce?->ville;
    }

    public function getAdresseAttribute(): ?string
    {
        return $this->personne?->adresse ?? $this->entrepriseTierce?->adresse;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function personne(): BelongsTo
    {
        return $this->belongsTo(Personne::class);
    }

    public function entrepriseTierce(): BelongsTo
    {
        return $this->belongsTo(EntrepriseTierce::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActifs(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
