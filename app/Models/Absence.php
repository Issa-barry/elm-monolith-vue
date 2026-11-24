<?php

namespace App\Models;

use App\Enums\StatutAbsence;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Absence d'un salarié (docs/rh-absences.md, ADR 0070) : motif, dates incluses, commentaire et
 * justificatif facultatifs. Jamais supprimée : l'annulation la conserve avec le statut `annulee`.
 * Toutes les écritures passent par App\Services\Rh\AbsenceService (contrôles, journal d'audit).
 *
 * Distincte des congés payés (non gérés en première version) : aucune incidence sur la paie ni sur
 * les contrats.
 */
class Absence extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'employe_id',
        'motif_absence_id',
        'date_debut',
        'date_fin',
        'commentaire',
        'justificatif_path',
        'justificatif_nom_original',
        'justificatif_mime_type',
        'justificatif_taille',
        'statut',
        'cree_par',
        'modifie_par',
        'annule_par',
        'annule_le',
        'motif_annulation',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutAbsence::class,
            'date_debut' => 'date',
            'date_fin' => 'date',
            'annule_le' => 'datetime',
            'justificatif_taille' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Salarié, même sorti ou supprimé depuis : l'historique reste lisible. */
    public function employe(): BelongsTo
    {
        return $this->belongsTo(Employe::class)->withTrashed();
    }

    public function motif(): BelongsTo
    {
        return $this->belongsTo(MotifAbsence::class, 'motif_absence_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function modificateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modifie_par');
    }

    public function annulateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annule_par');
    }

    /** @param  Builder<Absence>  $query */
    public function scopeEnregistrees(Builder $query): Builder
    {
        return $query->where('statut', StatutAbsence::ENREGISTREE->value);
    }

    /**
     * Absences dont la période touche [$debut, $fin] (bornes incluses). Comparaison semi-ouverte
     * (début < lendemain de $fin), comme Periode::appliquer() : juste que la colonne DATE soit lue
     * « Y-m-d » (MySQL) ou « Y-m-d 00:00:00 » (SQLite des tests).
     *
     * @param  Builder<Absence>  $query
     */
    public function scopeChevauchant(Builder $query, CarbonInterface $debut, CarbonInterface $fin): Builder
    {
        return $query->where('date_debut', '<', $fin->copy()->startOfDay()->addDay()->toDateString())
            ->where('date_fin', '>=', $debut->toDateString());
    }

    /** Durée en jours calendaires, dates de début et de fin incluses (10 → 12 novembre = 3 jours). */
    public static function dureeEnJours(CarbonInterface $debut, CarbonInterface $fin): int
    {
        return (int) $debut->copy()->startOfDay()->diffInDays($fin->copy()->startOfDay()) + 1;
    }

    public function duree(): int
    {
        return self::dureeEnJours($this->date_debut, $this->date_fin);
    }

    public function estAnnulee(): bool
    {
        return $this->statut === StatutAbsence::ANNULEE;
    }

    /**
     * État affiché : `annulee`, sinon déduit des dates par rapport à aujourd'hui (`a_venir`,
     * `en_cours`, `terminee`) — valeurs de StatusDot.vue.
     */
    public function etat(?CarbonInterface $aujourdhui = null): string
    {
        if ($this->estAnnulee()) {
            return 'annulee';
        }

        $jour = ($aujourdhui ?? now())->toDateString();

        return match (true) {
            $this->date_debut->toDateString() > $jour => 'a_venir',
            $this->date_fin->toDateString() < $jour => 'terminee',
            default => 'en_cours',
        };
    }

    public static function libelleEtat(string $etat): string
    {
        return match ($etat) {
            'annulee' => 'Annulée',
            'a_venir' => 'À venir',
            'terminee' => 'Terminée',
            default => 'En cours',
        };
    }
}
