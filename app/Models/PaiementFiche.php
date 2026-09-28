<?php

namespace App\Models;

use App\Enums\StatutFichePaiement;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaiementFiche extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'periode_id',
        'reference',
        'beneficiaire_type',
        'beneficiaire_id',
        'beneficiaire_nom',
        'rang',
        'fiche_origine_id',
        'site_id',
        'montant_brut',
        'total_deductions',
        'montant_net',
        'montant_paye',
        'report_a_deduire',
        'statut',
        'validated_at',
        'validated_by',
        'mode_paiement',
        'date_paiement',
        'paid_by',
        'signature_path',
        'commentaires',
    ];

    protected $appends = ['montant_restant', 'statut_label', 'beneficiaire_label'];

    protected function casts(): array
    {
        return [
            'statut' => StatutFichePaiement::class,
            'date_paiement' => 'date',
            'montant_brut' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'montant_net' => 'decimal:2',
            'montant_paye' => 'decimal:2',
            'report_a_deduire' => 'decimal:2',
            'rang' => 'integer',
            'validated_at' => 'datetime',
        ];
    }

    /**
     * Colonnes qui définissent ce qu'une fiche doit : figées dès le premier paiement. Le
     * montant payé et le statut de paiement restent mis à jour par les paiements eux-mêmes.
     */
    private const COLONNES_FIGEES = [
        'periode_id', 'beneficiaire_type', 'beneficiaire_id', 'rang',
        'montant_brut', 'total_deductions', 'montant_net', 'report_a_deduire',
    ];

    /**
     * Protection modèle (ADR 0010) : une fiche ayant reçu un paiement n'est jamais supprimée
     * (ni douce ni définitive) ni modifiée dans ce qu'elle doit. Doublée en base par la clé
     * étrangère restrictive de paiement_fiche_paiements et, dans le recalcul, par l'exclusion
     * des fiches figées.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $fiche) {
            if ($fiche->estFigee()) {
                throw new \LogicException("La fiche {$fiche->reference} a déjà reçu un paiement : elle ne peut pas être supprimée.");
            }
        });

        static::updating(function (self $fiche) {
            $etaitFigee = (float) $fiche->getOriginal('montant_paye') > 0.009
                || (float) $fiche->getOriginal('report_a_deduire') > 0.009
                || $fiche->historiquePaiements()->exists();

            if ($etaitFigee && $fiche->isDirty(self::COLONNES_FIGEES)) {
                throw new \LogicException("La fiche {$fiche->reference} a déjà reçu un paiement : ses montants ne peuvent plus être modifiés.");
            }
        });
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function periode(): BelongsTo
    {
        return $this->belongsTo(PaiementPeriode::class, 'periode_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(PaiementFicheLigne::class, 'fiche_id')->orderBy('ordre');
    }

    public function historiquePaiements(): HasMany
    {
        return $this->hasMany(PaiementFichePaiement::class, 'fiche_id')->latest('date_paiement');
    }

    public function ficheOrigine(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fiche_origine_id');
    }

    public function payeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getMontantRestantAttribute(): float
    {
        return max(0.0, (float) $this->montant_net - (float) $this->montant_paye);
    }

    public function getStatutLabelAttribute(): string
    {
        return $this->statut instanceof StatutFichePaiement ? $this->statut->label() : '';
    }

    public function getBeneficiaireLabelAttribute(): string
    {
        return $this->beneficiaire_nom ?? '';
    }

    // ── Métier ────────────────────────────────────────────────────────────────

    /**
     * Fiche définitivement figée (ADR 0010) : elle a reçu au moins un paiement, ou elle porte
     * une déduction reportée sur la fiche suivante. Jamais supprimée ni recalculée : toute
     * nouvelle ligne du bénéficiaire va sur une fiche complémentaire.
     */
    public function estFigee(): bool
    {
        return (float) $this->montant_paye > 0.009
            || (float) $this->report_a_deduire > 0.009
            || $this->historiquePaiements()->exists();
    }

    public function recalculTotaux(): void
    {
        if ($this->estFigee()) {
            throw new \LogicException("La fiche {$this->reference} a déjà reçu un paiement : ses totaux ne peuvent plus être recalculés.");
        }

        $lignes = $this->lignes()->get();
        $brut = (float) $lignes->where('montant', '>', 0)->sum('montant');
        $deductions = abs((float) $lignes->where('montant', '<', 0)->sum('montant'));

        $this->montant_brut = $brut;
        $this->total_deductions = $deductions;
        $this->montant_net = max(0, $brut - $deductions);
        $this->saveQuietly();
    }

    public function recalculStatut(): void
    {
        $paye = (float) $this->historiquePaiements()->sum('montant');
        $net = (float) $this->montant_net;

        $this->montant_paye = $paye;
        $this->statut = match (true) {
            $net > 0 && $paye >= $net => StatutFichePaiement::PAYE,
            $paye > 0 => StatutFichePaiement::PARTIELLEMENT_PAYE,
            default => StatutFichePaiement::A_PAYER,
        };

        if ($this->statut === StatutFichePaiement::PAYE) {
            $dernier = $this->historiquePaiements()->first();
            $this->date_paiement = $dernier?->date_paiement;
            $this->mode_paiement = $dernier?->mode_paiement;
            $this->paid_by = $dernier?->created_by;
        }

        $this->saveQuietly();
    }

    public function isPayee(): bool
    {
        return $this->statut === StatutFichePaiement::PAYE;
    }

    public function getBeneficiaireModel(): ?Model
    {
        return match ($this->beneficiaire_type) {
            'livreur' => Livreur::find($this->beneficiaire_id),
            'proprietaire' => Proprietaire::find($this->beneficiaire_id),
            'salarie' => Employe::find($this->beneficiaire_id),
            'site' => Site::find($this->beneficiaire_id),
            'prestataire' => Prestataire::find($this->beneficiaire_id),
            default => null,
        };
    }
}
