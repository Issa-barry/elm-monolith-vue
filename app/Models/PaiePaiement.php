<?php

namespace App\Models;

use App\Services\Comptabilite\PaieComptabilisationService;
use App\Services\JournalTresorerieService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

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

            // Comptabilité générale, en aval — ne doit jamais empêcher un paiement
            // métier de passer (mode shadow, même convention que PaiementFichePaiement).
            try {
                app(PaieComptabilisationService::class)->comptabiliserPaiement($p);
            } catch (\Throwable $e) {
                Log::error('Comptabilisation paiement salaire échouée', [
                    'paiement_id' => $p->id,
                    'error' => $e->getMessage(),
                ]);
            }
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
