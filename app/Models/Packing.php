<?php

namespace App\Models;

use App\Enums\PackingShift;
use App\Enums\PackingStatut;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class Packing extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    private const TEMP_PREFIX = 'TMP-PACK-';

    protected $fillable = [
        'organization_id',
        'prestataire_id',
        'reference',
        'date',
        'nb_rouleaux',
        'prix_par_rouleau',
        'montant',
        'shift',
        'statut',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $hidden = ['versements_sum_montant'];

    protected $appends = ['statut_label', 'prestataire_nom', 'montant_verse', 'montant_restant'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'nb_rouleaux' => 'integer',
            'prix_par_rouleau' => 'integer',
            'montant' => 'integer',
            'shift' => PackingShift::class,
            'statut' => PackingStatut::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Packing $p) {
            if (empty($p->reference)) {
                $p->reference = self::TEMP_PREFIX.Str::uuid();
            }
            if (empty($p->shift)) {
                $p->shift = PackingShift::JOUR;
            }
            if (empty($p->statut)) {
                $p->statut = PackingStatut::IMPAYEE;
            }
            $p->montant = (int) $p->nb_rouleaux * (int) $p->prix_par_rouleau;
            if (Auth::check()) {
                $p->created_by = Auth::id();
                $p->updated_by = Auth::id();
            }
        });

        static::created(function (Packing $p) {
            if (! str_starts_with((string) $p->reference, self::TEMP_PREFIX)) {
                return;
            }
            $ref = 'PACK-'.($p->created_at ?? now())->format('Ymd').'-'.str_pad((string) $p->id, 4, '0', STR_PAD_LEFT);
            $p->newQueryWithoutScopes()->whereKey($p->id)->update(['reference' => $ref]);
            $p->reference = $ref;
            $p->syncOriginalAttribute('reference');
        });

        static::updating(function (Packing $p) {
            $p->montant = (int) $p->nb_rouleaux * (int) $p->prix_par_rouleau;
            if (Auth::check()) {
                $p->updated_by = Auth::id();
            }
        });
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function prestataire(): BelongsTo
    {
        return $this->belongsTo(Prestataire::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function versements(): HasMany
    {
        return $this->hasMany(Versement::class);
    }

    // ── Accesseurs ────────────────────────────────────────────────────────────

    public function getStatutLabelAttribute(): string
    {
        return $this->statut instanceof PackingStatut ? $this->statut->label() : '';
    }

    public function getPrestataireNomAttribute(): ?string
    {
        return $this->prestataire?->nom_complet;
    }

    public function getMontantVerseAttribute(): int
    {
        if (array_key_exists('versements_sum_montant', $this->attributes)) {
            return (int) $this->attributes['versements_sum_montant'];
        }
        if ($this->relationLoaded('versements')) {
            return (int) $this->versements->sum('montant');
        }

        return (int) $this->versements()->sum('montant');
    }

    public function getMontantRestantAttribute(): int
    {
        return max(0, $this->montant - $this->montant_verse);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeNonAnnules(Builder $q): Builder
    {
        return $q->where('statut', '!=', PackingStatut::ANNULEE->value);
    }

    public function scopeNonPayes(Builder $q): Builder
    {
        return $q->whereIn('statut', [PackingStatut::IMPAYEE->value, PackingStatut::PARTIELLE->value]);
    }

    // ── Méthodes métier ───────────────────────────────────────────────────────

    public function mettreAJourStatut(): bool
    {
        if ($this->statut === PackingStatut::ANNULEE) {
            return false;
        }
        $verse = (int) $this->versements()->sum('montant');
        if ($verse <= 0) {
            $this->statut = PackingStatut::IMPAYEE;
        } elseif ($verse >= $this->montant) {
            $this->statut = PackingStatut::PAYEE;
        } else {
            $this->statut = PackingStatut::PARTIELLE;
        }

        return $this->saveQuietly();
    }

    public function peutEtreModifie(): bool
    {
        return $this->statut === PackingStatut::IMPAYEE;
    }

    public function peutEtreAnnule(): bool
    {
        return $this->statut !== PackingStatut::ANNULEE;
    }
}
