<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompteMapping extends Model
{
    use HasUlids;

    protected $table = 'compte_mappings';

    protected $fillable = [
        'organization_id',
        'evenement',
        'role',
        'moyen_paiement',
        'compte_comptable_id',
        'journal_comptable_id',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(CompteComptable::class, 'compte_comptable_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(JournalComptable::class, 'journal_comptable_id');
    }
}
