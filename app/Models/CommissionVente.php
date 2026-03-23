<?php

namespace App\Models;

use App\Enums\StatutCommission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommissionVente extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'commissions_ventes';

    protected $fillable = [
        'organization_id',
        'commande_vente_id',
        'vehicule_id',
        'livreur_id',
        'livreur_nom',
        'taux_commission',
        'taux_commission_proprietaire',
        'montant_commande',
        'montant_commission',
        'montant_part_livreur',
        'montant_part_proprietaire',
        'montant_verse',
        'montant_verse_livreur',
        'montant_verse_proprietaire',
        'statut',
    ];

    protected $appends = ['montant_restant', 'montant_restant_livreur', 'montant_restant_proprietaire', 'statut_label'];

    protected function casts(): array
    {
        return [
            'taux_commission'              => 'decimal:2',
            'taux_commission_proprietaire' => 'decimal:2',
            'montant_commande'             => 'decimal:2',
            'montant_commission'           => 'decimal:2',
            'montant_part_livreur'         => 'decimal:2',
            'montant_part_proprietaire'    => 'decimal:2',
            'montant_verse'                => 'decimal:2',
            'montant_verse_livreur'        => 'decimal:2',
            'montant_verse_proprietaire'   => 'decimal:2',
            'statut'                       => StatutCommission::class,
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeVente::class, 'commande_vente_id');
    }

    public function vehicule(): BelongsTo
    {
        return $this->belongsTo(Vehicule::class);
    }

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(Livreur::class);
    }

    public function versements(): HasMany
    {
        return $this->hasMany(VersementCommission::class);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getMontantRestantAttribute(): float
    {
        return max(0, (float) $this->montant_commission - (float) $this->montant_verse);
    }

    public function getMontantRestantLivreurAttribute(): float
    {
        return max(0, (float) $this->montant_part_livreur - (float) $this->montant_verse_livreur);
    }

    public function getMontantRestantProprietaireAttribute(): float
    {
        return max(0, (float) $this->montant_part_proprietaire - (float) $this->montant_verse_proprietaire);
    }

    public function getStatutLabelAttribute(): string
    {
        return $this->statut instanceof StatutCommission ? $this->statut->label() : '';
    }

    // ── Méthodes métier ───────────────────────────────────────────────────────

    public function recalculStatut(): bool
    {
        if ($this->statut === StatutCommission::ANNULEE) {
            return false;
        }

        $verseL = (float) $this->versements()->where('beneficiaire', 'livreur')->sum('montant');
        $verseP = (float) $this->versements()->where('beneficiaire', 'proprietaire')->sum('montant');
        $verse  = $verseL + $verseP;
        $total  = (float) $this->montant_commission;

        $this->montant_verse                = $verse;
        $this->montant_verse_livreur        = $verseL;
        $this->montant_verse_proprietaire   = $verseP;

        if ($verse <= 0) {
            $this->statut = StatutCommission::EN_ATTENTE;
        } elseif ($verse >= $total) {
            $this->statut = StatutCommission::VERSEE;
        } else {
            $this->statut = StatutCommission::PARTIELLE;
        }

        return $this->saveQuietly();
    }

    public function isVersee(): bool
    {
        return $this->statut === StatutCommission::VERSEE;
    }

    public function isAnnulee(): bool
    {
        return $this->statut === StatutCommission::ANNULEE;
    }
}
