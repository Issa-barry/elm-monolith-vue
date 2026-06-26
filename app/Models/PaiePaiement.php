<?php

namespace App\Models;

use App\Services\JournalTresorerieService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaiePaiement extends Model
{
    use HasUlids;

    protected $fillable = [
        'paie_ligne_id',
        'montant',
        'date_paiement',
        'mode_paiement',
        'note',
    ];

    protected $casts = [
        'montant' => 'decimal:2',
        'date_paiement' => 'date',
    ];

    protected static function booted(): void
    {
        static::created(function (self $p) {
            JournalTresorerieService::enregistrerPaieSalaire($p);
        });

        static::deleted(function (self $p) {
            JournalTresorerie::where('source_type', self::class)
                ->where('source_id', $p->id)
                ->delete();
        });
    }

    public function ligne(): BelongsTo
    {
        return $this->belongsTo(PaieLigne::class, 'paie_ligne_id');
    }
}
