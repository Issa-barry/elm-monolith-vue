<?php

namespace App\Models;

use App\Services\Comptabilite\FicheComptabilisationService;
use App\Services\JournalTresorerieService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

            // Comptabilité générale : un paiement de fiche déplace de la trésorerie
            // réelle (571000/521000/561xxx) — si la pièce comptable ne peut pas être
            // créée, le paiement ne doit PAS être enregistré non plus (sinon le
            // disponible calculé par TresorerieDisponibiliteService devient faux
            // silencieusement). Volontairement BLOQUANT depuis la revue Codex du
            // 2026-08-22 — l'appelant (PaiementFichePaiementController::store()) doit
            // englober cette création dans une transaction pour que l'échec annule
            // aussi l'insertion. Ne PAS étendre ce mode bloquant à un événement qui ne
            // touche pas un compte de trésorerie (ex: fiche_*_validee, vente_facturee) :
            // le risque de bloquer une opération métier fréquente sans bénéfice pour le
            // disponible ne se justifie pas là.
            app(FicheComptabilisationService::class)->comptabiliserPaiementFiche($p);
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

    /**
     * Allocation de ce paiement sur les CommissionEnveloppePart (V2 uniquement,
     * cf. CommissionEnveloppePartAllocationService) — toujours vide pour un
     * paiement d'une fiche Legacy.
     */
    public function enveloppeItems(): HasMany
    {
        return $this->hasMany(CommissionEnveloppePaiementItem::class, 'paiement_id');
    }
}
