<?php

namespace App\Models;

use App\Services\Comptabilite\FicheComptabilisationService;
use App\Services\JournalTresorerieService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PaiementFichePaiement extends Model
{
    use HasUlids;

    protected $fillable = [
        'fiche_id',
        'organization_id',
        'site_id',
        'montant',
        'mode_paiement',
        'moyen_paiement_detail',
        'date_paiement',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'date_paiement' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $p) {
            if (Auth::check()) {
                $p->created_by ??= Auth::id();
            }
        });

        static::created(function (self $p) {
            $p->fiche?->recalculStatut();
            JournalTresorerieService::enregistrerPaiementFiche($p);

            // Comptabilité générale, en aval — ne doit jamais empêcher un paiement
            // métier de passer (mode shadow, règle #26 de la spec).
            try {
                app(FicheComptabilisationService::class)->comptabiliserPaiementFiche($p);
            } catch (\Throwable $e) {
                Log::error('Comptabilisation paiement fiche échouée', [
                    'paiement_id' => $p->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        static::deleted(function (self $p) {
            $p->fiche?->recalculStatut();
        });
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function fiche(): BelongsTo
    {
        return $this->belongsTo(PaiementFiche::class, 'fiche_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
