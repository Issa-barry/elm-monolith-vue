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

    /** Les lignes d'une fiche figée (payée ou portant un report) sont intouchables (ADR 0010). */
    protected static function booted(): void
    {
        $garde = function (self $ligne): void {
            $fiche = PaiementFiche::withTrashed()->find($ligne->fiche_id);
            if ($fiche && $fiche->estFigee()) {
                throw new \LogicException("La fiche {$fiche->reference} a déjà reçu un paiement : ses lignes ne peuvent plus être modifiées.");
            }
        };

        static::creating($garde);
        static::updating($garde);
        static::deleting($garde);
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
