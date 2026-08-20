<?php

namespace App\Models;

use App\Enums\PrestataireType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Prestataire extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'reference',
        'personne_id',
        'entreprise_tierce_id',
        'type',
        'notes',
        'is_active',
    ];

    // Identité (nom/prenom/raison_sociale/email/phone/adresse...) portée par Personne (cas
    // physique) ou EntrepriseTierce (cas moral) — jamais de colonne équivalente ici, cf.
    // accesseurs ci-dessous. Exactement l'un des deux est renseigné. `type` reste ici : c'est un
    // attribut du rôle (quel genre de prestation), pas de l'identité.
    protected $appends = [
        'nom_complet', 'nom', 'prenom', 'raison_sociale', 'email',
        'phone', 'code_phone_pays', 'code_pays', 'pays', 'ville', 'adresse',
        'type_label',
    ];

    protected function casts(): array
    {
        return [
            'type' => PrestataireType::class,
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Prestataire $p) {
            if (empty($p->reference)) {
                $p->reference = self::generateReference();
            }
        });
    }

    public static function generateReference(): string
    {
        do {
            $letters = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 2));
            $digits = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
            $ref = 'P'.$letters.$digits;
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

    public function getTypeLabelAttribute(): string
    {
        return $this->type instanceof PrestataireType ? $this->type->label() : '';
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

    public function scopeParType(Builder $q, PrestataireType|string $type): Builder
    {
        $value = $type instanceof PrestataireType ? $type->value : $type;

        return $q->where('type', $value);
    }
}
