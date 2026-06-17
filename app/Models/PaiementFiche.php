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
        'site_id',
        'montant_brut',
        'total_deductions',
        'montant_net',
        'montant_paye',
        'statut',
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
        ];
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

    public function recalculTotaux(): void
    {
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
            default => null,
        };
    }
}
