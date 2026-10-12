<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paiement (partiel ou total) d'une facture fournisseur validée — décaissement réel depuis un support
 * de trésorerie (ADR 0024). Toujours accompagné de sa pièce comptable. `site_id` = agence d'où
 * l'argent est sorti : celle de la facture par défaut, une autre agence du périmètre du payeur
 * depuis le 12/10/2026.
 */
class PaiementFournisseur extends Model
{
    use HasUlids;

    protected $table = 'paiements_fournisseurs';

    protected $fillable = [
        'organization_id', 'facture_fournisseur_id', 'fournisseur_id', 'site_id', 'montant', 'mode_paiement',
        'moyen_paiement_detail', 'compte_tresorerie_id', 'reference_paiement', 'date_paiement', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'date_paiement' => 'date',
        ];
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(FactureFournisseur::class, 'facture_fournisseur_id');
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class);
    }

    /** Agence d'où l'argent est sorti. */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function compteTresorerie(): BelongsTo
    {
        return $this->belongsTo(CompteTresorerie::class, 'compte_tresorerie_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
