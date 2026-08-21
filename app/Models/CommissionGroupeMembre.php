<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un bénéficiaire final rattaché à un CommissionGroupe, avec sa part (%),
 * valable sur une période donnée. beneficiaire_type/id : polymorphe léger
 * (livreur ou employe), jamais `user` — cf. conception cible §0.2.1, un rôle
 * applicatif ne rend jamais quelqu'un bénéficiaire d'une commission.
 */
class CommissionGroupeMembre extends Model
{
    use HasUlids;

    public const TYPE_LIVREUR = 'livreur';

    public const TYPE_EMPLOYE = 'employe';

    protected $table = 'commission_groupe_membres';

    protected $fillable = [
        'groupe_id',
        'beneficiaire_type',
        'beneficiaire_id',
        'part_pourcentage',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'part_pourcentage' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function groupe(): BelongsTo
    {
        return $this->belongsTo(CommissionGroupe::class, 'groupe_id');
    }

    /** Résout le modèle bénéficiaire réel (Livreur ou Employe) — jamais User. */
    public function resoudreBeneficiaire(): ?Model
    {
        return match ($this->beneficiaire_type) {
            self::TYPE_LIVREUR => Livreur::find($this->beneficiaire_id),
            self::TYPE_EMPLOYE => Employe::find($this->beneficiaire_id),
            default => null,
        };
    }
}
