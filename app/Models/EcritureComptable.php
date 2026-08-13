<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne débit/crédit d'une pièce comptable. `debit` et `credit` sont
 * mutuellement exclusifs (l'un des deux vaut 0) — imposé par
 * EcritureComptableService avant toute persistance, cf. contrainte CHECK en
 * base pour les SGBD qui la supportent.
 */
class EcritureComptable extends Model
{
    use HasUlids;

    protected $table = 'ecritures_comptables';

    protected $fillable = [
        'piece_comptable_id',
        'compte_comptable_id',
        'tiers_comptable_id',
        'site_id',
        'libelle',
        'debit',
        'credit',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    public function piece(): BelongsTo
    {
        return $this->belongsTo(PieceComptable::class, 'piece_comptable_id');
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(CompteComptable::class, 'compte_comptable_id');
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(TiersComptable::class, 'tiers_comptable_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function solde(): float
    {
        return (float) $this->debit - (float) $this->credit;
    }
}
