<?php

namespace App\Models;

use App\Enums\StatutSoldeOuverture;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Point de départ, unique par support de trésorerie (cf. contrainte unique en
 * base), du calcul de disponibilité — obligatoire pour qu'une position de
 * trésorerie calculée depuis compta_ecritures (existant seulement depuis le
 * 2026-08-12) soit fiable dès le premier mois d'utilisation de l'écran
 * Financement des agences. Produit une pièce comptable à la validation
 * uniquement (cf. SoldeOuvertureTresorerieService) — jamais un simple champ
 * modifiable.
 */
class SoldeOuvertureTresorerie extends Model
{
    use HasUlids;

    protected $table = 'compta_soldes_ouverture';

    protected $fillable = [
        'organization_id',
        'compte_tresorerie_id',
        'date_situation',
        'montant',
        'justificatif_path',
        'commentaire',
        'statut',
        'created_by',
        'valide_by',
        'valide_at',
        'piece_comptable_id',
    ];

    protected function casts(): array
    {
        return [
            'date_situation' => 'date',
            'montant' => 'decimal:2',
            'statut' => StatutSoldeOuverture::class,
            'valide_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function compteTresorerie(): BelongsTo
    {
        return $this->belongsTo(CompteTresorerie::class);
    }

    public function piece(): BelongsTo
    {
        return $this->belongsTo(PieceComptable::class, 'piece_comptable_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_by');
    }

    public function isValide(): bool
    {
        return $this->statut === StatutSoldeOuverture::VALIDE;
    }
}
