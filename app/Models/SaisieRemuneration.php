<?php

namespace App\Models;

use App\Enums\StatutSaisieRemuneration;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Montant (ou taux) d'un mois pour une affectation « saisie chaque mois »
 * (docs/primes-avantages.md). Écritures : App\Services\Rh\AffectationRemunerationService.
 */
class SaisieRemuneration extends Model
{
    use HasUlids;

    protected $table = 'affectation_remuneration_saisies';

    protected $fillable = [
        'organization_id',
        'affectation_id',
        'annee',
        'mois',
        'montant',
        'taux',
        'commentaire',
        'statut',
        'saisi_par',
        'valide_par',
        'valide_le',
    ];

    protected function casts(): array
    {
        return [
            'statut' => StatutSaisieRemuneration::class,
            'annee' => 'integer',
            'mois' => 'integer',
            'montant' => 'decimal:2',
            'taux' => 'decimal:2',
            'valide_le' => 'datetime',
        ];
    }

    public function affectation(): BelongsTo
    {
        return $this->belongsTo(AffectationRemuneration::class, 'affectation_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function valideur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }

    public function periodeLabel(): string
    {
        return sprintf('%02d/%d', $this->mois, $this->annee);
    }
}
