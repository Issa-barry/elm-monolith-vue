<?php

namespace App\Models;

use App\Enums\StatutPieceComptable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PieceComptable extends Model
{
    use HasUlids;

    protected $table = 'compta_pieces';

    protected $fillable = [
        'organization_id',
        'journal_comptable_id',
        'exercice_comptable_id',
        'periode_comptable_id',
        'numero',
        'date_piece',
        'libelle',
        'source_type',
        'source_id',
        'type_evenement',
        'statut',
        'piece_origine_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date_piece' => 'date',
            'statut' => StatutPieceComptable::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(JournalComptable::class, 'journal_comptable_id');
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(ExerciceComptable::class, 'exercice_comptable_id');
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(PeriodeComptable::class, 'periode_comptable_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function pieceOrigine(): BelongsTo
    {
        return $this->belongsTo(self::class, 'piece_origine_id');
    }

    public function contrepassations(): HasMany
    {
        return $this->hasMany(self::class, 'piece_origine_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(EcritureComptable::class);
    }

    public function isValidee(): bool
    {
        return $this->statut === StatutPieceComptable::VALIDEE;
    }

    public function totalDebit(): float
    {
        return (float) $this->lignes()->sum('debit');
    }

    public function totalCredit(): float
    {
        return (float) $this->lignes()->sum('credit');
    }
}
