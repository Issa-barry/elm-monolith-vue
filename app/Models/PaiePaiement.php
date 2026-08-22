<?php

namespace App\Models;

use App\Enums\EvenementComptable;
use App\Services\Comptabilite\EcritureComptableService;
use App\Services\Comptabilite\PaieComptabilisationService;
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
            // Comptabilité générale : déplace de la trésorerie réelle — bloquant
            // depuis la revue Codex du 2026-08-22 (même raison que PaiementFichePaiement).
            // L'appelant (PaiePaiementController::store()) englobe cette création dans
            // une transaction.
            app(PaieComptabilisationService::class)->comptabiliserPaiement($p);
        });

        static::deleted(function (self $p) {
            // Jamais de suppression destructive d'écriture validée (règle #29) : on
            // contrepasse la pièce de paiement salaire si elle existe, on ne la
            // supprime jamais. PaiePaiementController::destroy() englobe déjà cette
            // suppression dans une transaction.
            $orgId = $p->ligne?->periode?->organization_id;
            if ($orgId) {
                $ecritures = app(EcritureComptableService::class);
                $piece = $ecritures->pieceExistantePour($orgId, $p, EvenementComptable::PAIEMENT_SALAIRE);
                if ($piece && $piece->isValidee()) {
                    $ecritures->contrepasser($piece, 'Paiement salaire supprimé');
                }
            }
        });
    }

    public function ligne(): BelongsTo
    {
        return $this->belongsTo(PaieLigne::class, 'paie_ligne_id');
    }
}
