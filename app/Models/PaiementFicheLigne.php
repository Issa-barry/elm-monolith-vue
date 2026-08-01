<?php

namespace App\Models;

use App\Enums\TypeLignePaiement;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaiementFicheLigne extends Model
{
    use HasUlids;

    protected $fillable = [
        'fiche_id',
        'source_type',
        'source_id',
        'type_ligne',
        'libelle',
        'montant',
        'ordre',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'type_ligne' => TypeLignePaiement::class,
        ];
    }

    public function fiche(): BelongsTo
    {
        return $this->belongsTo(PaiementFiche::class, 'fiche_id');
    }

    /**
     * Origine de la ligne (CommissionPart, CommissionLogistiquePart, Depense,
     * PaieLigne, PaieVariable — cf. PeriodeCalculatorService). Sert notamment
     * à retrouver le véhicule à l'origine d'un gain/déduction (PaiementFicheController).
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function isGain(): bool
    {
        return $this->type_ligne instanceof TypeLignePaiement && $this->type_ligne->isGain();
    }

    public function isDeduction(): bool
    {
        return $this->type_ligne instanceof TypeLignePaiement && $this->type_ligne->isDeduction();
    }
}
