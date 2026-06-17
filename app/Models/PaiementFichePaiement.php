<?php

namespace App\Models;

use App\Services\JournalTresorerieService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class PaiementFichePaiement extends Model
{
    use HasUlids;

    protected $fillable = [
        'fiche_id',
        'organization_id',
        'site_id',
        'montant',
        'mode_paiement',
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
