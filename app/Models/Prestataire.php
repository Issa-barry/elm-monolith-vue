<?php

namespace App\Models;

use App\Enums\PrestataireType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;

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

    /**
     * Libellé des qualifications (lot 6b) : libellés du référentiel, dans leur ordre ; repli sur
     * l'ancien `type` tant qu'aucune qualification n'est enregistrée.
     */
    public function getTypeLabelAttribute(): string
    {
        // Sans relation chargée : requête sans l'attacher, pour ne pas ajouter `sous_categories` à
        // toutes les sérialisations du modèle (type_label est dans $appends).
        $qualifications = ($this->relationLoaded('sousCategories') ? $this->sousCategories : ($this->exists ? $this->sousCategories()->get() : collect()))
            ->sortBy([['ordre', 'asc'], ['libelle', 'asc']]);
        if ($qualifications->isNotEmpty()) {
            return $qualifications->pluck('libelle')->implode(', ');
        }

        return $this->type instanceof PrestataireType ? $this->type->label() : '';
    }

    // ── Qualification Consultant (critère unique, ADR 0060 lot 6b) ───────────

    /**
     * Seule la qualification système Consultant ouvre l'éligibilité aux commissions consultant ;
     * une qualification libre n'est jamais assimilée à Consultant. Repli transitoire (décision Q2) :
     * un prestataire sans aucune qualification enregistrée est lu sur l'ancien `type`, le temps de
     * la reprise des données au déploiement — jamais pour masquer des données manquantes.
     */
    public function qualifieConsultant(): bool
    {
        if ($this->relationLoaded('sousCategories')) {
            return $this->sousCategories->isEmpty()
                ? $this->type === PrestataireType::CONSULTANT
                : $this->sousCategories->contains(fn (ContactSousCategorie $s) => $s->code === PrestataireType::CONSULTANT->value);
        }

        return static::whereKey($this->getKey())->withTrashed()->qualifiesConsultant()->exists();
    }

    public function scopeQualifiesConsultant(Builder $q): Builder
    {
        return $q->where(fn (Builder $sous) => static::conditionQualifieConsultant($sous->getQuery(), $q->getModel()->getTable()));
    }

    /**
     * Même condition pour une requête Eloquent et une règle `Rule::exists('prestataires')`.
     */
    public static function conditionQualifieConsultant(QueryBuilder $q, string $table = 'prestataires'): QueryBuilder
    {
        $qualifications = fn (QueryBuilder $s) => $s->from('prestataire_sous_categories')
            ->whereColumn('prestataire_sous_categories.prestataire_id', "{$table}.id");

        return $q->where(fn (QueryBuilder $w) => $w
            ->whereExists(fn (QueryBuilder $s) => $qualifications($s)
                ->join('contact_sous_categories', 'contact_sous_categories.id', '=', 'prestataire_sous_categories.contact_sous_categorie_id')
                ->where('contact_sous_categories.code', PrestataireType::CONSULTANT->value))
            ->orWhere(fn (QueryBuilder $repli) => $repli
                ->whereNotExists(fn (QueryBuilder $s) => $qualifications($s))
                ->where("{$table}.type", PrestataireType::CONSULTANT->value)));
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

    /**
     * Compte du consultant personne physique (ADR 0050) : non mass-assignable, seul
     * RattachementCompteService le pose. Une entreprise passe par ses interlocuteurs.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Qualifications du référentiel Contacts (ADR 0060, lot 6b) : plusieurs, égales, sans
     * principale. Source de vérité ; `type` en est une copie de compatibilité
     * (App\Services\Contacts\QualificationsPrestataire).
     */
    public function sousCategories(): BelongsToMany
    {
        return $this->belongsToMany(ContactSousCategorie::class, 'prestataire_sous_categories', 'prestataire_id', 'contact_sous_categorie_id')
            ->using(FonctionSousCategorie::class)
            ->withPivot(['id', 'created_by'])
            ->withTimestamps();
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
