<?php

namespace App\Models;

use App\Enums\StatutCommission;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Le montant total gagné par une cible bénéficiaire précise sur une opération
 * précise — jamais un simple total interne au calcul. Une seule enveloppe par
 * (cible, opération), même si plusieurs règles/catégories y contribuent
 * (décision AMOA #6) : le détail par règle vit dans les lignes, pas ici.
 */
class CommissionEnveloppe extends Model
{
    use HasUlids;

    protected $table = 'commission_enveloppes';

    protected $appends = ['montant_restant'];

    protected $fillable = [
        'organization_id',
        'source_type',
        'source_id',
        'processus_id',
        'cible_type',
        'cible_id',
        'montant_total',
        'montant_verse',
        'earned_at',
        'periode_code',
        'statut',
    ];

    protected function casts(): array
    {
        return [
            'montant_total' => 'decimal:2',
            'montant_verse' => 'decimal:2',
            'earned_at' => 'date',
            'statut' => StatutCommission::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function processus(): BelongsTo
    {
        return $this->belongsTo(CommissionProcessus::class, 'processus_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(CommissionEnveloppeLigne::class, 'enveloppe_id');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(CommissionEnveloppePart::class, 'enveloppe_id');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getMontantRestantAttribute(): float
    {
        if ($this->relationLoaded('parts')) {
            return (float) $this->parts->sum('montant_restant');
        }

        return (float) $this->parts()->get()->sum('montant_restant');
    }

    // ── Métier ────────────────────────────────────────────────────────────────

    public function isPaye(): bool
    {
        return $this->statut === StatutCommission::PAYE;
    }

    /**
     * Recalcule montant_verse + statut global depuis les parts — miroir exact
     * de CommissionVente::recalculStatutGlobal() (même comparaison au
     * montant_net théorique, pas au montant_a_payer ajusté : reproduit à
     * l'identique la même particularité assumée côté legacy).
     */
    public function recalculStatutGlobal(): bool
    {
        if (in_array($this->statut, [StatutCommission::CREEE, StatutCommission::ANNULEE], true)) {
            return false;
        }

        $parts = $this->parts()->get();

        $totalVerse = $parts->sum('montant_verse');
        $totalNet = $parts->sum('montant_net');

        $this->montant_verse = $totalVerse;

        $this->statut = match (true) {
            $totalNet > 0 && (float) $totalVerse >= (float) $totalNet => StatutCommission::PAYE,
            (float) $totalVerse > 0 => StatutCommission::PARTIEL,
            default => StatutCommission::IMPAYE,
        };

        return $this->saveQuietly();
    }
}
