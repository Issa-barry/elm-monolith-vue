<?php

namespace App\Models;

use App\Enums\StatutAffectationRemuneration;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Prime, indemnité, allocation ou avantage en nature attribué à un salarié sur une période
 * (docs/primes-avantages.md, ADR 0039). Toutes les écritures passent par
 * App\Services\Rh\AffectationRemunerationService (règles de dates, chevauchements, validation,
 * mois figés, journal).
 */
class AffectationRemuneration extends Model
{
    use HasUlids;

    protected $table = 'affectations_remuneration';

    protected $fillable = [
        'organization_id',
        'employe_id',
        'type_remuneration_id',
        'date_debut',
        'date_fin',
        'montant',
        'taux',
        'commentaire',
        'statut',
        'remplace_affectation_id',
        'date_fin_demandee',
        'motif_fin',
        'fin_demandee_par',
        'fin_demandee_le',
        'motif_annulation',
        'cree_par',
        'modifie_par',
        'valide_par',
        'valide_le',
        'annule_par',
        'annule_le',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutAffectationRemuneration::class,
            'date_debut' => 'date',
            'date_fin' => 'date',
            'date_fin_demandee' => 'date',
            'montant' => 'decimal:2',
            'taux' => 'decimal:2',
            'fin_demandee_le' => 'datetime',
            'valide_le' => 'datetime',
            'annule_le' => 'datetime',
        ];
    }

    public function employe(): BelongsTo
    {
        return $this->belongsTo(Employe::class)->withTrashed();
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(TypeRemuneration::class, 'type_remuneration_id');
    }

    /** Affectation à laquelle celle-ci succède (changement de montant). */
    public function remplace(): BelongsTo
    {
        return $this->belongsTo(self::class, 'remplace_affectation_id');
    }

    /** Changement de montant qui succède à celle-ci (à valider ou validé). */
    public function successeur(): HasOne
    {
        return $this->hasOne(self::class, 'remplace_affectation_id')
            ->whereIn('statut', StatutAffectationRemuneration::occupantLaPeriode());
    }

    public function saisies(): HasMany
    {
        return $this->hasMany(SaisieRemuneration::class, 'affectation_id')->orderByDesc('annee')->orderByDesc('mois');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function valideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }

    public function demandeurFin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fin_demandee_par');
    }

    /** Affectations validées en vigueur au moins un jour entre $du et $au. */
    public function scopeEnVigueurEntre(Builder $query, CarbonInterface $du, CarbonInterface $au): Builder
    {
        return $query->whereDate('date_debut', '<=', $au->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $du->toDateString()));
    }

    /**
     * Primes retenues pour le salarié : validées, en cours ou à venir (jamais une proposition à
     * valider, annulée ou terminée). Reprises dans le contrat PDF et comptées dans la liste des
     * contrats (docs/primes-avantages.md, AFF-004 ter).
     */
    public function scopeValideesEnCoursOuAVenir(Builder $query): Builder
    {
        return $query->where('statut', StatutAffectationRemuneration::VALIDEE->value)
            ->where(fn (Builder $q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', today()->toDateString()));
    }

    /** Une proposition (affectation, changement de montant, fin d'effet) attend-elle une validation ? */
    public function attendValidation(): bool
    {
        return $this->statut === StatutAffectationRemuneration::A_VALIDER
            || ($this->statut === StatutAffectationRemuneration::VALIDEE && $this->date_fin_demandee !== null);
    }

    /** @return array{value: string, label: string} */
    public function etat(?CarbonInterface $jour = null): array
    {
        return $this->statut->etat($this->date_debut, $this->date_fin, ($jour ?? now())->copy()->startOfDay());
    }

    /** L'affectation figure-t-elle dans une transmission validée (figée) ? */
    public function estTransmise(): bool
    {
        return TransmissionPaieLigne::where('affectation_id', $this->id)
            ->whereHas('transmission', fn (Builder $q) => $q->whereIn('statut', TransmissionPaie::STATUTS_FIGES))
            ->exists();
    }
}
