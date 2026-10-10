<?php

namespace App\Models;

use App\Enums\StatutFactureFournisseur;
use App\Enums\TypeJustificatifAchat;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * Facture fournisseur (ADR 0022) : facture des lignes de réception d'un bon de commande validé. La
 * dette fournisseur naît à la validation et vit ici : montant TTC, montant payé (lot 4), reste dû.
 */
class FactureFournisseur extends Model
{
    use HasUlids;

    protected $table = 'factures_fournisseurs';

    protected $fillable = [
        'organization_id', 'commande_achat_id', 'fournisseur_id', 'site_id', 'reference', 'numero',
        'numero_facture_fournisseur', 'type_justificatif', 'cle_numero_unique', 'date_facture', 'date_echeance', 'taux_tva', 'montant_ht',
        'montant_tva', 'montant_ttc', 'montant_paye', 'statut', 'note', 'contenu_modifie_par',
        'contenu_modifie_at', 'validee_at', 'validee_par', 'fournisseur_nom_snapshot', 'annulee_at',
        'annulee_par', 'motif_annulation', 'comptabilisation_erreur', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutFactureFournisseur::class,
            'type_justificatif' => TypeJustificatifAchat::class,
            'date_facture' => 'date',
            'date_echeance' => 'date',
            'taux_tva' => 'decimal:2',
            'montant_ht' => 'decimal:2',
            'montant_tva' => 'decimal:2',
            'montant_ttc' => 'decimal:2',
            'montant_paye' => 'decimal:2',
            'contenu_modifie_at' => 'datetime',
            'validee_at' => 'datetime',
            'annulee_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (FactureFournisseur $f) {
            if (Auth::check()) {
                $f->created_by ??= Auth::id();
                $f->updated_by = Auth::id();
            }
        });
        static::updating(function (FactureFournisseur $f) {
            if (Auth::check()) {
                $f->updated_by = Auth::id();
            }
        });
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeAchat::class, 'commande_achat_id');
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(FactureFournisseurLigne::class);
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(PaiementFournisseur::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function valideePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validee_par');
    }

    public function annuleePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annulee_par');
    }

    public function isBrouillon(): bool
    {
        return $this->statut === StatutFactureFournisseur::BROUILLON;
    }

    public function isConstatee(): bool
    {
        return in_array($this->statut, StatutFactureFournisseur::constatees(), true);
    }

    /** Dette restant due au fournisseur (0 tant que la facture n'est pas validée). */
    public function resteDu(): float
    {
        return $this->isConstatee() ? max(0.0, (float) $this->montant_ttc - (float) $this->montant_paye) : 0.0;
    }

    /** Aucun document remis par le fournisseur : achat enregistré sans justificatif. */
    public function estSansJustificatif(): bool
    {
        return $this->type_justificatif === TypeJustificatifAchat::AUCUN;
    }

    /** Désignation du document pour les libellés : « Facture F-12 », « Reçu sans numéro », « Achat sans justificatif ». */
    public function designationDocument(): string
    {
        if ($this->estSansJustificatif()) {
            return 'Achat sans justificatif';
        }
        $type = ($this->type_justificatif ?? TypeJustificatifAchat::FACTURE)->label();

        return filled($this->numero_facture_fournisseur) ? "{$type} {$this->numero_facture_fournisseur}" : "{$type} sans numéro";
    }

    public function fournisseurNom(): ?string
    {
        return $this->fournisseur_nom_snapshot ?? $this->fournisseur?->nom_complet;
    }
}
